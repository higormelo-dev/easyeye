<?php

declare(strict_types=1);

use App\Models\{Entity, FinancialCashEntry, FinancialCategory};
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Relatório de fluxo de caixa (Fase 3): KPIs realizado × previsto com o
 * overview() da tela de Fluxo de caixa SEM tirar summary (PDF/fechamento/BI),
 * "Por dia" com saldo do dia e acumulado, "Por categoria" por category_id com
 * participação no total do tipo, forma de pagamento traduzida e textos do
 * PeriodFilter (t.shared).
 */

const REPORTS_CF_FROM = '2026-08-01';
const REPORTS_CF_TO   = '2026-08-31';

beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    actingAsFinancialEntityUser($this->entity);
});

function reportsCfEntry(Entity $entity, array $attrs = []): FinancialCashEntry
{
    return FinancialCashEntry::query()->create(array_merge([
        'entity_id'   => $entity->id,
        'entry_date'  => '2026-08-10',
        'description' => 'Lançamento',
        'type'        => 'income',
        'status'      => 'paid',
        'amount'      => 100,
        'active'      => true,
    ], $attrs));
}

function reportsCfProps($testCase): array
{
    return $testCase->get(route('panel.financial.reports.cash-flow', ['from' => REPORTS_CF_FROM, 'to' => REPORTS_CF_TO]))
        ->assertOk()
        ->viewData('page')['props'];
}

it('KPIs realizado × previsto vêm do overview() e summary continua com as mesmas chaves e valores', function () {
    reportsCfEntry($this->entity, ['amount' => 300]);                                          // recebido
    reportsCfEntry($this->entity, ['amount' => 50, 'status' => 'pending']);                    // a receber
    reportsCfEntry($this->entity, ['amount' => 80, 'type' => 'expense']);                      // pago
    reportsCfEntry($this->entity, ['amount' => 40, 'type' => 'expense', 'status' => 'pending']); // a pagar
    reportsCfEntry($this->entity, ['amount' => 999, 'status' => 'cancelled']);                 // fora

    $other = Entity::factory()->create(['is_client' => true, 'active' => true]);
    reportsCfEntry($other, ['amount' => 7000]);

    $props = reportsCfProps($this);

    expect($props['overview'])->toMatchArray([
        'received'          => 300.0,
        'receivable'        => 50.0,
        'paid'              => 80.0,
        'payable'           => 40.0,
        'realized_balance'  => 220.0,
        'projected_balance' => 230.0,
    ])
        // summary() intocado: pagos + pendentes, sem cancelados (PDF usa).
        ->and($props['summary'])->toBe(['income' => 350.0, 'expense' => 120.0, 'balance' => 230.0, 'pending' => 50.0])
        // Saldo previsto = saldo do summary (mesma base, sem cancelados).
        ->and($props['overview']['projected_balance'])->toEqual($props['summary']['balance']);
});

it('"Por dia" em ordem cronológica com saldo do dia e saldo acumulado (último = saldo do período)', function () {
    reportsCfEntry($this->entity, ['entry_date' => '2026-08-12', 'amount' => 10.1, 'type' => 'expense']);
    reportsCfEntry($this->entity, ['entry_date' => '2026-08-05', 'amount' => 100.2]);
    reportsCfEntry($this->entity, ['entry_date' => '2026-08-05', 'amount' => 30.1, 'type' => 'expense', 'status' => 'pending']);
    reportsCfEntry($this->entity, ['entry_date' => '2026-08-20', 'amount' => 200, 'type' => 'expense']);
    reportsCfEntry($this->entity, ['entry_date' => '2026-08-20', 'amount' => 999, 'status' => 'cancelled']);

    $props = reportsCfProps($this);

    expect($props['byDay'])->toBe([
        ['day' => '2026-08-05', 'income' => 100.2, 'expense' => 30.1, 'balance' => 70.1, 'cumulative' => 70.1],
        ['day' => '2026-08-12', 'income' => 0.0, 'expense' => 10.1, 'balance' => -10.1, 'cumulative' => 60.0],
        ['day' => '2026-08-20', 'income' => 0.0, 'expense' => 200.0, 'balance' => -200.0, 'cumulative' => -140.0],
    ])
        ->and(end($props['byDay'])['cumulative'])->toEqual($props['summary']['balance']);
});

it('"Por categoria" agrupa por category_id (homônimos separados) com % do total do tipo', function () {
    $consultas = FinancialCategory::query()->create(['entity_id' => $this->entity->id, 'name' => 'CONSULTAS', 'type' => 'income', 'active' => true]);
    $homonimo  = FinancialCategory::query()->create(['entity_id' => $this->entity->id, 'name' => 'CONSULTAS', 'type' => 'income', 'active' => true]);

    reportsCfEntry($this->entity, ['amount' => 300, 'category_id' => $consultas->id]);
    reportsCfEntry($this->entity, ['amount' => 100, 'category_id' => $homonimo->id, 'status' => 'pending']);
    reportsCfEntry($this->entity, ['amount' => 50, 'type' => 'expense']);

    $rows = collect(reportsCfProps($this)['byCategory']);

    expect($rows)->toHaveCount(3)
        ->and($rows->firstWhere('category_id', (string) $consultas->id))->toMatchArray([
            'category' => 'CONSULTAS', 'type' => 'income', 'total' => 300.0, 'share' => 75.0,
        ])
        ->and($rows->firstWhere('category_id', (string) $homonimo->id))->toMatchArray([
            'category' => 'CONSULTAS', 'type' => 'income', 'total' => 100.0, 'share' => 25.0,
        ])
        ->and($rows->firstWhere('type', 'expense'))->toMatchArray([
            'category_id' => null, 'category' => 'Sem categoria', 'total' => 50.0, 'share' => 100.0,
        ])
        // Maior total primeiro.
        ->and($rows->pluck('total')->all())->toBe([300.0, 100.0, 50.0]);
});

it('lançamentos trazem a forma de pagamento traduzida; tela recebe hoje e os textos do filtro de período', function () {
    reportsCfEntry($this->entity, ['payment_method' => 'cash']);

    $this->get(route('panel.financial.reports.cash-flow', ['from' => REPORTS_CF_FROM, 'to' => REPORTS_CF_TO]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Panel/Financial/Reports/CashFlow')
            ->where('entries.data.0.payment_method_label', 'À Vista')
            ->where('today', now()->toDateString())
            ->has('t.shared.period.presets.month')
            ->has('t.cashflow.kpi_projected_balance_hint')
            ->has('overview.realized_balance'));

    $this->withSession(['locale' => 'en'])
        ->get(route('panel.financial.reports.cash-flow', ['from' => REPORTS_CF_FROM, 'to' => REPORTS_CF_TO]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('t.cashflow.group_projected', 'Projected')
            ->where('t.shared.period.label', 'Period'));
});
