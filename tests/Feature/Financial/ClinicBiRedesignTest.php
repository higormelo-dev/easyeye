<?php

declare(strict_types=1);

use App\Enums\{BillingClaimStatus, ClientRule, ScheduleSituation};
use App\Models\{BillingClaim, Covenant, Entity, FinancialCashEntry, Patient, User};
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Dashboard gerencial — fase 3 (redesign): props novas da tela (today, CTAs
 * com o período, textos do PeriodFilter e dicas dos KPIs), período inválido
 * nunca vira 500 nem vaza para os atalhos e nenhum número mistura clínicas.
 */

beforeEach(function () {
    $this->entity   = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->covenant = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true, 'name' => 'CONVENIO DA CLINICA']);
    actingAsFinancialEntityUser($this->entity);
});

function clinicBiRedesignEntry(Entity $entity, string $type, float $amount, string $date): void
{
    FinancialCashEntry::query()->create([
        'entity_id'   => $entity->id,
        'entry_date'  => $date,
        'description' => "BI redesign {$type}",
        'type'        => $type,
        'status'      => 'paid',
        'amount'      => $amount,
        'active'      => true,
    ]);
}

function clinicBiRedesignClaim(Entity $entity, Covenant $covenant, float $amount, float $paid, float $glosa, string $date): void
{
    BillingClaim::query()->create([
        'entity_id'       => $entity->id,
        'covenant_id'     => $covenant->id,
        'status'          => BillingClaimStatus::Paid->value,
        'attendance_date' => $date,
        'amount'          => $amount,
        'paid_amount'     => $paid,
        'glosa_amount'    => $glosa,
        'quantity'        => 1,
        'unit_price'      => $amount,
    ]);
}

function clinicBiRedesignProps(TestResponse $response): array
{
    return $response->viewData('page')['props'];
}

it('entrega "hoje", os atalhos de faturamento/glosas no período aplicado e os textos do PeriodFilter e das dicas', function () {
    $this->get(route('panel.financial.bi.index', ['from' => '2026-08-01', 'to' => '2026-08-31']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Panel/Financial/Bi/Index')
            ->where('today', now()->toDateString())
            ->where('routes.billing', route('panel.financial.billing.index', ['from' => '2026-08-01', 'to' => '2026-08-31']))
            ->where('routes.glosas', route('panel.financial.tiss.glosas.index', ['from' => '2026-08-01', 'to' => '2026-08-31']))
            ->where('t.shared.period.presets.month', __('financial_shared.period.presets.month'))
            ->where('t.shared.period.invalid_range', __('financial_shared.period.invalid_range'))
            ->where('t.attendance_rate_hint', __('financial_bi.attendance_rate_hint'))
            ->has('t.trend_chart_aria')
            ->has('t.schedule_chart_aria')
            ->has('t.see_data'));
});

it('período inválido na URL não vira 500 e os atalhos usam o período normalizado (nunca o valor cru)', function (array $query) {
    $from = now()->startOfMonth()->toDateString();
    $to   = now()->toDateString();

    $this->get(route('panel.financial.bi.index', $query))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.from', $from)
            ->where('filters.to', $to)
            ->where('routes.billing', route('panel.financial.billing.index', ['from' => $from, 'to' => $to]))
            ->where('routes.glosas', route('panel.financial.tiss.glosas.index', ['from' => $from, 'to' => $to])));
})->with([
    'texto'            => [['from' => 'abc', 'to' => 'xyz']],
    'array'            => [['from' => ['2026-01-01'], 'to' => ['x' => 'y']]],
    'data inexistente' => [['from' => '2026-02-30', 'to' => '2026-13-01']],
    'ano zero'         => [['from' => '0000-01-01', 'to' => '0000-01-31']],
    'injeção'          => [['from' => "2026-09-01' OR '1'='1", 'to' => '2026-09-30;DROP TABLE schedules']],
    'ano de 5 dígitos' => [['from' => '20260-01-01', 'to' => '99999-12-31']],
]);

it('"Atualizar" com período inválido redireciona para o período normalizado (sem 500)', function () {
    $this->get(route('panel.financial.bi.index', ['from' => 'abc', 'to' => ['x'], 'refresh' => 1]))
        ->assertRedirect(route('panel.financial.bi.index', [
            'from' => now()->startOfMonth()->toDateString(),
            'to'   => now()->toDateString(),
        ]));
});

it('não mistura clínicas: caixa, faturamento, agenda e pacientes novos são só da clínica da sessão', function () {
    $date          = now()->toDateString();
    $other         = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $otherCovenant = Covenant::factory()->create(['entity_id' => $other->id, 'active' => true, 'name' => 'CONVENIO DE OUTRA']);

    // Outra clínica: números grandes que apareceriam se o escopo falhasse.
    clinicBiRedesignEntry($other, 'income', 9000, $date);
    clinicBiRedesignEntry($other, 'expense', 7000, $date);
    clinicBiRedesignClaim($other, $otherCovenant, 5000, 5000, 0, $date);
    createScheduleForEntity($other, ['situation' => ScheduleSituation::Attended->value]);
    createScheduleForEntity($other, ['situation' => ScheduleSituation::NoShow->value]);
    Patient::factory()->count(3)->create(['entity_id' => $other->id]);

    // Clínica da sessão.
    clinicBiRedesignEntry($this->entity, 'income', 300, $date);
    clinicBiRedesignEntry($this->entity, 'expense', 120, $date);
    clinicBiRedesignClaim($this->entity, $this->covenant, 200, 150, 50, $date);
    createScheduleForEntity($this->entity, ['situation' => ScheduleSituation::Attended->value]);
    createScheduleForEntity($this->entity, ['situation' => ScheduleSituation::Cancelled->value]);
    createScheduleForEntity($this->entity, ['situation' => ScheduleSituation::Scheduled->value]);
    Patient::factory()->create(['entity_id' => $this->entity->id]);

    $props = clinicBiRedesignProps($this->get(route('panel.financial.bi.index')));
    $kpis  = $props['summary']['kpis'];

    expect($kpis['income'])->toEqual(300.0)
        ->and($kpis['expense'])->toEqual(120.0)
        ->and($kpis['balance'])->toEqual(180.0)
        ->and($kpis['total_billed'])->toEqual(200.0)
        ->and($kpis['total_paid'])->toEqual(150.0)
        ->and($kpis['total_glosa'])->toEqual(50.0)
        ->and($kpis['ticket_medio'])->toEqual(150.0)
        ->and($kpis['attended'])->toBe(1)
        ->and($kpis['noshow'])->toBe(0)
        ->and($kpis['cancelled'])->toBe(1)
        ->and($kpis['total_schedules'])->toBe(3)
        ->and($kpis['new_patients'])->toBe(1)
        ->and(array_column($props['summary']['by_covenant_chart'], 'label'))->toBe(['CONVENIO DA CLINICA'])
        ->and(collect($props['trend'])->last()['income'])->toEqual(300.0);

    // A outra clínica, com o cache da primeira já quente, vê só os próprios números.
    actingAsFinancialEntityUser($other);

    $otherKpis = clinicBiRedesignProps($this->get(route('panel.financial.bi.index')))['summary']['kpis'];

    expect($otherKpis['income'])->toEqual(9000.0)
        ->and($otherKpis['total_billed'])->toEqual(5000.0)
        ->and($otherKpis['total_schedules'])->toBe(2)
        ->and($otherKpis['new_patients'])->toBe(3);
});

it('perfil sem permissão financeira (secretária) não abre o BI', function () {
    $user       = User::factory()->create();
    $entityUser = createEntityUser($this->entity, $user, ClientRule::Secretary->value);

    $this->withSession(panelSession($entityUser))->actingAs($user)
        ->get(route('panel.financial.bi.index'))
        ->assertForbidden();
});
