<?php

declare(strict_types=1);

use App\Enums\{BillingBatchStatus, BillingClaimStatus};
use App\Models\{BillingBatch, BillingClaim, Covenant, Entity, Procedure, ProcedurePrice, VisitType};
use App\Services\Financial\{BillingReportService, BillingService};
use Illuminate\Testing\TestResponse;

/*
 * Faturamento (Fase 3): KPIs do período/convênio sempre da clínica da sessão,
 * estimativa de "A faturar" pela tabela de preços, "Pendências TISS" (guia
 * TISS em aberto fora de lote TISS), período/convênio também nas abas Guias e
 * Lotes, filtro por lote só da própria clínica e filtros inválidos sem 500.
 */

beforeEach(function (): void {
    $this->entity   = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->covenant = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true]);
    actingAsFinancialEntityUser($this->entity);
});

/** @param array<string, mixed> $overrides */
function bkpiClaim(Entity $entity, Covenant $covenant, array $overrides = []): BillingClaim
{
    return BillingClaim::query()->create(array_merge([
        'entity_id'       => $entity->id,
        'covenant_id'     => $covenant->id,
        'status'          => BillingClaimStatus::Submitted->value,
        'attendance_date' => now()->toDateString(),
        'amount'          => 100.00,
        'quantity'        => 1,
        'unit_price'      => 100.00,
    ], $overrides));
}

/** @param array<string, mixed> $overrides */
function bkpiBatch(Entity $entity, Covenant $covenant, array $overrides = []): BillingBatch
{
    return BillingBatch::query()->create(array_merge([
        'entity_id'    => $entity->id,
        'covenant_id'  => $covenant->id,
        'status'       => BillingBatchStatus::Draft->value,
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end'   => now()->toDateString(),
        'issued_at'    => now(),
    ], $overrides));
}

/** @return array<string, mixed> props da página (viewData do Inertia) */
function bkpiProps(TestResponse $response): array
{
    return $response->viewData('page')['props'];
}

describe('KPIs', function (): void {
    it('somam só a clínica da sessão, no período (data do atendimento) e no convênio filtrado', function (): void {
        bkpiClaim($this->entity, $this->covenant, ['amount' => 100]);
        bkpiClaim($this->entity, $this->covenant, ['status' => BillingClaimStatus::Paid->value, 'amount' => 150, 'paid_amount' => 150, 'paid_at' => now()]);
        bkpiClaim($this->entity, $this->covenant, ['status' => BillingClaimStatus::Denied->value, 'amount' => 200, 'glosa_amount' => 40]);
        // Cancelada não entra no glosado; fora do período não entra em nada.
        bkpiClaim($this->entity, $this->covenant, ['status' => BillingClaimStatus::Cancelled->value, 'amount' => 90, 'glosa_amount' => 30]);
        bkpiClaim($this->entity, $this->covenant, ['amount' => 999, 'attendance_date' => now()->subYear()->toDateString()]);

        // Outro convênio da mesma clínica: só conta sem filtro de convênio.
        $otherCovenant = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true]);
        bkpiClaim($this->entity, $otherCovenant, ['amount' => 60]);

        // Outra clínica: nunca entra.
        $other         = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $theirCovenant = Covenant::factory()->create(['entity_id' => $other->id, 'active' => true]);
        bkpiClaim($other, $theirCovenant, ['amount' => 500]);
        bkpiClaim($other, $theirCovenant, ['status' => BillingClaimStatus::Paid->value, 'amount' => 500, 'paid_amount' => 500]);
        bkpiClaim($other, $theirCovenant, ['status' => BillingClaimStatus::Denied->value, 'amount' => 500, 'glosa_amount' => 70]);

        $this->get(route('panel.financial.billing.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('kpis.open.amount', 160)
                ->where('kpis.open.count', 2)
                ->where('kpis.received.amount', 150)
                ->where('kpis.received.count', 1)
                ->where('kpis.denied.amount', 40)
                ->where('kpis.denied.count', 1));

        $this->get(route('panel.financial.billing.index', ['covenant_id' => $this->covenant->id]))
            ->assertInertia(fn ($page) => $page
                ->where('kpis.open.amount', 100)
                ->where('kpis.open.count', 1));
    });

    // Fase 4 (parte 2): "Glosado" segue a regra única de BillingReportService
    // (sem rascunho nem cancelada) — o mesmo número do relatório de convênios e do BI.
    it('"Glosado" exclui rascunho e cancelada: igual ao relatório de convênios (BillingReportService)', function (): void {
        bkpiClaim($this->entity, $this->covenant, ['status' => BillingClaimStatus::Denied->value, 'amount' => 200, 'glosa_amount' => 40]);
        bkpiClaim($this->entity, $this->covenant, ['status' => BillingClaimStatus::Paid->value, 'amount' => 150, 'paid_amount' => 120, 'glosa_amount' => 30, 'paid_at' => now()]);
        // Rascunho com glosa (legado — os fluxos atuais não geram) e cancelada: fora.
        bkpiClaim($this->entity, $this->covenant, ['status' => BillingClaimStatus::Draft->value, 'amount' => 100, 'glosa_amount' => 25]);
        bkpiClaim($this->entity, $this->covenant, ['status' => BillingClaimStatus::Cancelled->value, 'amount' => 90, 'glosa_amount' => 30]);

        $this->get(route('panel.financial.billing.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('kpis.denied.amount', 70)
                ->where('kpis.denied.count', 2));

        $report = app(BillingReportService::class);
        $totals = $report->totals($report->byCovenant((string) $this->entity->id, now()->startOfMonth()->toDateString(), now()->toDateString()));

        expect($totals['denied'])->toBe(70.0);
    });

    it('"A faturar" conta os elegíveis e estima pela tabela de preços da clínica (procedimento × convênio)', function (): void {
        $covenant  = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true, 'table' => true, 'ans_registry' => '326305']);
        $procedure = Procedure::factory()->create(['name' => 'Consulta oftalmológica']);
        $visitType = VisitType::create([
            'entity_id'    => $this->entity->id,
            'code'         => 'VT-KPI-1',
            'name'         => 'Consulta',
            'procedure_id' => $procedure->id,
            'active'       => true,
        ]);

        ProcedurePrice::factory()->create([
            'entity_id' => $this->entity->id, 'covenant_id' => $covenant->id, 'procedure_id' => $procedure->id, 'price' => 150,
        ]);
        // Preço de outra clínica para o mesmo par não vale aqui.
        $other = Entity::factory()->create(['is_client' => true, 'active' => true]);
        ProcedurePrice::factory()->create([
            'entity_id' => $other->id, 'covenant_id' => $covenant->id, 'procedure_id' => $procedure->id, 'price' => 999,
        ]);

        $priced = createBillableSchedule($this->entity, ['covenant_id' => $covenant->id, 'visit_id' => $visitType->id]);
        createBillableSchedule($this->entity, ['covenant_id' => $covenant->id, 'visit_id' => $visitType->id]);
        $unpriced = createBillableSchedule($this->entity);

        $response = $this->get(route('panel.financial.billing.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('kpis.to_bill.count', 3)
                ->where('kpis.to_bill.priced_count', 2)
                ->where('kpis.to_bill.estimated_amount', 300)
                ->where('totals.eligible', 3));

        $rows = collect(bkpiProps($response)['eligibleSchedules']['data'])->keyBy('id');

        expect($rows[$priced->id]['suggested_price'])->toEqual(150.0)
            ->and($rows[$priced->id]['procedure_name'])->toBe('Consulta oftalmológica')
            ->and($rows[$unpriced->id]['suggested_price'])->toBeNull();
    });

    it('"A faturar" sem nenhum preço na tabela não inventa estimativa', function (): void {
        createBillableSchedule($this->entity);

        $this->get(route('panel.financial.billing.index'))
            ->assertInertia(fn ($page) => $page
                ->where('kpis.to_bill.count', 1)
                ->where('kpis.to_bill.priced_count', 0)
                ->where('kpis.to_bill.estimated_amount', null));
    });

    it('"Pendências TISS": guia TISS em aberto fora de lote TISS (individual ou barrada na pré-validação), com filtro na aba Guias', function (): void {
        $service = app(BillingService::class);

        // Individual TISS: fora de lote (sem erro).
        $individual = $service->createIndividual([
            'schedule_id'         => createBillableSchedule($this->entity)->id,
            'unit_price'          => 150,
            'clinical_indication' => 'H40.1',
        ]);

        // Lote sem CID: a pré-validação barra e a guia fica com pendência.
        $blockedSchedule = createBillableSchedule($this->entity);
        $service->createBatch([
            'covenant_id' => $blockedSchedule->covenant_id,
            'date_from'   => now()->subDay()->toDateString(),
            'date_until'  => now()->toDateString(),
            'unit_price'  => 150,
        ]);
        $blocked = BillingClaim::query()->where('schedule_id', $blockedSchedule->id)->firstOrFail();

        // Lote com CID: a guia entra no lote TISS (não é pendência).
        $okSchedule = createBillableSchedule($this->entity);
        $service->createBatch([
            'covenant_id'         => $okSchedule->covenant_id,
            'date_from'           => now()->subDay()->toDateString(),
            'date_until'          => now()->toDateString(),
            'unit_price'          => 150,
            'clinical_indication' => 'H40.1',
        ]);
        $attached = BillingClaim::query()->where('schedule_id', $okSchedule->id)->firstOrFail();

        // Particular não é TISS; guia paga não é pendência.
        bkpiClaim($this->entity, $this->covenant);

        $response = $this->get(route('panel.financial.billing.index', ['tab' => 'claims']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('kpis.tiss_pending.count', 2));

        $rows = collect(bkpiProps($response)['claims']['data'])->keyBy('id');

        expect($rows[$individual->id]['out_of_batch'])->toBeTrue()
            ->and($rows[$individual->id]['has_pending_guide'])->toBeFalse()
            ->and($rows[$individual->id]['is_tiss'])->toBeTrue()
            ->and($rows[$blocked->id]['has_pending_guide'])->toBeTrue()
            ->and($rows[$blocked->id]['out_of_batch'])->toBeFalse()
            ->and($rows[$attached->id]['has_pending_guide'])->toBeFalse()
            ->and($rows[$attached->id]['out_of_batch'])->toBeFalse();

        $filtered = $this->get(route('panel.financial.billing.index', ['tab' => 'claims', 'claim_status' => 'tiss_pending']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.claim_status', 'tiss_pending')
                ->where('totals.claims', 2));

        expect(collect(bkpiProps($filtered)['claims']['data'])->pluck('id')->all())
            ->toEqualCanonicalizing([$individual->id, $blocked->id]);

        // Guia paga deixa de ser pendência.
        BillingClaim::query()->whereKey($individual->id)->update(['status' => BillingClaimStatus::Paid->value]);
        $this->get(route('panel.financial.billing.index'))
            ->assertInertia(fn ($page) => $page->where('kpis.tiss_pending.count', 1));
    });

    it('pendência TISS de outra clínica não entra', function (): void {
        $other = Entity::factory()->create(['is_client' => true, 'active' => true]);
        actingAsFinancialEntityUser($other);
        app(BillingService::class)->createIndividual([
            'schedule_id'         => createBillableSchedule($other)->id,
            'unit_price'          => 150,
            'clinical_indication' => 'H40.1',
        ]);

        actingAsFinancialEntityUser($this->entity);

        $this->get(route('panel.financial.billing.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('kpis.tiss_pending.count', 0)
                ->where('kpis.open.count', 0)
                ->has('claims.data', 0));
    });
});

describe('abas Guias e Lotes', function (): void {
    it('período (data do atendimento) e convênio também filtram guias e lotes', function (): void {
        $current = bkpiClaim($this->entity, $this->covenant);
        $old     = bkpiClaim($this->entity, $this->covenant, ['attendance_date' => now()->subMonths(3)->toDateString()]);

        $currentBatch = bkpiBatch($this->entity, $this->covenant);
        $oldBatch     = bkpiBatch($this->entity, $this->covenant, [
            'period_start' => now()->subMonths(3)->startOfMonth()->toDateString(),
            'period_end'   => now()->subMonths(3)->endOfMonth()->toDateString(),
        ]);

        $response = $this->get(route('panel.financial.billing.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('totals.claims', 1)->where('totals.batches', 1));

        expect(collect(bkpiProps($response)['claims']['data'])->pluck('id')->all())->toBe([$current->id])
            ->and(collect(bkpiProps($response)['batches']['data'])->pluck('id')->all())->toBe([$currentBatch->id]);

        $wide = $this->get(route('panel.financial.billing.index', [
            'from' => now()->subMonths(4)->toDateString(),
            'to'   => now()->toDateString(),
        ]));

        expect(collect(bkpiProps($wide)['claims']['data'])->pluck('id')->all())->toEqualCanonicalizing([$current->id, $old->id])
            ->and(collect(bkpiProps($wide)['batches']['data'])->pluck('id')->all())->toEqualCanonicalizing([$currentBatch->id, $oldBatch->id]);
    });

    it('lote filtrado (link do código) mostra guias e o lote independentemente do período', function (): void {
        $batch = bkpiBatch($this->entity, $this->covenant, [
            'period_start' => now()->subMonths(3)->startOfMonth()->toDateString(),
            'period_end'   => now()->subMonths(3)->endOfMonth()->toDateString(),
        ]);
        $inBatch = bkpiClaim($this->entity, $this->covenant, ['batch_id' => $batch->id, 'attendance_date' => now()->subMonths(3)->toDateString()]);
        bkpiClaim($this->entity, $this->covenant);

        $response = $this->get(route('panel.financial.billing.index', ['batch_id' => $batch->id, 'tab' => 'claims']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.batch_id', $batch->id)
                ->where('filters.batch_code', $batch->code)
                ->where('totals.claims', 1)
                ->where('totals.batches', 1));

        expect(collect(bkpiProps($response)['claims']['data'])->pluck('id')->all())->toBe([$inBatch->id])
            ->and(bkpiProps($response)['claims']['data'][0]['batch_code'])->toBe($batch->code);
    });

    it('lote de outra clínica é ignorado (sem filtro e sem vazar dado dela)', function (): void {
        $other         = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $theirCovenant = Covenant::factory()->create(['entity_id' => $other->id, 'active' => true]);
        $theirBatch    = bkpiBatch($other, $theirCovenant);
        bkpiClaim($other, $theirCovenant, ['batch_id' => $theirBatch->id]);
        $mine = bkpiClaim($this->entity, $this->covenant);

        $response = $this->get(route('panel.financial.billing.index', ['batch_id' => $theirBatch->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.batch_id', null)
                ->where('filters.batch_code', null));

        expect(collect(bkpiProps($response)['claims']['data'])->pluck('id')->all())->toBe([$mine->id])
            ->and(collect(bkpiProps($response)['batches']['data'])->pluck('id')->all())->not->toContain($theirBatch->id);
    });

    it('filtros inválidos (arrays, UUID malformado, data impossível) não viram erro 500', function (): void {
        $this->get(route('panel.financial.billing.index', [
            'from'         => '0000-01-01',
            'to'           => ['x'],
            'covenant_id'  => '<script>',
            'claim_status' => ['submitted'],
            'batch_id'     => 'nao-e-uuid',
            'tab'          => 'batches',
        ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.from', now()->startOfMonth()->toDateString())
                ->where('filters.to', now()->toDateString())
                ->where('filters.covenant_id', null)
                ->where('filters.claim_status', null)
                ->where('filters.batch_id', null)
                ->where('filters.tab', 'batches'));
    });

    it('expõe o preço da tabela, a busca CID do financeiro e os textos compartilhados', function (): void {
        $this->get(route('panel.financial.billing.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('cid10SearchUrl', route('panel.financial.cid10.search'))
                ->where('procedurePricesUrl', route('panel.financial.procedure-prices.index'))
                ->has('t.shared.period.presets')
                ->has('t.flow_steps.settle.title')
                ->where('claimStatuses', fn ($statuses) => collect($statuses)->contains('value', 'tiss_pending')));
    });
});
