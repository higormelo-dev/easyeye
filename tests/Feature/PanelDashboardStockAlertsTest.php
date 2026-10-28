<?php

declare(strict_types=1);

use App\Enums\{ClientRule, FeatureKey, Permission, SubscriptionStatus};
use App\Models\{Entity, EntityProduct, PermissionRecord, Plan, PlanFeature, Role, Subscription, User};
use App\Services\Stock\StockService;
use Database\Seeders\PermissionsSeeder;

/**
 * GAP fechado (revisão pós-Fase 4 do módulo de estoque): existia alerta de
 * estoque baixo/lote vencendo (Notice + StockAlertService), mas nenhuma
 * presença no Dashboard — App\Http\Controllers\PanelDashboardController
 * nunca tinha sido testado antes (nenhum teste pra este controller até
 * esta revisão).
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

it('[GAP] admin com produto abaixo do mínimo vê stockAlerts populado no Dashboard', function () {
    $product = EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'Lente IOL', 'unit' => 'un', 'active' => true, 'min_qty' => 10]);
    app(StockService::class)->manualIn($product, 4, 50.00); // saldo=4, abaixo do mínimo (10)

    $res = $this->actingAs($this->admin)->withSession(panelSession($this->adminEntityUser))
        ->get(route('panel.dashboard'));

    $res->assertOk();
    $res->assertInertia(fn ($page) => $page
        ->component('Panel/Dashboard')
        ->where('stockAlerts.below_minimum_count', 1)
        ->where('stockAlerts.expiring_lots_count', 0));
});

it('[GAP] clínica sem nada crítico no estoque recebe stockAlerts null (sem card vazio)', function () {
    EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'Lente IOL', 'unit' => 'un', 'active' => true]);

    $res = $this->actingAs($this->admin)->withSession(panelSession($this->adminEntityUser))
        ->get(route('panel.dashboard'));

    $res->assertOk();
    $res->assertInertia(fn ($page) => $page->where('stockAlerts', null));
});

it('[GAP] clínica sem o módulo de estoque no plano recebe stockAlerts null mesmo com produto abaixo do mínimo', function () {
    $entityNoModule = entityWithoutInventoryModule();
    $product        = EntityProduct::create(['entity_id' => $entityNoModule->id, 'name' => 'Lente IOL', 'unit' => 'un', 'active' => true, 'min_qty' => 10]);
    app(StockService::class)->manualIn($product, 4, 50.00);

    $admin      = User::factory()->create();
    $entityUser = createEntityUser($entityNoModule, $admin, ClientRule::Admin->value);

    $res = $this->actingAs($admin)->withSession(panelSession($entityUser))->get(route('panel.dashboard'));

    $res->assertOk();
    $res->assertInertia(fn ($page) => $page->where('stockAlerts', null));
});

it('[GAP] secretária SEM Role customizada (sem stock.manage) recebe stockAlerts null mesmo com produto abaixo do mínimo', function () {
    $product = EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'Lente IOL', 'unit' => 'un', 'active' => true, 'min_qty' => 10]);
    app(StockService::class)->manualIn($product, 4, 50.00);

    $secretary           = User::factory()->create();
    $secretaryEntityUser = createEntityUser($this->entity, $secretary, ClientRule::Secretary->value);

    $res = $this->actingAs($secretary)->withSession(panelSession($secretaryEntityUser))->get(route('panel.dashboard'));

    $res->assertOk();
    $res->assertInertia(fn ($page) => $page->where('stockAlerts', null));
});

it('[GAP] secretária COM Role customizada (stock.manage) vê stockAlerts populado', function () {
    $this->seed(PermissionsSeeder::class);

    $product = EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'Lente IOL', 'unit' => 'un', 'active' => true, 'min_qty' => 10]);
    app(StockService::class)->manualIn($product, 4, 50.00);

    $secretary           = User::factory()->create();
    $secretaryEntityUser = createEntityUser($this->entity, $secretary, ClientRule::Secretary->value);

    $stockPermission = PermissionRecord::where('key', Permission::StockManage->value)->firstOrFail();
    $role            = Role::query()->create(['entity_id' => $this->entity->id, 'name' => 'Gestor de Estoque']);
    $role->permissions()->sync([$stockPermission->id]);
    $secretaryEntityUser->roles()->sync([$role->id]);

    // hasPermissionInEntity() cacheia por instância de User — busca um User
    // fresco pra simular uma nova requisição real (mesmo padrão de
    // RolesTest.php / PanelNavigationTest.php).
    $res = $this->actingAs(User::find($secretary->id))
        ->withSession(panelSession($secretaryEntityUser))
        ->get(route('panel.dashboard'));

    $res->assertOk();
    $res->assertInertia(fn ($page) => $page->where('stockAlerts.below_minimum_count', 1));
});
