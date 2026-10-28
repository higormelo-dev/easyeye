<?php

declare(strict_types=1);

use App\Enums\StockMovementType;
use App\Exceptions\{ExpiredLotException, StockReversalException};
use App\Models\{Entity, EntityProduct, StockMovement};
use App\Models\StockLot;
use App\Services\IolLensStockBridgeService;
use App\Services\Stock\{StockReportService, StockService};
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/*
 * Fase 4 / M4 — estoque pronto para rastrear implante: estorno de consumo
 * (ledger imutável, um por consumo), conferência de validade na baixa e o
 * produto de série/lote das lentes IOL.
 */
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));

    $this->entity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->stock   = app(StockService::class);
    $this->product = EntityProduct::create([
        'entity_id' => $this->entity->id, 'name' => 'OPM Lote', 'unit' => 'un', 'requires_lot' => true, 'active' => true,
    ]);
});

function srxLot(object $t, string $number, string $expiry, float $qty = 10): StockLot
{
    $lot = $t->stock->findOrCreateLot($t->product, $number, Carbon::parse($expiry));
    $t->stock->manualIn($t->product, $qty, 50.00, lot: $lot);

    return $lot->fresh();
}

// ── Validade na baixa ───────────────────────────────────────────────────────

it('recusa o consumo de lote vencido e não mexe em saldo nem no ledger', function () {
    $lot = srxLot($this, 'VENCIDO', '2026-10-14');

    expect(fn () => $this->stock->consumptionOut($this->product, 1, lot: $lot))->toThrow(ExpiredLotException::class);

    expect((float) $this->product->fresh()->qty_on_hand)->toBe(10.0)
        ->and((float) $lot->fresh()->qty_on_hand)->toBe(10.0)
        ->and(StockMovement::query()->where('type', StockMovementType::ConsumptionOut->value)->count())->toBe(0);
});

it('lote que vence HOJE ainda vale até o fim do dia', function () {
    $lot = srxLot($this, 'HOJE', '2026-10-15');

    $movement = $this->stock->consumptionOut($this->product, 1, lot: $lot);

    expect($movement->type)->toBe(StockMovementType::ConsumptionOut)->and((float) $lot->fresh()->qty_on_hand)->toBe(9.0);
});

it('validade editada retroativamente vale: lote corrigido para vencido é recusado', function () {
    $lot = srxLot($this, 'EDITADO', '2027-01-01');
    $lot->update(['expiry_date' => '2026-01-01']);

    expect(fn () => $this->stock->consumptionOut($this->product, 1, lot: $lot->fresh()))->toThrow(ExpiredLotException::class);
});

it('perda, devolução e ajuste de lote vencido continuam permitidos (é o que se faz com ele)', function () {
    $lot = srxLot($this, 'VENCIDO', '2026-10-01');

    $this->stock->loss($this->product, 2, lot: $lot);
    $this->stock->returnOut($this->product, 1, lot: $lot);
    $this->stock->adjustToCountedQuantity($this->product, 5, lot: $lot);

    expect((float) $lot->fresh()->qty_on_hand)->toBe(5.0);
});

it('lote sem validade cadastrada não é barrado pelo serviço (a cirurgia é quem exige validade)', function () {
    $lot = $this->stock->findOrCreateLot($this->product, 'SEM-VALIDADE');
    $this->stock->manualIn($this->product, 3, 10.0, lot: $lot);

    $movement = $this->stock->consumptionOut($this->product, 1, lot: $lot);

    expect($movement->exists)->toBeTrue();
});

// ── Estorno ─────────────────────────────────────────────────────────────────

it('estorna o consumo: devolve saldo do produto e do lote e aponta para o consumo', function () {
    $lot         = srxLot($this, 'L1', '2027-01-01');
    $consumption = $this->stock->consumptionOut($this->product, 3, lot: $lot);

    $reversal = $this->stock->reverseConsumption($consumption, 'estorno');

    expect($reversal->type)->toBe(StockMovementType::ConsumptionReversal)
        ->and($reversal->reversal_of_id)->toBe($consumption->id)
        ->and((float) $reversal->quantity)->toBe(3.0)
        ->and((string) $reversal->stock_lot_id)->toBe((string) $lot->id)
        ->and((float) $this->product->fresh()->qty_on_hand)->toBe(10.0)
        ->and((float) $lot->fresh()->qty_on_hand)->toBe(10.0)
        ->and((float) $reversal->balance_after)->toBe(10.0);

    // O consumo original continua intacto no ledger.
    expect($consumption->fresh()->type)->toBe(StockMovementType::ConsumptionOut);
});

/** Dois lotes do mesmo produto com custos diferentes: o custo médio do PRODUTO (150) não é o de nenhum lote. */
function srxPricedLots(object $t): array
{
    $cheap = $t->stock->findOrCreateLot($t->product, 'BARATO', Carbon::parse('2027-01-01'));
    $dear  = $t->stock->findOrCreateLot($t->product, 'CARO', Carbon::parse('2027-01-01'));

    $t->stock->purchaseIn($t->product, 10, 100.00, lot: $cheap);
    $t->stock->purchaseIn($t->product, 10, 200.00, lot: $dear);

    return [$cheap->fresh(), $dear->fresh()];
}

it('estornar consumo não altera o custo médio do lote (só o do produto entra pelo custo do consumo)', function () {
    [$cheap, $dear] = srxPricedLots($this);

    expect((float) $this->product->fresh()->cost_avg)->toBe(150.0);

    $consumption = $this->stock->consumptionOut($this->product, 1, lot: $cheap);

    // O consumo grava o custo médio do PRODUTO, não o do lote.
    expect((float) $consumption->unit_cost)->toBe(150.0)
        ->and((float) $cheap->fresh()->cost_avg)->toBe(100.0);

    $reversal = $this->stock->reverseConsumption($consumption);

    expect((float) $cheap->fresh()->cost_avg)->toBe(100.0)             // antes do conserto: 105
        ->and((float) $cheap->fresh()->qty_on_hand)->toBe(10.0)
        ->and((float) $dear->fresh()->cost_avg)->toBe(200.0)
        ->and((float) $this->product->fresh()->cost_avg)->toBe(150.0)
        ->and((float) $this->product->fresh()->qty_on_hand)->toBe(20.0)
        ->and((float) $reversal->unit_cost)->toBe(150.0);
});

it('lote zerado e estornado volta ao custo dele, não ao custo médio do produto', function () {
    [$cheap] = srxPricedLots($this);

    $consumption = $this->stock->consumptionOut($this->product, 10, lot: $cheap);

    expect((float) $cheap->fresh()->qty_on_hand)->toBe(0.0);

    $this->stock->reverseConsumption($consumption);

    expect((float) $cheap->fresh()->cost_avg)->toBe(100.0)              // antes do conserto: 150
        ->and((float) $cheap->fresh()->qty_on_hand)->toBe(10.0);
});

it('compra no mesmo lote entre o consumo e o estorno: o estorno também não mexe na média do lote', function () {
    [$cheap] = srxPricedLots($this);

    $consumption = $this->stock->consumptionOut($this->product, 2, lot: $cheap);
    $this->stock->purchaseIn($this->product, 10, 120.00, lot: $cheap);

    $before = (float) $cheap->fresh()->cost_avg;

    expect($before)->toBe(111.1111);                                    // (8 × 100 + 10 × 120) ÷ 18

    $this->stock->reverseConsumption($consumption);

    expect((float) $cheap->fresh()->cost_avg)->toBe($before)
        ->and((float) $cheap->fresh()->qty_on_hand)->toBe(20.0);
});

it('entrada com custo de aquisição continua ponderando o lote (só o estorno é neutro)', function () {
    [$cheap] = srxPricedLots($this);

    $this->stock->manualIn($this->product, 10, 200.00, lot: $cheap);    // 10 × 100 + 10 × 200

    expect((float) $cheap->fresh()->cost_avg)->toBe(150.0);
});

it('estorna mesmo com o lote vencido depois do consumo (a unidade volta ao lote)', function () {
    $lot         = srxLot($this, 'L2', '2026-10-20');
    $consumption = $this->stock->consumptionOut($this->product, 1, lot: $lot);

    $this->travelTo(Carbon::parse('2026-11-01 09:00:00'));

    $reversal = $this->stock->reverseConsumption($consumption);

    expect($reversal->type)->toBe(StockMovementType::ConsumptionReversal)->and((float) $lot->fresh()->qty_on_hand)->toBe(10.0);
});

it('é idempotente: estornar de novo devolve o mesmo estorno e não mexe no saldo', function () {
    $lot         = srxLot($this, 'L3', '2027-01-01');
    $consumption = $this->stock->consumptionOut($this->product, 2, lot: $lot);

    $first  = $this->stock->reverseConsumption($consumption);
    $second = $this->stock->reverseConsumption($consumption);

    expect($second->id)->toBe($first->id)
        ->and((float) $this->product->fresh()->qty_on_hand)->toBe(10.0)
        ->and(StockMovement::query()->where('type', StockMovementType::ConsumptionReversal->value)->count())->toBe(1);
});

it('o banco recusa um segundo estorno do mesmo consumo (índice único)', function () {
    $lot         = srxLot($this, 'L4', '2027-01-01');
    $consumption = $this->stock->consumptionOut($this->product, 1, lot: $lot);
    $first       = $this->stock->reverseConsumption($consumption);

    $duplicate     = $first->replicate();
    $duplicate->id = (string) Str::uuid();

    expect(fn () => $duplicate->save())->toThrow(QueryException::class);
});

it('recusa estornar o que não é consumo', function () {
    $lot  = srxLot($this, 'L5', '2027-01-01');
    $loss = $this->stock->loss($this->product, 1, lot: $lot);

    expect(fn () => $this->stock->reverseConsumption($loss))->toThrow(StockReversalException::class);
    expect((float) $lot->fresh()->qty_on_hand)->toBe(9.0);
});

it('recusa estornar consumo de outra clínica quando o chamador informa a sua', function () {
    $lot         = srxLot($this, 'L6', '2027-01-01');
    $consumption = $this->stock->consumptionOut($this->product, 1, lot: $lot);
    $other       = Entity::factory()->create(['is_client' => true, 'active' => true]);

    expect(fn () => $this->stock->reverseConsumption($consumption, entityId: (string) $other->id))->toThrow(StockReversalException::class);
    expect(StockMovement::query()->where('type', StockMovementType::ConsumptionReversal->value)->count())->toBe(0);
});

it('o estorno não é lançamento manual', function () {
    expect(StockMovementType::manualTypes())->not->toContain(StockMovementType::ConsumptionReversal);
    expect(StockMovementType::ConsumptionReversal->direction())->toBe(1);

    expect(fn () => $this->stock->manual($this->product, StockMovementType::ConsumptionReversal, 1, lot: srxLot($this, 'L7', '2027-01-01')))
        ->toThrow(InvalidArgumentException::class);
});

it('o estorno devolve ao custo médio ponderado do consumo (custo do lote preservado)', function () {
    $lot         = srxLot($this, 'L8', '2027-01-01');
    $consumption = $this->stock->consumptionOut($this->product, 4, lot: $lot);

    $this->stock->reverseConsumption($consumption);

    expect((float) $this->product->fresh()->cost_avg)->toBe(50.0)->and((float) $lot->fresh()->cost_avg)->toBe(50.0);
});

// ── Lente IOL: lote e série ─────────────────────────────────────────────────

it('lente IOL nova nasce com lote e série obrigatórios', function () {
    $lens = app(IolLensStockBridgeService::class)->create((string) $this->entity->id, ['model_name' => 'LIO Nova', 'manufacturer' => 'ACME']);

    expect($lens->entityProduct->requires_lot)->toBeTrue()->and($lens->entityProduct->requires_serial)->toBeTrue();
});

it('backfill: lente existente marca série sempre e lote só quando não há saldo fora de lote', function () {
    $service = app(IolLensStockBridgeService::class);

    $empty    = $service->create((string) $this->entity->id, ['model_name' => 'Sem saldo']);
    $lotted   = $service->create((string) $this->entity->id, ['model_name' => 'Com lote']);
    $unlotted = $service->create((string) $this->entity->id, ['model_name' => 'Saldo solto']);

    $lot = $this->stock->findOrCreateLot($lotted->entityProduct, 'LL', Carbon::parse('2027-01-01'));
    $this->stock->manualIn($lotted->entityProduct, 4, 10.0, lot: $lot);

    // Saldo "sem lote": cenário legado (antes de a lente exigir lote).
    EntityProduct::query()->whereKey($unlotted->entity_product_id)->update(['requires_lot' => false, 'qty_on_hand' => 7]);

    EntityProduct::query()->whereIn('id', [$empty->entity_product_id, $lotted->entity_product_id, $unlotted->entity_product_id])
        ->update(['requires_lot' => false, 'requires_serial' => false]);

    (include database_path('migrations/2026_10_29_000000_add_serial_and_reversal_to_stock.php'))->up();

    $flags = fn ($lens) => EntityProduct::query()->find($lens->entity_product_id)->only(['requires_lot', 'requires_serial']);

    expect($flags($empty))->toBe(['requires_lot' => true, 'requires_serial' => true])
        ->and($flags($lotted))->toBe(['requires_lot' => true, 'requires_serial' => true])
        // Saldo sem lote: exigir lote travaria o consumo — só a série é marcada.
        ->and($flags($unlotted))->toBe(['requires_lot' => false, 'requires_serial' => true]);
});

it('o extrato lista o tipo estorno com rótulo traduzido', function () {
    expect(StockMovementType::ConsumptionReversal->label())->toBe(__('stock_enums.movement_types.consumption_reversal'))
        ->and(StockMovementType::ConsumptionReversal->label())->not->toContain('stock_enums');
});

it('o giro de estoque abate o consumo estornado', function () {
    $lot = srxLot($this, 'L9', '2027-01-01');
    $c1  = $this->stock->consumptionOut($this->product, 4, lot: $lot);
    $this->stock->consumptionOut($this->product, 1, lot: $lot);
    $this->stock->reverseConsumption($c1);

    $rows = app(StockReportService::class)->turnoverByProduct((string) $this->entity->id, '2026-10-01', '2026-10-31');
    $row  = collect($rows)->firstWhere('id', $this->product->id);

    expect($row['qty_out'])->toBe(1.0);
});
