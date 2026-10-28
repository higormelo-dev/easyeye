<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Models\{Entity, ProductCategory, User};

/**
 * Catálogo de categorias de produto/estoque — App\Http\Controllers\Stock\
 * ProductCategoriesController (extends BaseSettingController, mesmo padrão
 * genérico de AdditionTypesController/VisitTypesController).
 *
 * MOVIDO pro módulo de Estoque (era `panel.setting.product-categories.*`,
 * disponível pra TODA clínica independente de plano) — decisão do usuário:
 * categoria não tem consumidor fora de Estoque (ver docblock do controller),
 * então cadastro agora É parte do módulo pago, atrás do MESMO gate duplo do
 * resto de Estoque (`permission:stock.manage` + `feature:has_inventory_module`,
 * ver routes/web.php). `giveInventoryModuleAccess()` é helper compartilhado
 * de tests/Pest.php (usado também por IolLensesTest/StockCountsTest/etc.) —
 * chamado pra `$this->entity` E pra qualquer entity extra criada num teste,
 * senão o 403 do gate mascara o que o teste de isolamento realmente verifica.
 *
 * ClientRule::Admin faz bypass automático da permission dentro de
 * HasEntityRoles::hasPermissionInEntity() (mesmo padrão de
 * tests/Feature/AclTest.php), então os testes de admin não precisam de uma
 * Role customizada com `stock.manage` atribuída — só da feature no plano.
 */
beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    giveInventoryModuleAccess($this->entity);

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
        ->post(route('panel.stock.product-categories.store'), ['name' => 'Colírios'], ['Accept' => 'application/json']);

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
        ->post(route('panel.stock.product-categories.store'), ['name' => 'OPM'], ['Accept' => 'application/json']);

    $res->assertStatus(422)->assertJsonValidationErrors('name');
});

it('clínica diferente pode usar o mesmo nome de categoria (unicidade é por entity_id)', function () {
    ProductCategory::create(['entity_id' => $this->entity->id, 'name' => 'OPM', 'active' => true]);

    $otherEntity     = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $otherAdmin      = User::factory()->create();
    $otherEntityUser = createEntityUser($otherEntity, $otherAdmin, ClientRule::Admin->value);
    giveInventoryModuleAccess($otherEntity);

    $res = actingAsCategoryAdmin($this, $otherAdmin, $otherEntityUser)
        ->post(route('panel.stock.product-categories.store'), ['name' => 'OPM'], ['Accept' => 'application/json']);

    $res->assertOk();
});

it('[ISOLAMENTO] admin de outra clínica não edita categoria alheia (404, não 403 — não revela existência)', function () {
    $category = ProductCategory::create(['entity_id' => $this->entity->id, 'name' => 'OPM', 'active' => true]);

    $otherEntity     = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $otherAdmin      = User::factory()->create();
    $otherEntityUser = createEntityUser($otherEntity, $otherAdmin, ClientRule::Admin->value);
    giveInventoryModuleAccess($otherEntity);

    $res = actingAsCategoryAdmin($this, $otherAdmin, $otherEntityUser)
        ->put(route('panel.stock.product-categories.update', $category->id), ['name' => 'Hackeado', 'active' => true], ['Accept' => 'application/json']);

    $res->assertStatus(404);
    expect($category->fresh()->name)->toBe('OPM');
});

it('admin desativa (soft delete) uma categoria', function () {
    $category = ProductCategory::create(['entity_id' => $this->entity->id, 'name' => 'Papelaria', 'active' => true]);

    actingAsCategoryAdmin($this)
        ->delete(route('panel.stock.product-categories.destroy', $category->id), [], ['Accept' => 'application/json'])
        ->assertOk();

    expect($category->fresh()->trashed())->toBeTrue();
});

it('[REGRA DE NEGÓCIO] clínica sem o módulo de estoque no plano recebe 403 ao acessar/cadastrar categoria', function () {
    // Assinatura ATIVA, mas o plano NÃO tem a feature has_inventory_module
    // habilitada — mesmo padrão de tests/Feature/Stock/StockCountsTest.php e
    // tests/Feature/Stock/IolLensesTest.php. Cobre a mudança de decisão desta
    // migração: cadastro de categoria deixou de ser universal (ver docblock
    // do arquivo) e passou a exigir o módulo pago, igual ao resto de Estoque.
    $entityNoModule = entityWithoutInventoryModule();
    $admin          = User::factory()->create();
    $entityUser     = createEntityUser($entityNoModule, $admin, ClientRule::Admin->value);

    actingAsCategoryAdmin($this, $admin, $entityUser)
        ->get(route('panel.stock.product-categories.index'), ['Accept' => 'application/json'])
        ->assertForbidden();

    actingAsCategoryAdmin($this, $admin, $entityUser)
        ->post(route('panel.stock.product-categories.store'), ['name' => 'Qualquer'], ['Accept' => 'application/json'])
        ->assertForbidden();
});
