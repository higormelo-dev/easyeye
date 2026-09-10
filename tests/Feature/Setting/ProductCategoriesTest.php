<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Models\{Entity, ProductCategory, User};

/**
 * Catálogo de categorias de produto/estoque — App\Http\Controllers\Setting\
 * ProductCategoriesController (extends BaseSettingController, mesmo padrão
 * genérico de AdditionTypesController/VisitTypesController).
 *
 * Rota protegida por `permission:settings.manage` — ClientRule::Admin faz
 * bypass automático (ver HasEntityRoles::hasPermissionInEntity()), então os
 * testes rodam como admin puro, sem Role customizada. Sem feature gate
 * (fica só no grupo `stock.` de Products/Movements) — categoria é catálogo
 * de configuração comum.
 */
beforeEach(function () {
    $this->entity          = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->admin           = User::factory()->create();
    $this->adminEntityUser = createEntityUser($this->entity, $this->admin, ClientRule::Admin->value);
});

function actingAsCategoryAdmin($test, ?User $admin = null, $entityUser = null)
{
    return $test->actingAs($admin ?? $test->admin)
        ->withSession(panelSession($entityUser ?? $test->adminEntityUser));
}

it('admin cria uma categoria de produto', function () {
    $res = actingAsCategoryAdmin($this)
        ->post(route('panel.setting.product-categories.store'), ['name' => 'Colírios'], ['Accept' => 'application/json']);

    $res->assertOk();

    $category = ProductCategory::query()->where('entity_id', $this->entity->id)->first();
    expect($category)->not->toBeNull()
        ->and($category->name)->toBe('Colírios')
        ->and($category->active)->toBeTrue()
        ->and($category->code)->toStartWith('CAT-');
});

it('não permite duas categorias com o mesmo nome na mesma clínica', function () {
    ProductCategory::create(['entity_id' => $this->entity->id, 'name' => 'OPM', 'active' => true]);

    $res = actingAsCategoryAdmin($this)
        ->post(route('panel.setting.product-categories.store'), ['name' => 'OPM'], ['Accept' => 'application/json']);

    $res->assertStatus(422)->assertJsonValidationErrors('name');
});

it('clínica diferente pode usar o mesmo nome de categoria (unicidade é por entity_id)', function () {
    ProductCategory::create(['entity_id' => $this->entity->id, 'name' => 'OPM', 'active' => true]);

    $otherEntity     = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $otherAdmin      = User::factory()->create();
    $otherEntityUser = createEntityUser($otherEntity, $otherAdmin, ClientRule::Admin->value);

    $res = actingAsCategoryAdmin($this, $otherAdmin, $otherEntityUser)
        ->post(route('panel.setting.product-categories.store'), ['name' => 'OPM'], ['Accept' => 'application/json']);

    $res->assertOk();
});

it('[ISOLAMENTO] admin de outra clínica não edita categoria alheia (404, não 403 — não revela existência)', function () {
    $category = ProductCategory::create(['entity_id' => $this->entity->id, 'name' => 'OPM', 'active' => true]);

    $otherEntity     = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $otherAdmin      = User::factory()->create();
    $otherEntityUser = createEntityUser($otherEntity, $otherAdmin, ClientRule::Admin->value);

    $res = actingAsCategoryAdmin($this, $otherAdmin, $otherEntityUser)
        ->put(route('panel.setting.product-categories.update', $category->id), ['name' => 'Hackeado', 'active' => true], ['Accept' => 'application/json']);

    $res->assertStatus(404);
    expect($category->fresh()->name)->toBe('OPM');
});

it('admin desativa (soft delete) uma categoria', function () {
    $category = ProductCategory::create(['entity_id' => $this->entity->id, 'name' => 'Papelaria', 'active' => true]);

    actingAsCategoryAdmin($this)
        ->delete(route('panel.setting.product-categories.destroy', $category->id), [], ['Accept' => 'application/json'])
        ->assertOk();

    expect($category->fresh()->trashed())->toBeTrue();
});
