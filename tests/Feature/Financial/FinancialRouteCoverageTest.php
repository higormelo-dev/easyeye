<?php

use App\Models\{CashClose, FinancialCashEntry, ProcedurePrice};
use App\Models\{Covenant, Entity, Procedure};

beforeEach(function () {
    $this->entity   = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->covenant = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true]);
});

it('renders the cash-closing index without route errors', function () {
    actingAsFinancialEntityUser($this->entity);

    $this->get(route('panel.financial.cash-closing.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Panel/Financial/CashClosing/Index'));
});

it('closes and reopens a cash period via http', function () {
    actingAsFinancialEntityUser($this->entity);

    $this->post(route('panel.financial.cash-closing.store'), [
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end'   => now()->toDateString(),
    ])->assertRedirect();

    $close = CashClose::query()->where('entity_id', $this->entity->id)->firstOrFail();

    $this->delete(route('panel.financial.cash-closing.destroy', $close->id))
        ->assertRedirect();

    expect(CashClose::withTrashed()->find($close->id)->trashed())->toBeTrue();
});

it('renders the bi dashboard without route errors', function () {
    actingAsFinancialEntityUser($this->entity);

    $this->get(route('panel.financial.bi.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Panel/Financial/Bi/Index'));
});

it('renders and saves the procedure price table via http', function () {
    actingAsFinancialEntityUser($this->entity);

    $procedure = Procedure::factory()->create(['entity_id' => $this->entity->id, 'active' => true]);

    $this->get(route('panel.financial.procedure-prices.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Panel/Financial/ProcedurePrices/Index'));

    $this->post(route('panel.financial.procedure-prices.store'), [
        'covenant_id' => $this->covenant->id,
        'items'       => [
            ['procedure_id' => $procedure->id, 'price' => '199.90', 'charging' => true],
        ],
    ])->assertRedirect();

    expect(ProcedurePrice::query()
        ->where('entity_id', $this->entity->id)
        ->where('covenant_id', $this->covenant->id)
        ->where('procedure_id', $procedure->id)
        ->value('price'))->toEqual('199.90');
});

it('renders the cash-flow and covenants reports without route errors', function () {
    actingAsFinancialEntityUser($this->entity);

    $this->get(route('panel.financial.reports.cash-flow'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Panel/Financial/Reports/CashFlow'));

    $this->get(route('panel.financial.reports.covenants'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Panel/Financial/Reports/Covenants'));
});

it('renders the cash-flow index and updates an entry via http', function () {
    actingAsFinancialEntityUser($this->entity);

    $this->get(route('panel.financial.cash-flow.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Panel/Financial/CashFlow/Index'));

    $this->postJson(route('panel.financial.cash-flow.store'), [
        'entry_date'  => now()->toDateString(),
        'description' => 'Lançamento de teste',
        'type'        => 'income',
        'amount'      => 80,
    ])->assertOk();

    $entry = FinancialCashEntry::query()->where('entity_id', $this->entity->id)->firstOrFail();

    $this->putJson(route('panel.financial.cash-flow.update', $entry->id), [
        'entry_date'  => $entry->entry_date->toDateString(),
        'description' => 'Lançamento atualizado',
        'type'        => 'income',
        'amount'      => 95,
    ])->assertOk();

    expect($entry->fresh()->description)->toBe('Lançamento atualizado');
});

it('exports the cash-flow and covenants reports as csv', function () {
    actingAsFinancialEntityUser($this->entity);

    $this->get(route('panel.financial.reports.cash-flow.export'))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

    $this->get(route('panel.financial.reports.covenants.export'))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
});
