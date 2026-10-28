<?php

declare(strict_types=1);

use App\Enums\{ClientRule, FeatureKey, SubscriptionStatus};
use App\Models\{Entity, EntityProduct, Plan, PlanFeature, Subscription, User};
use App\Services\Stock\StockService;

/**
 * Metadado de lotes — App\Http\Controllers\Stock\ProductLotsController.
 * Saldo/custo do lote (qty_on_hand/cost_avg) é sempre coberto em
 * StockServiceTest/StockMovementsTest; aqui só a edição de metadado
 * (número/validade/ativo) + isolamento.
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
        'entity_id' => $this->entity->id, 'name' => 'OPM Lote', 'unit' => 'un', 'requires_lot' => true, 'active' => true,
    ]);

    $this->lot = app(StockService::class)->findOrCreateLot($this->product, 'LOTE-001', now()->addMonths(3));
    app(StockService::class)->manualIn($this->product, 10, 50.00, lot: $this->lot);
});

function actingAsLotAdmin($test, ?User $admin = null, $entityUser = null)
{
    return $test->actingAs($admin ?? $test->admin)
        ->withSession(panelSession($entityUser ?? $test->adminEntityUser));
}

it('lista os lotes de um produto com saldo/status', function () {
    $res = actingAsLotAdmin($this)->getJson(route('panel.stock.products.lots.index', $this->product->id));

    $res->assertOk();
    $data = $res->json('data');
    // json_encode() de um float "redondo" (10.0) emite `10` (sem
    // JSON_PRESERVE_ZERO_FRACTION) — json_decode() volta como int, não
    // float. `toBe` é comparação estrita (===), por isso o cast explícito
    // aqui (mesmo padrão usado em toda asserção numérica desta suíte).
    expect($data)->toHaveCount(1)
        ->and($data[0]['lot_number'])->toBe('LOTE-001')
        ->and((float) $data[0]['qty_on_hand'])->toBe(10.0)
        ->and($data[0]['is_expired'])->toBeFalse();
});

it('admin edita número/validade/ativo do lote', function () {
    $res = actingAsLotAdmin($this)->putJson(route('panel.stock.lots.update', $this->lot->id), [
        'lot_number'  => 'LOTE-001-CORRIGIDO',
        'expiry_date' => now()->addYear()->toDateString(),
        'active'      => false,
    ]);

    $res->assertOk();

    $fresh = $this->lot->fresh();
    expect($fresh->lot_number)->toBe('LOTE-001-CORRIGIDO')
        ->and($fresh->active)->toBeFalse()
        // saldo intocado — update() só mexe em metadado.
        ->and((float) $fresh->qty_on_hand)->toBe(10.0);
});

it('não aceita qty_on_hand/cost_avg via mass-assignment na edição de metadado', function () {
    actingAsLotAdmin($this)->putJson(route('panel.stock.lots.update', $this->lot->id), [
        'lot_number'  => 'LOTE-001',
        'qty_on_hand' => 9999,
        'cost_avg'    => 1,
    ])->assertOk();

    expect((float) $this->lot->fresh()->qty_on_hand)->toBe(10.0);
});

it('rejeita número de lote duplicado no mesmo produto', function () {
    $otherLot = app(StockService::class)->findOrCreateLot($this->product, 'LOTE-002', now()->addYear());

    actingAsLotAdmin($this)->putJson(route('panel.stock.lots.update', $otherLot->id), [
        'lot_number' => 'LOTE-001', // já existe no mesmo produto
    ])->assertStatus(422)->assertJsonValidationErrors('lot_number');
});

it('[ISOLAMENTO] admin de outra clínica recebe 404 ao listar lotes de produto alheio', function () {
    $otherEntity     = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $otherAdmin      = User::factory()->create();
    $otherEntityUser = createEntityUser($otherEntity, $otherAdmin, ClientRule::Admin->value);

    actingAsLotAdmin($this, $otherAdmin, $otherEntityUser)
        ->getJson(route('panel.stock.products.lots.index', $this->product->id))
        ->assertStatus(404);
});

it('[ISOLAMENTO] admin de outra clínica recebe 404 ao editar lote alheio', function () {
    $otherEntity     = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $otherAdmin      = User::factory()->create();
    $otherEntityUser = createEntityUser($otherEntity, $otherAdmin, ClientRule::Admin->value);

    actingAsLotAdmin($this, $otherAdmin, $otherEntityUser)
        ->putJson(route('panel.stock.lots.update', $this->lot->id), ['lot_number' => 'Hackeado'])
        ->assertStatus(404);

    expect($this->lot->fresh()->lot_number)->toBe('LOTE-001');
});
