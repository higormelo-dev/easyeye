<?php

declare(strict_types=1);

use App\Models\{Entity, FinancialCashEntry, FinancialCategory};
use Illuminate\Support\Arr;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Relatório de fluxo de caixa (FinancialReportsController::cashFlow): contrato
 * de props lido pela tela (summary.income/expense — a tela lia revenue/expenses
 * e mostrava R$ 0,00), agrupamento por categoria e período validado.
 */

beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    actingAsFinancialEntityUser($this->entity);
});

function reportsCashFlowEntry(Entity $entity, array $attrs = []): FinancialCashEntry
{
    return FinancialCashEntry::query()->create(array_merge([
        'entity_id'   => $entity->id,
        'entry_date'  => now()->toDateString(),
        'description' => 'Lançamento',
        'type'        => 'income',
        'status'      => 'paid',
        'amount'      => 100,
        'active'      => true,
    ], $attrs));
}

it('entrega summary.income/expense/balance/pending e lançamentos com data ISO e status', function () {
    reportsCashFlowEntry($this->entity, ['amount' => 300]);
    reportsCashFlowEntry($this->entity, ['amount' => 50, 'status' => 'pending']);
    reportsCashFlowEntry($this->entity, ['amount' => 80, 'type' => 'expense']);
    reportsCashFlowEntry($this->entity, ['amount' => 999, 'status' => 'cancelled']);

    $response = $this->get(route('panel.financial.reports.cash-flow'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Panel/Financial/Reports/CashFlow')
            ->has('summary.income')
            ->has('summary.expense')
            ->has('summary.balance')
            ->has('summary.pending')
            // Lista paginada no servidor (paginador do Laravel).
            ->has('entries.data', 3)
            ->where('entries.total', 3)
            ->has('entries.data.0.id')
            ->has('entries.data.0.code')
            ->has('entries.data.0.status')
            ->where('entries.data.0.entry_date', now()->toDateString())
            ->has('routes.export')
            ->where('export_formats', ['csv', 'xlsx', 'pdf'])
            ->has('t.cashflow.title'));

    $summary = $response->viewData('page')['props']['summary'];

    // Semântica do CashFlowService::summary preservada (pagos + pendentes, sem cancelados).
    expect($summary['income'])->toEqual(350.0)
        ->and($summary['expense'])->toEqual(80.0)
        ->and($summary['balance'])->toEqual(270.0)
        ->and($summary['pending'])->toEqual(50.0);
});

it('agrupa por categoria sem quebrar nome com "|" nem trocar o tipo', function () {
    $category = FinancialCategory::query()->create([
        'entity_id' => $this->entity->id,
        'name'      => 'CONSULTAS | PARTICULAR',
        'type'      => 'income',
        'active'    => true,
    ]);

    reportsCashFlowEntry($this->entity, ['amount' => 120, 'category_id' => $category->id]);
    reportsCashFlowEntry($this->entity, ['amount' => 30, 'type' => 'expense']);

    $byCategory = collect($this->get(route('panel.financial.reports.cash-flow'))
        ->assertOk()
        ->viewData('page')['props']['byCategory']);

    $consultas = $byCategory->firstWhere('category', 'CONSULTAS | PARTICULAR');

    expect($consultas)->not->toBeNull()
        ->and($consultas['type'])->toBe('income')
        ->and($consultas['total'])->toEqual(120.0)
        ->and($byCategory->firstWhere('type', 'expense')['category'])->toBe('Sem categoria')
        ->and($byCategory->pluck('key')->unique()->count())->toBe(2);
});

it('"sem categoria" sai no idioma do usuário', function () {
    reportsCashFlowEntry($this->entity, ['amount' => 10]);

    $this->withSession(['locale' => 'en'])
        ->get(route('panel.financial.reports.cash-flow'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('byCategory.0.category', 'No category')
            ->where('t.cashflow.title', 'Cash flow report'));
});

it('período inválido cai no mês atual e invertido é trocado (sem erro 500)', function () {
    $this->get(route('panel.financial.reports.cash-flow', ['from' => 'abc', 'to' => ['x']]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.from', now()->startOfMonth()->toDateString())
            ->where('filters.to', now()->toDateString()));

    $this->get(route('panel.financial.reports.cash-flow', ['from' => '2026-09-20', 'to' => '2026-09-01']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.from', '2026-09-01')
            ->where('filters.to', '2026-09-20'));
});

it('não mostra lançamentos de outra clínica', function () {
    $other = Entity::factory()->create(['is_client' => true, 'active' => true]);
    reportsCashFlowEntry($other, ['amount' => 5000]);
    reportsCashFlowEntry($this->entity, ['amount' => 10]);

    $this->get(route('panel.financial.reports.cash-flow'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('entries.data', 1));
});

it('lang/pt_BR e lang/en de financial_reports têm as mesmas chaves', function () {
    $pt = array_keys(Arr::dot(require lang_path('pt_BR/financial_reports.php')));
    $en = array_keys(Arr::dot(require lang_path('en/financial_reports.php')));

    sort($pt);
    sort($en);

    expect($en)->toBe($pt);
});
