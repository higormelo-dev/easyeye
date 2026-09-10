<?php

declare(strict_types=1);

use App\Enums\{ClientRule, FeatureKey, SubscriptionStatus};
use App\Models\{Entity, EntityProduct, Plan, PlanFeature, StockLot, StockMovement, Subscription, User};
use App\Services\Stock\StockService;

/**
 * Lançamento manual + extrato de movimentação — App\Http\Controllers\Stock\
 * StockMovementsController (App\Services\Stock\StockService por trás).
 */
beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->plan   = Plan::factory()->create(['active' => true]);

    PlanFeature::factory()->enabled(FeatureKey::HasInventoryModule)->for($this->plan)->create();

    Subscription::factory()->create([
        'entity_id' => $this->entity->id,
        'plan_id'   => $this->plan->id,
        'status'    => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(),
        'ends_at'   => now()->addMonth(),
    ]);

    $this->admin           = User::factory()->create();
    $this->adminEntityUser = createEntityUser($this->entity, $this->admin, ClientRule::Admin->value);

    $this->product = EntityProduct::create([
        'entity_id' => $this->entity->id,
        'name'      => 'Colírio Diclofenaco 0,1%',
        'unit'      => 'un',
        'min_qty'   => 5,
        'active'    => true,
    ]);
});

function actingAsMovementAdmin($test, ?User $admin = null, $entityUser = null)
{
    return $test->actingAs($admin ?? $test->admin)
        ->withSession(panelSession($entityUser ?? $test->adminEntityUser));
}

it('entrada manual aumenta o saldo e recalcula custo médio ponderado', function () {
    actingAsMovementAdmin($this)
        ->post(route('panel.stock.movements.store'), [
            'entity_product_id' => $this->product->id,
            'type'              => 'manual_in',
            'quantity'          => 10,
            'unit_cost'         => 5.00,
        ], ['Accept' => 'application/json'])
        ->assertRedirect(route('panel.stock.movements.index'));

    $product = $this->product->fresh();
    expect((float) $product->qty_on_hand)->toBe(10.0)
        ->and((float) $product->cost_avg)->toBe(5.0);

    // segunda entrada com custo diferente — média ponderada: (10*5 + 10*7)/20 = 6
    actingAsMovementAdmin($this)
        ->post(route('panel.stock.movements.store'), [
            'entity_product_id' => $this->product->id,
            'type'              => 'manual_in',
            'quantity'          => 10,
            'unit_cost'         => 7.00,
        ], ['Accept' => 'application/json']);

    $product = $this->product->fresh();
    expect((float) $product->qty_on_hand)->toBe(20.0)
        ->and((float) $product->cost_avg)->toBe(6.0);
});

it('saída manual reduz o saldo sem alterar o custo médio', function () {
    // qty_on_hand/cost_avg NÃO são fillable (ver doc de EntityProduct) — um
    // ->update() mass-assignment neles seria ignorado em silêncio. Seed via
    // StockService, mesmo caminho que o app usa de verdade.
    $seed = app(StockService::class)->manualIn($this->product, 20, 6.00);

    actingAsMovementAdmin($this)
        ->post(route('panel.stock.movements.store'), [
            'entity_product_id' => $this->product->id,
            'type'              => 'manual_out',
            'quantity'          => 8,
        ], ['Accept' => 'application/json'])
        ->assertRedirect(route('panel.stock.movements.index'));

    $product = $this->product->fresh();
    expect((float) $product->qty_on_hand)->toBe(12.0)
        ->and((float) $product->cost_avg)->toBe(6.0);

    // `created_at` tem resolução de segundo — seed e saída podem cair no
    // mesmo timestamp, então "latest('created_at')" não desempata de forma
    // confiável. Filtra pelo id da seed (conhecido) em vez de ordenar.
    $movement = StockMovement::query()
        ->where('entity_product_id', $this->product->id)
        ->whereKeyNot($seed->id)
        ->first();
    expect((float) $movement->balance_after)->toBe(12.0)
        ->and((float) $movement->unit_cost)->toBe(6.0); // snapshot do custo médio vigente
});

it('[REGRA DE NEGÓCIO] saída maior que o saldo é bloqueada (422), saldo não muda', function () {
    app(StockService::class)->manualIn($this->product, 5, 6.00);

    actingAsMovementAdmin($this)
        ->post(route('panel.stock.movements.store'), [
            'entity_product_id' => $this->product->id,
            'type'              => 'manual_out',
            'quantity'          => 100,
        ], ['Accept' => 'application/json'])
        ->assertStatus(422);

    expect((float) $this->product->fresh()->qty_on_hand)->toBe(5.0);
});

it('purchase_in e consumption_out são rejeitados no lançamento manual (só nascem de compra/procedimento)', function () {
    foreach (['purchase_in', 'consumption_out'] as $type) {
        actingAsMovementAdmin($this)
            ->post(route('panel.stock.movements.store'), [
                'entity_product_id' => $this->product->id,
                'type'              => $type,
                'quantity'          => 1,
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
    }

    expect((float) $this->product->fresh()->qty_on_hand)->toBe(0.0);
});

it('quantidade zero ou negativa é rejeitada na validação', function () {
    actingAsMovementAdmin($this)
        ->post(route('panel.stock.movements.store'), [
            'entity_product_id' => $this->product->id,
            'type'              => 'manual_in',
            'quantity'          => 0,
        ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('quantity');
});

it('[ISOLAMENTO] admin de outra clínica não lança movimentação em produto alheio (404)', function () {
    $otherEntity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $otherPlan   = Plan::factory()->create(['active' => true]);
    PlanFeature::factory()->enabled(FeatureKey::HasInventoryModule)->for($otherPlan)->create();
    Subscription::factory()->create([
        'entity_id' => $otherEntity->id,
        'plan_id'   => $otherPlan->id,
        'status'    => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(),
        'ends_at'   => now()->addMonth(),
    ]);
    $otherAdmin      = User::factory()->create();
    $otherEntityUser = createEntityUser($otherEntity, $otherAdmin, ClientRule::Admin->value);

    actingAsMovementAdmin($this, $otherAdmin, $otherEntityUser)
        ->post(route('panel.stock.movements.store'), [
            'entity_product_id' => $this->product->id,
            'type'              => 'manual_in',
            'quantity'          => 10,
        ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('entity_product_id');

    expect((float) $this->product->fresh()->qty_on_hand)->toBe(0.0);
});

// ── Fase 2: lote/validade via HTTP ──────────────────────────────────────

it('entrada com new_lot_number cria o lote e vincula a movimentação a ele', function () {
    $lotProduct = EntityProduct::create([
        'entity_id' => $this->entity->id, 'name' => 'OPM Lote', 'unit' => 'un', 'requires_lot' => true, 'active' => true,
    ]);

    actingAsMovementAdmin($this)
        ->post(route('panel.stock.movements.store'), [
            'entity_product_id'   => $lotProduct->id,
            'type'                => 'manual_in',
            'quantity'            => 5,
            'unit_cost'           => 300.00,
            'new_lot_number'      => 'LOTE-XPTO-01',
            'new_lot_expiry_date' => now()->addYear()->toDateString(),
        ], ['Accept' => 'application/json'])
        ->assertRedirect(route('panel.stock.movements.index'));

    $lot = StockLot::query()->where('entity_product_id', $lotProduct->id)->where('lot_number', 'LOTE-XPTO-01')->first();
    expect($lot)->not->toBeNull()
        ->and((float) $lot->qty_on_hand)->toBe(5.0)
        ->and((float) $lotProduct->fresh()->qty_on_hand)->toBe(5.0);

    $movement = StockMovement::query()->where('entity_product_id', $lotProduct->id)->first();
    expect((string) $movement->stock_lot_id)->toBe((string) $lot->id);
});

it('[REGRA DE NEGÓCIO] produto requires_lot=true sem lote informado é rejeitado (422)', function () {
    $lotProduct = EntityProduct::create([
        'entity_id' => $this->entity->id, 'name' => 'OPM Lote', 'unit' => 'un', 'requires_lot' => true, 'active' => true,
    ]);

    actingAsMovementAdmin($this)
        ->post(route('panel.stock.movements.store'), [
            'entity_product_id' => $lotProduct->id,
            'type'              => 'manual_in',
            'quantity'          => 5,
            'unit_cost'         => 100.00,
        ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('stock_lot_id');
});

it('não deixa escolher lote existente E informar lote novo ao mesmo tempo', function () {
    $lotProduct = EntityProduct::create([
        'entity_id' => $this->entity->id, 'name' => 'OPM Lote', 'unit' => 'un', 'requires_lot' => true, 'active' => true,
    ]);
    $lot = app(StockService::class)->findOrCreateLot($lotProduct, 'LOTE-JA-EXISTE', now()->addYear());

    actingAsMovementAdmin($this)
        ->post(route('panel.stock.movements.store'), [
            'entity_product_id' => $lotProduct->id,
            'type'              => 'manual_in',
            'quantity'          => 5,
            'stock_lot_id'      => $lot->id,
            'new_lot_number'    => 'LOTE-OUTRO',
        ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('stock_lot_id');
});

it('não permite criar lote novo numa saída — saída exige lote EXISTENTE', function () {
    $lotProduct = EntityProduct::create([
        'entity_id' => $this->entity->id, 'name' => 'OPM Lote', 'unit' => 'un', 'requires_lot' => true, 'active' => true,
    ]);

    actingAsMovementAdmin($this)
        ->post(route('panel.stock.movements.store'), [
            'entity_product_id' => $lotProduct->id,
            'type'              => 'manual_out',
            'quantity'          => 5,
            'new_lot_number'    => 'LOTE-INVENTADO',
        ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('new_lot_number');
});

it('[ISOLAMENTO] stock_lot_id de outra clínica é rejeitado (exists rule escopada por entity_id)', function () {
    $lotProduct = EntityProduct::create([
        'entity_id' => $this->entity->id, 'name' => 'OPM Lote', 'unit' => 'un', 'requires_lot' => true, 'active' => true,
    ]);

    $otherEntity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $otherProduct = EntityProduct::create([
        'entity_id' => $otherEntity->id, 'name' => 'OPM de outra clínica', 'unit' => 'un', 'requires_lot' => true, 'active' => true,
    ]);
    $foreignLot = app(StockService::class)->findOrCreateLot($otherProduct, 'LOTE-ALHEIO', now()->addYear());

    actingAsMovementAdmin($this)
        ->post(route('panel.stock.movements.store'), [
            'entity_product_id' => $lotProduct->id,
            'type'              => 'manual_out',
            'quantity'          => 1,
            'stock_lot_id'      => $foreignLot->id,
        ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('stock_lot_id');
});
