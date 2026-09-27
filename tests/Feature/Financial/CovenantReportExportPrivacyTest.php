<?php

declare(strict_types=1);

use App\Enums\BillingClaimStatus;
use App\Models\{BillingClaim, Covenant, Entity, Patient, People};
use Illuminate\Support\Facades\DB;

/*
 * LGPD (decisão do usuário): a exportação do relatório de convênios identifica
 * o paciente só por CÓDIGO + INICIAIS ("PAC-0000000123 · J. S."), nunca pelo
 * nome completo — em CSV e em Excel —, com o cabeçalho deixando isso claro e
 * o evento de auditoria por exportação mantido (sem dado do paciente).
 */

beforeEach(function () {
    $this->entity   = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->covenant = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true, 'name' => 'UNIMED']);
    actingAsFinancialEntityUser($this->entity);

    $person        = People::factory()->create(['full_name' => 'MARIA SIGILOSA DA COSTA']);
    $this->patient = Patient::factory()->create([
        'entity_id'   => $this->entity->id,
        'person_id'   => $person->id,
        'covenant_id' => $this->covenant->id,
    ]);

    foreach ([[BillingClaimStatus::Paid, $this->patient->id], [BillingClaimStatus::Submitted, null]] as [$status, $patientId]) {
        BillingClaim::query()->create([
            'entity_id'       => $this->entity->id,
            'covenant_id'     => $this->covenant->id,
            'patient_id'      => $patientId,
            'status'          => $status->value,
            'attendance_date' => now()->toDateString(),
            'amount'          => 100,
            'paid_amount'     => $status === BillingClaimStatus::Paid ? 100 : 0,
            'quantity'        => 1,
            'unit_price'      => 100,
        ]);
    }
});

it('CSV do relatório de convênios traz código + iniciais do paciente, nunca o nome', function () {
    $body = $this->get(route('panel.financial.reports.covenants.export'))->assertOk()->getContent();
    $rows = array_map(
        fn (string $line) => str_getcsv($line, ';', '"', ''),
        preg_split('/\r?\n/', trim(substr($body, 3))),
    );

    $patients = array_column(array_slice($rows, 1), 3);

    expect($rows[0][3])->toBe('Paciente (código · iniciais)')
        ->and($patients)->toEqualCanonicalizing(["{$this->patient->code} · M. C.", 'Sem paciente'])
        ->and($body)->not->toContain('MARIA')
        ->and($body)->not->toContain('SIGILOSA')
        ->and($body)->not->toContain('COSTA');
});

it('Excel do relatório de convênios também só leva código + iniciais', function () {
    $response = $this->get(route('panel.financial.reports.covenants.export', ['format' => 'xlsx']))->assertOk();

    $tmp = tempnam(sys_get_temp_dir(), 'covenant_privacy_xlsx_');
    file_put_contents($tmp, $response->getContent());

    $zip = new ZipArchive();
    expect($zip->open($tmp))->toBeTrue();
    $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    @unlink($tmp);

    expect($sheet)->toContain("<t>{$this->patient->code} · M. C.</t>")
        ->and($sheet)->not->toContain('SIGILOSA')
        ->and($sheet)->not->toContain('MARIA');
});

it('mantém um evento de auditoria por exportação, sem código nem nome do paciente', function () {
    $this->get(route('panel.financial.reports.covenants.export'))->assertOk();

    $logs = DB::table('audit_logs')->where('event', 'financial.report.export')->get();

    expect($logs)->toHaveCount(1);

    $values = json_decode((string) $logs[0]->new_values, true);

    expect($values)->toMatchArray(['report' => 'covenants', 'format' => 'csv', 'rows' => 2])
        ->and((string) $logs[0]->new_values)->not->toContain('SIGILOSA')
        ->and((string) $logs[0]->new_values)->not->toContain((string) $this->patient->code);
});
