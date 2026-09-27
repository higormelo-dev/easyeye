<?php

declare(strict_types=1);

use App\Enums\{BillingClaimStatus, ScheduleSituation};
use App\Models\{BillingClaim, Covenant, Entity, FinancialCashEntry};
use App\Services\Financial\ClinicBiService;
use Illuminate\Support\{Arr, Carbon};
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Dashboard gerencial (ClinicBiController + ClinicBiService): contrato de
 * props lido pela tela, período validado, cache sem mistura de idioma e
 * botão "Atualizar".
 */

beforeEach(function () {
    $this->entity     = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->covenant   = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true]);
    $this->entityUser = actingAsFinancialEntityUser($this->entity);
});

function biDashboardEntry(Entity $entity, string $type, float $amount, string $status = 'paid'): FinancialCashEntry
{
    return FinancialCashEntry::query()->create([
        'entity_id'   => $entity->id,
        'entry_date'  => now()->toDateString(),
        'description' => "BI {$type} {$status}",
        'type'        => $type,
        'status'      => $status,
        'amount'      => $amount,
        'active'      => true,
    ]);
}

function biDashboardClaim(Entity $entity, Covenant $covenant, BillingClaimStatus $status, float $amount, float $paid = 0, float $glosa = 0): BillingClaim
{
    return BillingClaim::query()->create([
        'entity_id'       => $entity->id,
        'covenant_id'     => $covenant->id,
        'status'          => $status->value,
        'attendance_date' => now()->toDateString(),
        'amount'          => $amount,
        'paid_amount'     => $paid,
        'glosa_amount'    => $glosa,
        'quantity'        => 1,
        'unit_price'      => $amount,
    ]);
}

/** Props do page Inertia como o PHP montou (floats preservados). */
function biDashboardProps(TestResponse $response): array
{
    return $response->viewData('page')['props'];
}

it('entrega as chaves que a tela lê: kpis.income/expense/balance e trend[].period/month/income/expense', function () {
    biDashboardEntry($this->entity, 'income', 300);
    biDashboardEntry($this->entity, 'expense', 120);
    biDashboardEntry($this->entity, 'income', 999, 'pending'); // BI conta só pagos

    biDashboardClaim($this->entity, $this->covenant, BillingClaimStatus::Submitted, 100);
    biDashboardClaim($this->entity, $this->covenant, BillingClaimStatus::Paid, 200, paid: 180, glosa: 20);
    biDashboardClaim($this->entity, $this->covenant, BillingClaimStatus::Draft, 1000);

    $response = $this->get(route('panel.financial.bi.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Panel/Financial/Bi/Index')
            ->has('summary.kpis.income')
            ->has('summary.kpis.expense')
            ->has('summary.kpis.balance')
            ->has('summary.kpis.total_billed')
            ->has('summary.kpis.total_paid')
            ->has('summary.kpis.total_glosa')
            ->has('summary.by_covenant_chart')
            ->has('summary.generated_at')
            ->has('trend', 6)
            ->has('trend.0.period')
            ->has('trend.0.month')
            ->has('trend.0.income')
            ->has('trend.0.expense')
            ->has('generated_at')
            ->has('routes.billing')
            ->has('routes.glosas')
            ->has('t.income')
            ->where('filters.from', now()->startOfMonth()->toDateString())
            ->where('filters.to', now()->toDateString()));

    $props = biDashboardProps($response);
    $kpis  = $props['summary']['kpis'];

    expect($kpis['income'])->toEqual(300.0)
        ->and($kpis['expense'])->toEqual(120.0)
        ->and($kpis['balance'])->toEqual(180.0)
        ->and($kpis['total_billed'])->toEqual(300.0)
        ->and($kpis['total_paid'])->toEqual(180.0)
        ->and($kpis['total_glosa'])->toEqual(20.0);

    $current = collect($props['trend'])->last();

    expect($current['month'])->toBe(now()->format('Y-m'))
        ->and($current['period'])->toBe(now()->format('m/Y'))
        ->and($current['income'])->toEqual(300.0)
        ->and($current['expense'])->toEqual(120.0);
});

it('período inválido na URL cai no mês atual em vez de erro 500', function () {
    $this->get(route('panel.financial.bi.index', ['from' => 'abc', 'to' => ['x']]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.from', now()->startOfMonth()->toDateString())
            ->where('filters.to', now()->toDateString()));

    $this->get(route('panel.financial.bi.index', ['from' => '0000-01-01', 'to' => '2026-02-30']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.from', now()->startOfMonth()->toDateString())
            ->where('filters.to', now()->toDateString()));
});

it('período invertido é trocado e devolvido normalizado em filters', function () {
    $this->get(route('panel.financial.bi.index', ['from' => '2026-09-20', 'to' => '2026-09-01']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.from', '2026-09-01')
            ->where('filters.to', '2026-09-20'));
});

it('o cache não mistura idiomas: rótulos saem no idioma de quem abre a tela', function () {
    createScheduleForEntity($this->entity, ['situation' => ScheduleSituation::Attended->value]);

    $this->withSession(['locale' => 'en'])
        ->get(route('panel.financial.bi.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.schedule_chart.0.key', 'attended')
            ->where('summary.schedule_chart.0.label', 'Attended')
            ->where('t.refresh', 'Refresh'));

    // Mesma clínica e período (cache quente), outro idioma.
    $this->withSession(['locale' => 'pt_BR'])
        ->get(route('panel.financial.bi.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.schedule_chart.0.label', 'Atendidos')
            ->where('t.refresh', 'Atualizar'));
});

it('"Atualizar" descarta o cache da clínica e mostra o lançamento recém-feito', function () {
    biDashboardEntry($this->entity, 'income', 100);

    expect(biDashboardProps($this->get(route('panel.financial.bi.index')))['summary']['kpis']['income'])->toEqual(100.0);

    biDashboardEntry($this->entity, 'income', 50);

    // Ainda em cache (até 10 min): o número antigo continua.
    expect(biDashboardProps($this->get(route('panel.financial.bi.index')))['summary']['kpis']['income'])->toEqual(100.0);

    $from = now()->startOfMonth()->toDateString();
    $to   = now()->toDateString();

    $this->get(route('panel.financial.bi.index', ['from' => $from, 'to' => $to, 'refresh' => 1]))
        ->assertRedirect(route('panel.financial.bi.index', ['from' => $from, 'to' => $to]));

    $props = biDashboardProps($this->get(route('panel.financial.bi.index', ['from' => $from, 'to' => $to])));

    expect($props['summary']['kpis']['income'])->toEqual(150.0)
        ->and(collect($props['trend'])->last()['income'])->toEqual(150.0)
        ->and($props['generated_at'])->toBeString();
});

it('"Atualizar" de uma clínica não recalcula nem expõe o cache de outra', function () {
    $other = Entity::factory()->create(['is_client' => true, 'active' => true]);
    biDashboardEntry($other, 'income', 777);
    biDashboardEntry($this->entity, 'income', 10);

    $this->get(route('panel.financial.bi.index', ['refresh' => 1]))->assertRedirect();

    expect(biDashboardProps($this->get(route('panel.financial.bi.index')))['summary']['kpis']['income'])->toEqual(10.0);
});

function biDashboardPaidEntryOn(Entity $entity, string $date, string $type, float $amount): void
{
    FinancialCashEntry::query()->create([
        'entity_id'   => $entity->id,
        'entry_date'  => $date,
        'description' => "BI {$type} {$date}",
        'type'        => $type,
        'status'      => 'paid',
        'amount'      => $amount,
        'active'      => true,
    ]);
}

it('tendência mensal no dia 31 lista 6 meses distintos e consecutivos (sem estouro do Carbon)', function () {
    // Antes: now()->subMonths($i) em 31/10 caía em 01/10, 01/07… e a série
    // saía 05,07,07,08,10,10 — setembro e junho sumiam.
    $this->travelTo(Carbon::parse('2026-10-31 10:00:00'));

    biDashboardPaidEntryOn($this->entity, '2026-05-10', 'income', 5);
    biDashboardPaidEntryOn($this->entity, '2026-09-15', 'income', 70);
    biDashboardPaidEntryOn($this->entity, '2026-10-31', 'expense', 3);

    $trend = biDashboardProps($this->get(route('panel.financial.bi.index')))['trend'];

    expect(array_column($trend, 'month'))->toBe(['2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10'])
        ->and(array_column($trend, 'period'))->toBe(['05/2026', '06/2026', '07/2026', '08/2026', '09/2026', '10/2026'])
        ->and($trend[0]['income'])->toEqual(5.0)
        ->and($trend[4]['income'])->toEqual(70.0)
        ->and($trend[5]['expense'])->toEqual(3.0);
});

it('tendência em 31/07 começa em fevereiro e inclui os lançamentos de fevereiro', function () {
    // Antes: o início da consulta virava 01/03 (31/02 estoura) e fevereiro sumia.
    $this->travelTo(Carbon::parse('2026-07-31 10:00:00'));

    biDashboardPaidEntryOn($this->entity, '2026-02-10', 'income', 40);

    $trend = biDashboardProps($this->get(route('panel.financial.bi.index')))['trend'];

    expect(array_column($trend, 'month'))->toBe(['2026-02', '2026-03', '2026-04', '2026-05', '2026-06', '2026-07'])
        ->and($trend[0]['income'])->toEqual(40.0);
});

it('convênio excluído continua dono do seu faturamento no gráfico, sem se fundir com o homônimo recriado', function () {
    $this->covenant->update(['name' => 'UNIMED']);
    biDashboardClaim($this->entity, $this->covenant, BillingClaimStatus::Submitted, 300);
    $this->covenant->delete(); // soft delete

    $recreated = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true, 'name' => 'UNIMED']);
    biDashboardClaim($this->entity, $recreated, BillingClaimStatus::Submitted, 100);

    $this->get(route('panel.financial.bi.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('summary.by_covenant_chart', 2)
            ->where('summary.by_covenant_chart.0.label', 'UNIMED (inativo)')
            ->where('summary.by_covenant_chart.0.value', 300)
            ->where('summary.by_covenant_chart.1.label', 'UNIMED')
            ->where('summary.by_covenant_chart.1.value', 100));

    // Cache quente, outro idioma: o sufixo é traduzido depois da leitura.
    $this->withSession(['locale' => 'en'])
        ->get(route('panel.financial.bi.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('summary.by_covenant_chart.0.label', 'UNIMED (inactive)'));
});

it('atalhos para os relatórios levam o período aplicado no BI', function () {
    $this->get(route('panel.financial.bi.index', ['from' => '2026-08-01', 'to' => '2026-08-31']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('routes.cash_flow', route('panel.financial.reports.cash-flow', ['from' => '2026-08-01', 'to' => '2026-08-31']))
            ->where('routes.covenants', route('panel.financial.reports.covenants', ['from' => '2026-08-01', 'to' => '2026-08-31'])));
});

it('o tooltip do "Atualizar" informa o maior prazo de cache (resumo ou tendência)', function () {
    $this->get(route('panel.financial.bi.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('cache_minutes', max(ClinicBiService::SUMMARY_TTL_MINUTES, ClinicBiService::TREND_TTL_MINUTES))
            ->where('cache_minutes', 30));
});

it('lang/pt_BR e lang/en de financial_bi têm as mesmas chaves', function () {
    $pt = array_keys(Arr::dot(require lang_path('pt_BR/financial_bi.php')));
    $en = array_keys(Arr::dot(require lang_path('en/financial_bi.php')));

    sort($pt);
    sort($en);

    expect($en)->toBe($pt);
});
