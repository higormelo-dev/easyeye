<?php

declare(strict_types=1);

use App\Enums\{ClientRule, FeatureKey, SubscriptionStatus};
use App\Models\{Entity, EntityProduct, Plan, PlanFeature, Procedure, ProcedureProduct, Subscription, User};

/**
 * BOM de estoque por procedimento — App\Http\Controllers\Stock\
 * ProcedureProductsController. Gestão ADMIN (permission:stock.manage +
 * feature:has_inventory_module), diferente da leitura clínica em
 * panel.procedures.bom (ver MedicalRecordProceduresTest.php).
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

    $this->procedure = Procedure::query()->create(['entity_id' => $this->entity->id, 'code' => 'P1', 'name' => 'Facectomia', 'active' => true]);
    $this->product   = EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'Lente IOL', 'unit' => 'un', 'active' => true]);
});

function actingAsBomAdmin($test, ?User $admin = null, $entityUser = null)
{
    return $test->actingAs($admin ?? $test->admin)->withSession(panelSession($entityUser ?? $test->adminEntityUser));
}

it('admin cadastra item da BOM de um procedimento', function () {
    $res = actingAsBomAdmin($this)->postJson(route('panel.stock.procedures.bom.store', $this->procedure->id), [
        'entity_product_id' => $this->product->id, 'quantity' => 2,
    ]);

    $res->assertCreated();
    expect(ProcedureProduct::query()->where('procedure_id', $this->procedure->id)->count())->toBe(1)
        ->and((float) ProcedureProduct::query()->first()->quantity)->toBe(2.0);
});

it('reenviar o mesmo produto ATUALIZA a quantidade em vez de duplicar', function () {
    actingAsBomAdmin($this)->postJson(route('panel.stock.procedures.bom.store', $this->procedure->id), [
        'entity_product_id' => $this->product->id, 'quantity' => 2,
    ])->assertCreated();

    actingAsBomAdmin($this)->postJson(route('panel.stock.procedures.bom.store', $this->procedure->id), [
        'entity_product_id' => $this->product->id, 'quantity' => 5,
    ])->assertCreated();

    expect(ProcedureProduct::query()->where('procedure_id', $this->procedure->id)->count())->toBe(1)
        ->and((float) ProcedureProduct::query()->first()->quantity)->toBe(5.0);
});

it('admin remove item da BOM', function () {
    $bom = ProcedureProduct::create([
        'entity_id' => $this->entity->id, 'procedure_id' => $this->procedure->id, 'entity_product_id' => $this->product->id, 'quantity' => 1,
    ]);

    actingAsBomAdmin($this)->deleteJson(route('panel.stock.procedure-products.destroy', $bom->id))->assertOk();

    expect(ProcedureProduct::query()->count())->toBe(0);
});

it('[ISOLAMENTO] admin de outra clínica recebe 404 ao gerenciar BOM de procedimento alheio', function () {
    $otherEntity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $otherPlan   = Plan::factory()->create(['active' => true]);
    PlanFeature::factory()->enabled(FeatureKey::HasInventoryModule)->for($otherPlan)->create();
    Subscription::factory()->create([
        'entity_id' => $otherEntity->id, 'plan_id' => $otherPlan->id, 'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
    ]);
    $otherAdmin      = User::factory()->create();
    $otherEntityUser = createEntityUser($otherEntity, $otherAdmin, ClientRule::Admin->value);
    // Produto da PRÓPRIA clínica alheia — isola o teste no que importa aqui
    // (posse do PROCEDIMENTO, alvo de assertOwnsProcedure()). Usar
    // $this->product (de outra entidade) falharia antes, na validação de
    // existência de entity_product_id, mascarando o que este teste cobre.
    $otherProduct = EntityProduct::create(['entity_id' => $otherEntity->id, 'name' => 'Item', 'unit' => 'un', 'active' => true]);

    actingAsBomAdmin($this, $otherAdmin, $otherEntityUser)
        ->postJson(route('panel.stock.procedures.bom.store', $this->procedure->id), ['entity_product_id' => $otherProduct->id, 'quantity' => 1])
        ->assertStatus(404);
});

// ── Procedure GLOBAL (entity_id null — catálogo compartilhado, ver
// ProcedureSearchController) ─────────────────────────────────────────────

it('admin cadastra BOM pra um procedimento GLOBAL (entity_id null) sem violar NOT NULL de procedure_products.entity_id', function () {
    $globalProcedure = Procedure::query()->create(['entity_id' => null, 'code' => 'GLOBAL-1', 'name' => 'Consulta padrão', 'active' => true]);

    $res = actingAsBomAdmin($this)->postJson(route('panel.stock.procedures.bom.store', $globalProcedure->id), [
        'entity_product_id' => $this->product->id, 'quantity' => 1,
    ]);

    $res->assertCreated();
    expect(ProcedureProduct::query()->first()->entity_id)->toBe($this->entity->id); // BOM é DESTA clínica, não null
});

it('[ISOLAMENTO] BOM de duas clínicas pro MESMO procedimento global não vazam entre si', function () {
    $globalProcedure = Procedure::query()->create(['entity_id' => null, 'code' => 'GLOBAL-1', 'name' => 'Consulta padrão', 'active' => true]);

    actingAsBomAdmin($this)->postJson(route('panel.stock.procedures.bom.store', $globalProcedure->id), [
        'entity_product_id' => $this->product->id, 'quantity' => 1,
    ])->assertCreated();

    $otherEntity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $otherPlan   = Plan::factory()->create(['active' => true]);
    PlanFeature::factory()->enabled(FeatureKey::HasInventoryModule)->for($otherPlan)->create();
    Subscription::factory()->create([
        'entity_id' => $otherEntity->id, 'plan_id' => $otherPlan->id, 'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
    ]);
    $otherAdmin      = User::factory()->create();
    $otherEntityUser = createEntityUser($otherEntity, $otherAdmin, ClientRule::Admin->value);

    $res = actingAsBomAdmin($this, $otherAdmin, $otherEntityUser)
        ->getJson(route('panel.stock.procedures.bom.index', $globalProcedure->id));

    $res->assertOk();
    expect($res->json('data'))->toBe([]); // não vê a BOM da OUTRA clínica pro mesmo procedimento global
});
