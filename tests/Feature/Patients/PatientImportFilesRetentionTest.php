<?php

declare(strict_types=1);

/**
 * Retenção LGPD dos arquivos da importação de pacientes (plano patients-import-covenant-mapping-plano.md §4.8): o CSV
 * original e o CSV de erros têm dado de paciente, então expiram (30 dias, configurável), são apagados por um comando
 * agendado e o histórico do import permanece sem os arquivos.
 */

require_once __DIR__ . '/../../Support/PatientImportCovenantHelpers.php';

use App\Enums\ImportStatus;
use App\Models\{Entity, PatientImport, User};
use Illuminate\Support\{Carbon, Str};
use Illuminate\Support\Facades\{DB, Log, Storage};

beforeEach(function () {
    Storage::fake();
    $this->clinic = picmPanelClinic();
});

/** Import com os dois arquivos gravados no disco (original e erros). */
function pifrImport(Entity $clinic, ImportStatus $status, array $attributes = []): PatientImport
{
    $import = picmReportImport($clinic, picmReportCsv(), ['status' => $status, ...$attributes]);
    picmErrorsFile($import, [[2, picmNotFound('PAS 606'), 'PAS 606']]);

    return $import->fresh();
}

it('o comando apaga original e erros vencidos, mantém o registro e marca files_purged_at; não toca em pendente, em andamento nem no prazo', function () {
    $past = now()->subMinute();

    $done      = pifrImport($this->clinic, ImportStatus::Done, ['files_expire_at' => $past]);
    $failed    = pifrImport($this->clinic, ImportStatus::Failed, ['files_expire_at' => $past]);
    $cancelled = pifrImport($this->clinic, ImportStatus::Cancelled, ['files_expire_at' => $past]);
    $fresh     = pifrImport($this->clinic, ImportStatus::Done, ['files_expire_at' => now()->addDay()]);
    $running   = pifrImport($this->clinic, ImportStatus::Processing, ['files_expire_at' => $past]);
    $queued    = pifrImport($this->clinic, ImportStatus::Pending, ['files_expire_at' => $past, 'confirmed_at' => now()->subHour()]);
    $preview   = pifrImport($this->clinic, ImportStatus::Pending, ['files_expire_at' => $past]); // nunca confirmado

    $this->artisan('imports:prune-patient-files')->assertSuccessful();

    foreach ([$done, $failed, $cancelled] as $import) {
        $kept = $import->fresh();

        expect($kept)->not->toBeNull()
            ->and($kept->files_purged_at)->not->toBeNull()
            ->and($kept->errors_file_path)->toBeNull()
            ->and($kept->status)->toBe($import->status)
            ->and(Storage::disk()->exists($import->file_path))->toBeFalse()
            ->and(Storage::disk()->exists($import->errors_file_path))->toBeFalse();
    }

    foreach ([$fresh, $running, $queued] as $import) {
        expect($import->fresh()->files_purged_at)->toBeNull()
            ->and(Storage::disk()->exists($import->file_path))->toBeTrue()
            ->and(Storage::disk()->exists($import->errors_file_path))->toBeTrue();
    }

    // Prévia abandonada (sem job): some o registro e o arquivo, como o cancelamento.
    expect(PatientImport::withoutGlobalScopes()->whereKey($preview->id)->exists())->toBeFalse()
        ->and(Storage::disk()->exists($preview->file_path))->toBeFalse();
});

it('o comando é idempotente e só loga contagem agregada (sem nome de arquivo, convênio nem paciente)', function () {
    $import = pifrImport($this->clinic, ImportStatus::Done, ['files_expire_at' => now()->subMinute(), 'original_name' => 'pacientes_sigilosos.csv']);
    $logged = [];
    Log::listen(function ($event) use (&$logged): void {
        $logged[] = $event->message . ' ' . json_encode($event->context);
    });

    $this->artisan('imports:prune-patient-files')->assertSuccessful();
    $purgedAt = $import->fresh()->files_purged_at;

    $this->artisan('imports:prune-patient-files')->assertSuccessful()->expectsOutputToContain('0');

    expect($import->fresh()->files_purged_at->equalTo($purgedAt))->toBeTrue()
        ->and(implode("\n", $logged))->not->toContain('sigilosos')->not->toContain('PAS 606')->not->toContain('SIGILOSO');
});

it('--days apaga também o que passou da janela informada, mesmo com prazo renovado; sem a opção o prazo manda', function () {
    $old = pifrImport($this->clinic, ImportStatus::Done, ['files_expire_at' => now()->addDays(20), 'finished_at' => now()->subDays(40)]);

    $this->artisan('imports:prune-patient-files')->assertSuccessful();
    expect($old->fresh()->files_purged_at)->toBeNull();

    $this->artisan('imports:prune-patient-files', ['--days' => 30])->assertSuccessful();
    expect($old->fresh()->files_purged_at)->not->toBeNull()
        ->and(Storage::disk()->exists($old->file_path))->toBeFalse();
});

it('a migration deixa files_expire_at futuro (30 dias a partir do deploy) nos imports que já existiam', function () {
    $migration = require base_path('database/migrations/2026_10_23_121000_add_covenant_mapping_fields_to_patient_imports.php');
    $clinic    = picmEntity();

    $migration->down(); // volta ao esquema anterior ao recurso (o RefreshDatabase desfaz ao fim do teste)
    DB::table('patient_imports')->insert([
        'id'     => (string) Str::uuid(), 'entity_id' => $clinic->id, 'user_id' => User::factory()->create()->id,
        'status' => 'done', 'file_path' => 'imports/patients/x/antigo.csv', 'original_name' => 'antigo.csv', 'created_at' => now()->subYear(), 'updated_at' => now()->subYear(),
    ]);
    $migration->up();

    $row = DB::table('patient_imports')->where('original_name', 'antigo.csv')->first();

    expect(Carbon::parse($row->files_expire_at)->between(now()->addDays(29), now()->addDays(31)))->toBeTrue() // não vem de created_at (há 1 ano)
        ->and($row->files_purged_at)->toBeNull();
});

it('errors(): baixa antes da expiração, privado/no-store; depois da expiração ou da limpeza volta com aviso, sem 500', function () {
    $import = pifrImport($this->clinic, ImportStatus::Done, ['files_expire_at' => now()->addDay()]);
    $url    = route('panel.patients.import.errors', $import);
    $back   = route('panel.patients.import.index');

    $response = picmAs($this->clinic)->get($url)->assertOk()->assertDownload("erros_importacao_{$import->id}.csv");

    expect($response->headers->get('Content-Type'))->toContain('text/csv')->toContain('charset=UTF-8')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');

    $import->update(['files_expire_at' => now()->subMinute()]);
    picmAs($this->clinic)->from($back)->get($url)->assertRedirect($back)->assertSessionHas('error', __('imports.errors_file_expired'));

    $import->update(['files_expire_at' => now()->addDay(), 'files_purged_at' => now(), 'errors_file_path' => null]);
    picmAs($this->clinic)->from($back)->get($url)->assertRedirect($back)->assertSessionHas('error', __('imports.errors_file_expired'));
});

it('errors(): outra clínica é 404; import sem arquivo de erros é 404; arquivo sumido do disco mantém o aviso antigo', function () {
    $import = pifrImport($this->clinic, ImportStatus::Done, ['files_expire_at' => now()->addDay()]);
    $url    = route('panel.patients.import.errors', $import);
    $back   = route('panel.patients.import.index');

    picmAs(picmPanelClinic())->get($url)->assertNotFound();

    $without = picmReportImport($this->clinic, picmReportCsv(), ['files_expire_at' => now()->addDay()]);
    picmAs($this->clinic)->get(route('panel.patients.import.errors', $without))->assertNotFound();

    Storage::disk()->delete($import->errors_file_path);
    picmAs($this->clinic)->from($back)->get($url)->assertRedirect($back)->assertSessionHas('error', __('imports.errors_file_missing'));
});

it('o comando só apaga caminhos de imports/patients/{entity_id}/ do próprio import; fora do prefixo pula, conta e não toca em nada do registro', function () {
    $past  = now()->subMinute();
    $other = picmPanelClinic();
    Storage::disk()->put('private/arquivo-sensivel-de-outro-modulo.txt', 'não apagar');
    Storage::disk()->put("imports/patients/{$other->id}/da-outra-clinica.csv", 'dado de outra clínica');
    Storage::disk()->put("imports/patients/{$this->clinic->id}-irmao/arquivo.csv", 'prefixo parecido, pasta diferente');

    $outside   = pifrImport($this->clinic, ImportStatus::Done, ['files_expire_at' => $past]);
    $cross     = pifrImport($this->clinic, ImportStatus::Done, ['files_expire_at' => $past]);
    $sibling   = pifrImport($this->clinic, ImportStatus::Done, ['files_expire_at' => $past]);
    $traversal = pifrImport($this->clinic, ImportStatus::Done, ['files_expire_at' => $past]);
    $preview   = pifrImport($this->clinic, ImportStatus::Pending, ['files_expire_at' => $past]);
    $ok        = pifrImport($this->clinic, ImportStatus::Done, ['files_expire_at' => $past]);

    $outside->update(['file_path' => 'private/arquivo-sensivel-de-outro-modulo.txt']);
    $cross->update(['file_path' => "imports/patients/{$other->id}/da-outra-clinica.csv"]);
    $sibling->update(['errors_file_path' => "imports/patients/{$this->clinic->id}-irmao/arquivo.csv"]);
    $traversal->update(['errors_file_path' => "imports/patients/{$this->clinic->id}/../../private/arquivo-sensivel-de-outro-modulo.txt"]);
    $preview->update(['file_path' => 'private/arquivo-sensivel-de-outro-modulo.txt']); // prévia abandonada com caminho suspeito

    $this->artisan('imports:prune-patient-files')->assertSuccessful()->expectsOutputToContain('1 com arquivos apagados, 0 prévias abandonadas descartadas, 5 ignorados');

    foreach ([$outside, $cross, $sibling, $traversal] as $import) {
        $kept = $import->fresh();

        expect($kept->files_purged_at)->toBeNull()
            ->and($kept->file_path)->toBe($import->file_path)
            ->and($kept->errors_file_path)->toBe($import->errors_file_path);
    }

    expect(PatientImport::withoutGlobalScopes()->whereKey($preview->id)->exists())->toBeTrue()
        ->and(Storage::disk()->exists('private/arquivo-sensivel-de-outro-modulo.txt'))->toBeTrue()
        ->and(Storage::disk()->exists("imports/patients/{$other->id}/da-outra-clinica.csv"))->toBeTrue()
        ->and(Storage::disk()->exists("imports/patients/{$this->clinic->id}-irmao/arquivo.csv"))->toBeTrue()
        ->and(Storage::disk()->exists($outside->errors_file_path))->toBeTrue() // o outro arquivo do mesmo registro também fica
        ->and($ok->fresh()->files_purged_at)->not->toBeNull()
        ->and(Storage::disk()->exists($ok->file_path))->toBeFalse();
});
