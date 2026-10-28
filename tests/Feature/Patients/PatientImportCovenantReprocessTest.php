<?php

declare(strict_types=1);

/**
 * Reprocessar a importação de pacientes só nas linhas que falharam por convênio (plano
 * patients-import-covenant-mapping-plano.md): import filho com `reprocess_lines`, sem duplicar paciente, respeitando o
 * limite do plano, idempotente e sem PHI nos logs.
 */

require_once __DIR__ . '/../../Support/PatientImportCovenantHelpers.php';

use App\Enums\{FeatureKey, ImportStatus};
use App\Models\{AuditLog, Covenant, Patient, PatientImport, PlanFeature, User};
use App\Services\Covenants\PatientImportReprocessService;
use App\Services\{FeatureGateService, PatientImportService};
use Illuminate\Support\Facades\{Log, Storage};
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

beforeEach(function () {
    Storage::fake();
    $this->clinic = picmClinic();
    $this->user   = User::factory()->create();
    $this->amil   = Covenant::factory()->create(['name' => 'AMIL']);
    $this->pas    = Covenant::factory()->create(['name' => 'PAS']);
});

/** @return list<string> */
function picrNames(string $entityId): array
{
    return Patient::withoutGlobalScopes()->where('entity_id', $entityId)->get()->map(fn (Patient $p) => $p->person->full_name)->sort()->values()->all();
}

function picrReprocess(PatientImport $parent, ?User $user = null): PatientImport
{
    return app(PatientImportReprocessService::class)->reprocess($parent, $user ?? test()->user)->fresh();
}

it('processa só a linha com erro de convênio: a boa não duplica e a ruim entra com o convênio mapeado; o pai não muda', function () {
    $parent = picmProcess($this->clinic, "nome;celular;convenio\nAna Boa;11911110001;AMIL\nBia Ruim;11911110002;PAS 606\n");

    expect($parent->imported_rows)->toBe(1)->and($parent->covenant_error_lines)->toBe([3]);

    picmMapping($this->clinic, 'PAS 606', $this->pas);
    $child = picrReprocess($parent);

    expect($child->status)->toBe(ImportStatus::Done)
        ->and($child->reprocess_of_id)->toBe($parent->id)
        ->and($child->reprocess_lines)->toBe([3])
        ->and($child->total_rows)->toBe(1)
        ->and($child->only(['processed_rows', 'imported_rows', 'skipped_rows', 'error_rows']))->toBe(['processed_rows' => 1, 'imported_rows' => 1, 'skipped_rows' => 0, 'error_rows' => 0])
        ->and($child->covenant_error_lines)->toBe([])
        ->and(picrNames($this->clinic->id))->toBe(['ANA BOA', 'BIA RUIM'])
        ->and(Patient::withoutGlobalScopes()->whereHas('person', fn ($q) => $q->where('full_name', 'BIA RUIM'))->sole()->covenant_id)->toBe($this->pas->id)
        ->and($parent->fresh()->only(['status', 'imported_rows', 'error_rows', 'covenant_error_lines']))->toBe(['status' => ImportStatus::Done, 'imported_rows' => 1, 'error_rows' => 1, 'covenant_error_lines' => [3]]);
});

it('o número da linha de erro do filho aponta para a linha do arquivo ORIGINAL', function () {
    $parent = picmProcess($this->clinic, "nome;celular;convenio;codigo_importacao\nAna;11911110001;AMIL;A1\nBia;11911110002;PAS 606;B1\nCaio;11911110003;AMIL;C1\nDani;11911110004;PAS 606;A1\n");

    expect($parent->covenant_error_lines)->toBe([3, 5]);

    picmMapping($this->clinic, 'PAS 606', $this->pas);
    $child = picrReprocess($parent);
    $csv   = ltrim(Storage::disk()->get($child->errors_file_path), "\xEF\xBB\xBF");

    // Linha 5 (Dani) repete o código A1 da Ana: erro de código, na linha 5 do arquivo original — não na linha 2 do filho.
    expect($child->imported_rows)->toBe(1)
        ->and($child->error_rows)->toBe(1)
        ->and($child->covenant_error_lines)->toBe([])
        ->and(str_getcsv(explode("\n", $csv)[1], ';')[0])->toBe('5');
});

it('duplicidade por CPF: duas linhas com o mesmo CPF falham por convênio; ao reprocessar uma cria e a outra é ignorada', function () {
    $parent = picmProcess($this->clinic, "nome;celular;cpf;convenio\nCarlos Um;11911110001;52998224725;PAS 606\nCarlos Dois;11911110002;52998224725;PAS 606\n");

    expect($parent->imported_rows)->toBe(0)->and($parent->covenant_error_lines)->toBe([2, 3]);

    picmMapping($this->clinic, 'PAS 606', $this->pas);
    $child = picrReprocess($parent);

    expect($child->only(['imported_rows', 'skipped_rows', 'error_rows']))->toBe(['imported_rows' => 1, 'skipped_rows' => 1, 'error_rows' => 0])
        ->and(Patient::withoutGlobalScopes()->where('entity_id', $this->clinic->id)->count())->toBe(1);
});

it('duplicidade por import_code: a linha que repete o código de quem já importou continua falhando e o código fica com o paciente certo', function () {
    $parent = picmProcess($this->clinic, "nome;celular;convenio;codigo_importacao\nAna Lima;11911110001;PAS 606;X1\nBia Lima;11911110002;AMIL;X1\n");

    expect($parent->imported_rows)->toBe(1); // Bia ficou com o código X1

    picmMapping($this->clinic, 'PAS 606', $this->pas);
    $child = picrReprocess($parent);
    $csv   = ltrim(Storage::disk()->get($child->errors_file_path), "\xEF\xBB\xBF");

    expect($child->only(['imported_rows', 'error_rows']))->toBe(['imported_rows' => 0, 'error_rows' => 1])
        ->and($csv)->toContain("Código de importação 'X1' já utilizado")
        ->and(picrNames($this->clinic->id))->toBe(['BIA LIMA'])
        ->and(Patient::withoutGlobalScopes()->where('import_code', 'X1')->sole()->person->full_name)->toBe('BIA LIMA');
});

it('é idempotente: processar o filho de novo não cria paciente nenhum', function () {
    $parent = picmProcess($this->clinic, "nome;celular;convenio\nAna;11911110001;PAS 606\nBia;11911110002;PAS 606\n");
    picmMapping($this->clinic, 'PAS 606', $this->pas);
    $child = picrReprocess($parent);

    expect($child->imported_rows)->toBe(2);

    app(PatientImportService::class)->process($child);
    $again = $child->fresh();

    expect(Patient::withoutGlobalScopes()->where('entity_id', $this->clinic->id)->count())->toBe(2)
        ->and($again->only(['imported_rows', 'skipped_rows', 'error_rows']))->toBe(['imported_rows' => 0, 'skipped_rows' => 2, 'error_rows' => 0]);
});

it('respeita o limite do plano: com o limite já atingido o reprocessamento aborta com o motivo e não grava paciente', function () {
    $parent = picmProcess($this->clinic, "nome;celular;convenio\nAna;11911110001;AMIL\nBia;11911110002;PAS 606\nCaio;11911110003;PAS 606\n");

    expect($parent->imported_rows)->toBe(1);

    picmMapping($this->clinic, 'PAS 606', $this->pas);
    PlanFeature::query()->where('feature', FeatureKey::MaxPatients->value)->update(['value' => '1']); // já há 1 paciente
    app(FeatureGateService::class)->forgetCache($this->clinic->id);
    $child = picrReprocess($parent);

    expect($child->status)->toBe(ImportStatus::Done)
        ->and($child->imported_rows)->toBe(0)
        ->and($child->abort_reason)->toContain('Limite do plano atingido')
        ->and(picrNames($this->clinic->id))->toBe(['ANA']);
});

it('import ANTIGO (sem covenant_error_lines): as linhas vêm do CSV de erros e o reprocessamento funciona', function () {
    $parent = picmProcess($this->clinic, "nome;celular;convenio\nAna;11911110001;AMIL\nBia;11911110002;PAS 606\n");
    $parent->update(['covenant_error_lines' => null, 'covenant_mapping_snapshot' => null, 'preview' => ['total_rows' => 2]]); // como era antes do recurso

    picmMapping($this->clinic, 'PAS 606', $this->pas);
    $child = picrReprocess($parent->fresh());

    expect($child->reprocess_lines)->toBe([3])
        ->and($child->imported_rows)->toBe(1)
        ->and(picrNames($this->clinic->id))->toBe(['ANA', 'BIA']);
});

it('LGPD: nenhum log da importação nem do reprocessamento carrega nome, CPF, telefone ou carteirinha', function () {
    $logged = [];
    Log::listen(function ($event) use (&$logged): void {
        $logged[] = $event->message . ' ' . json_encode($event->context);
    });

    $parent = picmProcess($this->clinic, "nome;celular;cpf;convenio;carteirinha;codigo_importacao\n"
        . "Paciente Sigiloso Um;11999887766;52998224725;PAS 606;CART-777;K1\n"
        . "Paciente Sigiloso Dois;11999887755;11144477735;AMIL;CART-888;K1\n");
    picmMapping($this->clinic, 'PAS 606', $this->pas);
    picrReprocess($parent);

    $all = implode("\n", $logged);

    foreach (['Sigiloso', '52998224725', '11144477735', '11999887766', '11999887755', 'CART-777', 'CART-888'] as $phi) {
        expect($all)->not->toContain($phi);
    }
});

it('reprocessar grava em audit_logs quem pediu, o pai, o filho e o número de linhas — na mesma transação do filho', function () {
    $parent = picmProcess($this->clinic, "nome;celular;convenio\nAna;11911110001;PAS 606\nBia;11911110002;PAS 606\n");
    picmMapping($this->clinic, 'PAS 606', $this->pas);
    Queue::fake();

    $child = picrReprocess($parent);
    $log   = AuditLog::query()->where('event', 'reprocessed')->sole();

    expect($log->auditable_type)->toBe(PatientImport::class)
        ->and($log->auditable_id)->toBe($child->id)
        ->and($log->entity_id)->toBe($this->clinic->id)
        ->and($log->new_values)->toBe(['reprocess_of_id' => $parent->id, 'user_id' => $this->user->id, 'lines' => 2]);
});

it('reprocessamento recusado (409/422) não deixa trilha de auditoria', function () {
    $parent = picmProcess($this->clinic, "nome;celular;convenio\nAna;11911110001;PAS 606\n");
    Queue::fake();
    picrReprocess($parent);

    expect(fn () => picrReprocess($parent))->toThrow(ConflictHttpException::class); // já há filho ativo

    expect(AuditLog::query()->where('event', 'reprocessed')->count())->toBe(1);
});
