<?php

declare(strict_types=1);

use App\Enums\{ClientRule, FeatureKey, SubscriptionStatus};
use App\Models\{Entity, EntityProduct, Plan, PlanFeature, Subscription, User};
use App\Services\Stock\StockService;

/**
 * Smoke test HTTP — App\Http\Controllers\Stock\StockReportsController.
 * Lógica de cálculo já coberta em tests/Unit/Stock/StockReportServiceTest.php;
 * aqui só confirma que a rota resolve, respeita a dupla trava (permission +
 * feature) e entrega os 4 blocos de dado esperados pro Inertia.
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

it('admin acessa os relatórios de estoque com os 4 blocos de dado', function () {
    $product = EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'Lente IOL', 'unit' => 'un', 'active' => true]);
    app(StockService::class)->manualIn($product, 10, 50.00);

    $res = $this->actingAs($this->admin)->withSession(panelSession($this->adminEntityUser))
        ->get(route('panel.stock.reports.index'));

    $res->assertOk();
    $res->assertInertia(fn ($page) => $page
        ->component('Panel/Stock/Reports/Index')
        ->has('valuedInventory.items', 1)
        ->has('turnover')
        ->has('consumptionByProcedure')
        ->has('purchasesBySupplier'));
});

it('[REGRA DE NEGÓCIO] clínica sem o módulo de estoque no plano recebe 403', function () {
    $entityNoModule = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $planNoModule   = Plan::factory()->create(['active' => true]);
    Subscription::factory()->create([
        'entity_id' => $entityNoModule->id, 'plan_id' => $planNoModule->id, 'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
    ]);
    $admin      = User::factory()->create();
    $entityUser = createEntityUser($entityNoModule, $admin, ClientRule::Admin->value);

    $this->actingAs($admin)->withSession(panelSession($entityUser))
        ->get(route('panel.stock.reports.index'), ['Accept' => 'application/json'])
        ->assertForbidden();
});
