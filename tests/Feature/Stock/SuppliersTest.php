<?php

declare(strict_types=1);

use App\Enums\{ClientRule, FeatureKey, SubscriptionStatus};
use App\Models\{Entity, Plan, PlanFeature, Subscription, Supplier, User};

/**
 * CRUD de fornecedores (Fase 4) — App\Http\Controllers\Stock\SuppliersController.
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

function actingAsSupplierAdmin($test, ?User $admin = null, $entityUser = null)
{
    return $test->actingAs($admin ?? $test->admin)->withSession(panelSession($entityUser ?? $test->adminEntityUser));
}

it('admin cadastra um fornecedor', function () {
    actingAsSupplierAdmin($this)
        ->post(route('panel.stock.suppliers.store'), ['name' => 'Fornecedor Alfa', 'document' => '12345678000199'], ['Accept' => 'application/json'])
        ->assertRedirect(route('panel.stock.suppliers.index'));

    $supplier = Supplier::query()->where('entity_id', $this->entity->id)->first();
    expect($supplier)->not->toBeNull()
        ->and($supplier->code)->toStartWith('FOR-');
});

it('documento duplicado na mesma clínica é rejeitado', function () {
    Supplier::create(['entity_id' => $this->entity->id, 'name' => 'A', 'document' => '111', 'active' => true]);

    actingAsSupplierAdmin($this)
        ->post(route('panel.stock.suppliers.store'), ['name' => 'B', 'document' => '111'], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('document');
});

it('[ISOLAMENTO] admin de outra clínica recebe 404 ao editar fornecedor alheio', function () {
    $supplier = Supplier::create(['entity_id' => $this->entity->id, 'name' => 'Fornecedor Alfa', 'active' => true]);

    $otherEntity     = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $otherAdmin      = User::factory()->create();
    $otherEntityUser = createEntityUser($otherEntity, $otherAdmin, ClientRule::Admin->value);

    actingAsSupplierAdmin($this, $otherAdmin, $otherEntityUser)
        ->put(route('panel.stock.suppliers.update', $supplier->id), ['name' => 'Hackeado'], ['Accept' => 'application/json'])
        ->assertStatus(404);

    expect($supplier->fresh()->name)->toBe('Fornecedor Alfa');
});

it('admin desativa (soft delete) um fornecedor', function () {
    $supplier = Supplier::create(['entity_id' => $this->entity->id, 'name' => 'Fornecedor Alfa', 'active' => true]);

    actingAsSupplierAdmin($this)
        ->delete(route('panel.stock.suppliers.destroy', $supplier->id), [], ['Accept' => 'application/json'])
        ->assertRedirect(route('panel.stock.suppliers.index'));

    expect($supplier->fresh()->trashed())->toBeTrue();
});
