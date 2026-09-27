<?php

declare(strict_types=1);

use App\Enums\BillingClaimStatus;
use App\Models\{BillingClaim, Covenant, Entity, Patient, People};
use App\Services\Financial\CovenantReportService;

/*
 * Detalhe do relatório de convênios (GET panel.financial.reports.covenants.claims):
 * guias do convênio no período em JSON paginado, mesmas regras do agregado,
 * paciente só por código + iniciais (LGPD), covenant_id validado (UUID +
 * convênio da clínica ou global) e isolamento por clínica.
 */

const COVENANT_CLAIMS_FROM = '2026-08-01';
const COVENANT_CLAIMS_TO   = '2026-08-31';

beforeEach(function () {
    $this->entity   = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->covenant = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true, 'name' => 'UNIMED']);
    actingAsFinancialEntityUser($this->entity);
});

function covenantClaimsClaim(Entity $entity, Covenant $covenant, BillingClaimStatus $status, float $amount, array $attrs = []): BillingClaim
{
    return BillingClaim::query()->create(array_merge([
        'entity_id'       => $entity->id,
        'covenant_id'     => $covenant->id,
        'status'          => $status->value,
        'attendance_date' => '2026-08-10',
        'amount'          => $amount,
        'paid_amount'     => 0,
        'glosa_amount'    => 0,
        'quantity'        => 1,
        'unit_price'      => $amount,
    ], $attrs));
}

function covenantClaimsPatient(Entity $entity, Covenant $covenant, string $name): Patient
{
    $person = People::factory()->create(['full_name' => $name]);

    return Patient::factory()->create(['entity_id' => $entity->id, 'person_id' => $person->id, 'covenant_id' => $covenant->id]);
}

function covenantClaimsUrl(array $query): string
{
    return route('panel.financial.reports.covenants.claims', array_merge([
        'from' => COVENANT_CLAIMS_FROM,
        'to'   => COVENANT_CLAIMS_TO,
    ], $query));
}

it('lista as guias do convênio no período, paginadas, da mais recente para a mais antiga', function () {
    foreach (range(1, 12) as $day) {
        covenantClaimsClaim($this->entity, $this->covenant, BillingClaimStatus::Submitted, 10 * $day, [
            'attendance_date' => sprintf('2026-08-%02d', $day),
        ]);
    }

    // Fora do detalhe: rascunho, cancelada, outro convênio e fora do período.
    covenantClaimsClaim($this->entity, $this->covenant, BillingClaimStatus::Draft, 1);
    covenantClaimsClaim($this->entity, $this->covenant, BillingClaimStatus::Cancelled, 1);
    covenantClaimsClaim($this->entity, Covenant::factory()->create(['entity_id' => $this->entity->id]), BillingClaimStatus::Submitted, 1);
    covenantClaimsClaim($this->entity, $this->covenant, BillingClaimStatus::Submitted, 1, ['attendance_date' => '2026-09-01']);

    $first = $this->getJson(covenantClaimsUrl(['covenant_id' => $this->covenant->id]))
        ->assertOk()
        ->assertJsonPath('meta.total', 12)
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonPath('meta.per_page', CovenantReportService::CLAIMS_PER_PAGE)
        ->assertJsonPath('filters', ['from' => COVENANT_CLAIMS_FROM, 'to' => COVENANT_CLAIMS_TO])
        ->assertJsonCount(10, 'data')
        ->assertJsonStructure(['data' => [['id', 'code', 'attendance_date', 'status', 'patient', 'amount', 'received', 'glosa']]])
        ->json();

    expect(array_column($first['data'], 'attendance_date'))->toBe([
        '2026-08-12', '2026-08-11', '2026-08-10', '2026-08-09', '2026-08-08',
        '2026-08-07', '2026-08-06', '2026-08-05', '2026-08-04', '2026-08-03',
    ])
        ->and($first['data'][0]['amount'])->toEqual(120.0)
        ->and($first['data'][0]['status'])->toBe('submitted');

    $this->getJson(covenantClaimsUrl(['covenant_id' => $this->covenant->id, 'page' => 2]))
        ->assertOk()
        ->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('data.0.attendance_date', '2026-08-02')
        ->assertJsonPath('data.1.attendance_date', '2026-08-01')
        ->assertJsonCount(2, 'data');
});

it('paciente sai só com código + iniciais (nunca o nome) e "Recebido" só de guia paga', function () {
    $patient = covenantClaimsPatient($this->entity, $this->covenant, 'JOÃO DA SILVA SAURO');

    covenantClaimsClaim($this->entity, $this->covenant, BillingClaimStatus::Paid, 200, [
        'patient_id' => $patient->id, 'paid_amount' => 180, 'glosa_amount' => 20, 'attendance_date' => '2026-08-20',
    ]);
    covenantClaimsClaim($this->entity, $this->covenant, BillingClaimStatus::Denied, 50, [
        'patient_id' => $patient->id, 'paid_amount' => 10, 'glosa_amount' => 50, 'attendance_date' => '2026-08-15',
    ]);
    covenantClaimsClaim($this->entity, $this->covenant, BillingClaimStatus::Submitted, 30, ['attendance_date' => '2026-08-10']);

    // Paciente de OUTRA clínica gravado na guia (vínculo forjado): não vaza.
    $other        = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $otherPatient = covenantClaimsPatient($other, $this->covenant, 'MARIA SIGILOSA');
    covenantClaimsClaim($this->entity, $this->covenant, BillingClaimStatus::Submitted, 40, [
        'patient_id' => $otherPatient->id, 'attendance_date' => '2026-08-05',
    ]);

    $response = $this->getJson(covenantClaimsUrl(['covenant_id' => $this->covenant->id]))->assertOk();
    $data     = $response->json('data');

    expect($data[0])->toMatchArray([
        'patient'  => "{$patient->code} · J. S.",
        'status'   => 'paid',
        'amount'   => 200,
        'received' => 180,
        'glosa'    => 20,
    ])
        // Glosada com paid_amount residual: não conta como recebida (regra do BI).
        ->and($data[1]['received'])->toEqual(0)
        ->and($data[1]['glosa'])->toEqual(50)
        ->and($data[2]['patient'])->toBe('Sem paciente')
        ->and($data[3]['patient'])->toBe('Sem paciente')
        ->and($response->getContent())->not->toContain('JOÃO')
        ->and($response->getContent())->not->toContain('SILVA')
        // (o código do paciente é sequencial POR clínica: o da outra pode
        // coincidir com o daqui, por isso a checagem é pelo "Sem paciente").
        ->and($response->getContent())->not->toContain('SIGILOSA');
});

it('convênio de outra clínica, UUID inválido, lista ou ausente → 422 (nunca 500)', function () {
    $other         = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $otherCovenant = Covenant::factory()->create(['entity_id' => $other->id, 'active' => true]);
    covenantClaimsClaim($other, $otherCovenant, BillingClaimStatus::Paid, 9000, ['paid_amount' => 9000]);

    $this->getJson(covenantClaimsUrl(['covenant_id' => $otherCovenant->id]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('covenant_id');

    $this->getJson(covenantClaimsUrl(['covenant_id' => 'abc']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('covenant_id');

    $this->getJson(covenantClaimsUrl(['covenant_id' => ['x']]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('covenant_id');

    $this->getJson(covenantClaimsUrl([]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('covenant_id');
});

it('convênio global mostra só as guias da própria clínica; convênio excluído continua com detalhe', function () {
    $global = Covenant::factory()->create(['entity_id' => null, 'active' => true, 'name' => 'GLOBAL']);
    $other  = Entity::factory()->create(['is_client' => true, 'active' => true]);

    covenantClaimsClaim($other, $global, BillingClaimStatus::Paid, 9000, ['paid_amount' => 9000]);
    $mine = covenantClaimsClaim($this->entity, $global, BillingClaimStatus::Submitted, 100);

    $this->getJson(covenantClaimsUrl(['covenant_id' => $global->id]))
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', (string) $mine->id);

    covenantClaimsClaim($this->entity, $this->covenant, BillingClaimStatus::Paid, 70, ['paid_amount' => 70]);
    $this->covenant->delete(); // soft delete: a linha "Inativo" do relatório ainda abre

    $this->getJson(covenantClaimsUrl(['covenant_id' => $this->covenant->id]))
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.received', 70);
});

it('período inválido no detalhe cai no mês atual (sem erro 500)', function () {
    covenantClaimsClaim($this->entity, $this->covenant, BillingClaimStatus::Submitted, 10, ['attendance_date' => now()->toDateString()]);

    $this->getJson(route('panel.financial.reports.covenants.claims', [
        'covenant_id' => $this->covenant->id,
        'from'        => 'abc',
        'to'          => ['x'],
        'page'        => 'zz',
    ]))
        ->assertOk()
        ->assertJsonPath('filters.from', now()->startOfMonth()->toDateString())
        ->assertJsonPath('filters.to', now()->toDateString())
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.total', 1);
});

it('iniciais: primeiro e último nome, sem partículas nem símbolos', function (?string $name, string $expected) {
    expect(app(CovenantReportService::class)->initials($name))->toBe($expected);
})->with([
    'nome composto'       => ['JOÃO DA SILVA', 'J. S.'],
    'minúsculas e acento' => ['élodie de araújo', 'É. A.'],
    'vários sobrenomes'   => ['ANA MARIA DOS SANTOS E SOUZA', 'A. S.'],
    'um nome só'          => ['MARIA', 'M.'],
    'símbolo no início'   => ['@CMD', 'C.'],
    'espaços extras'      => ['  PEDRO   ALVES  ', 'P. A.'],
    'só partículas'       => ['DA DOS', ''],
    'vazio'               => ['', ''],
    'nulo'                => [null, ''],
]);
