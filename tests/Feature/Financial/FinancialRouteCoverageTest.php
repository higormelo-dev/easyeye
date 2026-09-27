<?php

use App\Models\{CashClose, FinancialCashEntry, ProcedurePrice};
use App\Models\{Covenant, Entity, Procedure};
use App\Services\Financial\BillingService;

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

    // Reabrir exige motivo (auditoria) e perfil admin.
    $this->delete(route('panel.financial.cash-closing.destroy', $close->id), ['reason' => 'Reabertura para ajuste de teste'])
        ->assertRedirect();

    expect(CashClose::withTrashed()->find($close->id)->trashed())->toBeTrue();
});

it('renders the bi dashboard without route errors', function () {
    actingAsFinancialEntityUser($this->entity);

    // Contrato lido pela tela: a Vue lia kpis.revenue/expenses e trend[].month/
    // revenue e mostrava R$ 0,00 — só o nome do componente não pegava isso.
    $this->get(route('panel.financial.bi.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Panel/Financial/Bi/Index')
            ->has('summary.kpis.income')
            ->has('summary.kpis.expense')
            ->has('summary.kpis.balance')
            ->has('trend.0.period')
            ->has('trend.0.income')
            ->has('trend.0.expense'));
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
        ->assertInertia(fn ($page) => $page
            ->component('Panel/Financial/Reports/CashFlow')
            ->has('summary.income')
            ->has('summary.expense')
            ->has('summary.balance')
            ->has('summary.pending'));

    $this->get(route('panel.financial.reports.covenants'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Panel/Financial/Reports/Covenants')
            ->has('summary.total_claims')
            ->has('summary.total_amount')
            ->has('summary.total_paid')
            ->has('summary.total_denied'));
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

it('cancels a draft claim and a draft batch and fixes a pending tiss guide via http (fase 4)', function () {
    actingAsFinancialEntityUser($this->entity);
    $service = app(BillingService::class);

    // Corrigir pendência: guia TISS individual sem CID → JSON com a pré-validação refeita.
    $pending = $service->createIndividual(['schedule_id' => createBillableSchedule($this->entity)->id, 'unit_price' => 150]);

    $this->postJson(route('panel.financial.billing.claims.fix-pending', $pending->id), ['clinical_indication' => 'H40.1'])
        ->assertOk()
        ->assertJsonStructure(['message', 'attached', 'validation' => ['passes', 'errors', 'warnings', 'summary']]);

    // Cancelar guia (motivo obrigatório).
    $this->post(route('panel.financial.billing.claims.cancel', $pending->id), ['reason' => 'Cancelamento pela cobertura de rotas'])
        ->assertRedirect()
        ->assertSessionHas('success');

    // Cancelar lote.
    $schedule = createBillableSchedule($this->entity);
    $batch    = $service->createBatch([
        'covenant_id'         => $schedule->covenant_id,
        'date_from'           => now()->subDay()->toDateString(),
        'date_until'          => now()->toDateString(),
        'unit_price'          => 150,
        'clinical_indication' => 'H40.1',
    ]);

    $this->post(route('panel.financial.billing.batches.cancel', $batch->id), ['reason' => 'Cancelamento pela cobertura de rotas'])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($pending->fresh()->status->value)->toBe('cancelled')
        ->and($batch->fresh()->status->value)->toBe('cancelled');
});

it('attaches claims to a batch, reprocesses pending claims and records bulk/batch payments via http (fase 4, parte 2)', function () {
    actingAsFinancialEntityUser($this->entity);
    $service = app(BillingService::class);

    // Lote TISS sem CID (guia pendente) + guia individual do mesmo convênio.
    $schedule = createBillableSchedule($this->entity);
    $batch    = $service->createBatch([
        'covenant_id' => $schedule->covenant_id,
        'date_from'   => now()->subDay()->toDateString(),
        'date_until'  => now()->toDateString(),
        'unit_price'  => 150,
    ]);
    $single = $service->createIndividual([
        'schedule_id'         => createBillableSchedule($this->entity, ['covenant_id' => $schedule->covenant_id])->id,
        'unit_price'          => 150,
        'clinical_indication' => 'H40.1',
    ]);

    $this->getJson(route('panel.financial.billing.batches.attachable-claims', $batch->id))
        ->assertOk()
        ->assertJsonStructure(['data' => [['id', 'code', 'origin', 'has_errors']], 'total', 'max']);

    $this->getJson(route('panel.financial.billing.claims.attach-targets', $single->id))
        ->assertOk()
        ->assertJsonStructure(['data' => [['id', 'code', 'attach_url', 'is_current']]]);

    $this->postJson(route('panel.financial.billing.batches.attach-claims', $batch->id), ['claim_ids' => [$single->id]])
        ->assertOk()
        ->assertJsonStructure(['message', 'attached_count', 'pending_count', 'results' => [['claim_id', 'code', 'attached', 'validation' => ['passes', 'errors', 'warnings', 'summary']]]]);

    $this->postJson(route('panel.financial.billing.batches.reprocess-pending', $batch->id))
        ->assertOk()
        ->assertJsonPath('attached_count', 0);

    // Recebimento: guia individual (sem lote) e o lote particular já cobrado.
    $receivable = $service->createIndividual(['schedule_id' => createParticularSchedule($this->entity)->id, 'unit_price' => 100]);

    $this->postJson(route('panel.financial.billing.claims.bulk-receipt'), [
        'paid_at'        => now()->toDateString(),
        'payment_method' => 'transfer',
        'items'          => [['claim_id' => $receivable->id, 'paid_amount' => 100]],
    ])->assertOk()->assertJsonStructure(['message', 'paid', 'skipped', 'total_paid']);

    $particular = $service->createBatch([
        'covenant_id' => createParticularSchedule($this->entity)->covenant_id,
        'date_from'   => now()->subDay()->toDateString(),
        'date_until'  => now()->toDateString(),
        'unit_price'  => 100,
    ]);
    $service->submitBatch($particular->fresh());

    $this->getJson(route('panel.financial.billing.batches.receipt-preview', $particular->id))
        ->assertOk()
        ->assertJsonPath('count', 1);

    $this->postJson(route('panel.financial.billing.batches.receipt', $particular->id), [
        'paid_at'        => now()->toDateString(),
        'payment_method' => 'cash',
    ])->assertOk()->assertJsonPath('total_paid', 100);

    expect(FinancialCashEntry::query()->where('entity_id', $this->entity->id)->count())->toBe(2);
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
