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
    // CNPJ de formato válido nos dois (14 dígitos) — senão o teste passaria
    // pelo motivo ERRADO (regex de formato barrando '111', não a
    // unicidade, que é o que este teste diz cobrir).
    Supplier::create(['entity_id' => $this->entity->id, 'name' => 'A', 'document' => '12345678000199', 'active' => true]);

    actingAsSupplierAdmin($this)
        ->post(route('panel.stock.suppliers.store'), ['name' => 'B', 'document' => '12345678000199'], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('document');
});

// ── GAP fechado (revisão pós-Fase 4): document aceitava qualquer string ────

it('[GAP] documento com formato inválido (nem CPF nem CNPJ) é rejeitado', function () {
    actingAsSupplierAdmin($this)
        ->post(route('panel.stock.suppliers.store'), ['name' => 'Fornecedor B', 'document' => '111'], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('document');

    expect(Supplier::where('name', 'Fornecedor B')->exists())->toBeFalse();
});

it('[GAP] documento com pontuação (CNPJ formatado) é normalizado e aceito', function () {
    actingAsSupplierAdmin($this)
        ->post(route('panel.stock.suppliers.store'), ['name' => 'Fornecedor C', 'document' => '12.345.678/0001-99'], ['Accept' => 'application/json'])
        ->assertRedirect(route('panel.stock.suppliers.index'));

    $supplier = Supplier::where('name', 'Fornecedor C')->firstOrFail();
    expect($supplier->document)->toBe('12345678000199');
});

it('[GAP] CPF (11 dígitos, fornecedor pessoa física/MEI) também é aceito', function () {
    actingAsSupplierAdmin($this)
        ->post(route('panel.stock.suppliers.store'), ['name' => 'Fornecedor MEI', 'document' => '12345678901'], ['Accept' => 'application/json'])
        ->assertRedirect(route('panel.stock.suppliers.index'));

    expect(Supplier::where('name', 'Fornecedor MEI')->exists())->toBeTrue();
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
