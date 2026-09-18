<?php

declare(strict_types=1);

use App\Enums\{ClientRule, FeatureKey, StockMovementType, SubscriptionStatus};
use App\Models\{Entity, EntityProduct, Plan, PlanFeature, StockMovement, Subscription, User};
use App\Services\Stock\StockService;

/**
 * Contagem física de estoque em massa (GAP fechado — revisão pós-Fase 4,
 * "melhorar o módulo de estoque") —
 * App\Http\Controllers\Stock\StockCountsController.
 */
beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->plan   = Plan::factory()->create(['active' => true]);
    PlanFeature::factory()->enabled(FeatureKey::HasInventoryModule)->for($this->plan)->create();
    Subscription::factory()->create([
        'entity_id' => $this->entity->id, 'plan_id' => $this->plan->id, 'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
    ]);

    $this->admin           = User::factory()->create();
    $this->adminEntityUser = createEntityUser($this->entity, $this->admin, ClientRule::Admin->value);
});

function actingAsCountAdmin($test, ?User $admin = null, $entityUser = null)
{
    return $test->actingAs($admin ?? $test->admin)->withSession(panelSession($entityUser ?? $test->adminEntityUser));
}

it('index() lista produtos ativos com saldo atual', function () {
    EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'Lente IOL', 'unit' => 'un', 'active' => true]);
    EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'Inativo', 'unit' => 'un', 'active' => false]);

    $res = actingAsCountAdmin($this)->get(route('panel.stock.counts.index'));

    $res->assertOk();
    $res->assertInertia(fn ($page) => $page
        ->component('Panel/Stock/Counts/Index')
        ->has('products', 1)
        ->where('products.0.name', 'Lente IOL'));
});

it('[GAP] store() aplica contagem MAIOR que o saldo — gera adjustment_in', function () {
    $product = EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'Lente IOL', 'unit' => 'un', 'active' => true]);
    app(StockService::class)->manualIn($product, 10, 50.00); // saldo=10

    $res = actingAsCountAdmin($this)->postJson(route('panel.stock.counts.store'), [
        'items' => [['entity_product_id' => $product->id, 'counted_qty' => 15]],
    ]);

    $res->assertOk();
    expect($res->json('variances'))->toHaveCount(1)
        ->and($res->json('variances.0.delta'))->toBe(5); // json_encode() de float redondo perde a casa decimal

    $product->refresh();
    expect((float) $product->qty_on_hand)->toBe(15.0);

    $movement = StockMovement::where('entity_product_id', $product->id)->latest('id')->first();
    expect($movement->type)->toBe(StockMovementType::AdjustmentIn);
});

it('[GAP] store() aplica contagem MENOR que o saldo — gera adjustment_out', function () {
    $product = EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'Lente IOL', 'unit' => 'un', 'active' => true]);
    app(StockService::class)->manualIn($product, 10, 50.00);

    $res = actingAsCountAdmin($this)->postJson(route('panel.stock.counts.store'), [
        'items' => [['entity_product_id' => $product->id, 'counted_qty' => 6]],
    ]);

    $res->assertOk();
    expect($res->json('variances.0.delta'))->toBe(-4); // json_encode() de float redondo perde a casa decimal

    $movement = StockMovement::where('entity_product_id', $product->id)->latest('id')->first();
    expect($movement->type)->toBe(StockMovementType::AdjustmentOut);
});

it('[GAP] store() com contagem IGUAL ao saldo não gera movimentação nenhuma (não entra na lista de divergência)', function () {
    $product = EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'Lente IOL', 'unit' => 'un', 'active' => true]);
    app(StockService::class)->manualIn($product, 10, 50.00);

    $res = actingAsCountAdmin($this)->postJson(route('panel.stock.counts.store'), [
        'items' => [['entity_product_id' => $product->id, 'counted_qty' => 10]],
    ]);

    $res->assertOk();
    expect($res->json('variances'))->toBe([]);
    expect(StockMovement::where('entity_product_id', $product->id)->count())->toBe(1); // só a entrada original
});

it('[GAP] store() aplica VÁRIOS produtos de uma vez, cada um com seu próprio delta', function () {
    $productA = EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'A', 'unit' => 'un', 'active' => true]);
    $productB = EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'B', 'unit' => 'un', 'active' => true]);
    app(StockService::class)->manualIn($productA, 5, 10.00);
    app(StockService::class)->manualIn($productB, 20, 10.00);

    $res = actingAsCountAdmin($this)->postJson(route('panel.stock.counts.store'), [
        'items' => [
            ['entity_product_id' => $productA->id, 'counted_qty' => 8],
            ['entity_product_id' => $productB->id, 'counted_qty' => 20], // bate — não entra na lista
        ],
    ]);

    $res->assertOk();
    expect($res->json('variances'))->toHaveCount(1)
        ->and($res->json('variances.0.product_name'))->toBe('A');
});

it('[GAP][SEGURANÇA] produto de OUTRA clínica no payload retorna 422 — nunca ajusta saldo alheio', function () {
    $otherEntity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $otherProduct = EntityProduct::create(['entity_id' => $otherEntity->id, 'name' => 'De outra clínica', 'unit' => 'un', 'active' => true]);
    app(StockService::class)->manualIn($otherProduct, 10, 50.00);

    actingAsCountAdmin($this)->postJson(route('panel.stock.counts.store'), [
        'items' => [['entity_product_id' => $otherProduct->id, 'counted_qty' => 999]],
    ])->assertStatus(422)->assertJsonValidationErrors('items.0.entity_product_id');

    expect((float) $otherProduct->fresh()->qty_on_hand)->toBe(10.0);
});

it('[REGRA DE NEGÓCIO] clínica sem o módulo de estoque no plano recebe 403', function () {
    $entityNoModule = entityWithoutInventoryModule();
    $admin          = User::factory()->create();
    $entityUser     = createEntityUser($entityNoModule, $admin, ClientRule::Admin->value);

    $this->actingAs($admin)->withSession(panelSession($entityUser))
        ->get(route('panel.stock.counts.index'), ['Accept' => 'application/json'])
        ->assertForbidden();
});
