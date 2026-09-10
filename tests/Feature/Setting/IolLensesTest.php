<?php

use App\Enums\{ClientRule, FeatureKey, SubscriptionStatus};
use App\Models\{Entity, EntityIolLens, EntityProduct, IolLensModel, Plan, PlanFeature, Subscription, User};
use App\Services\Stock\StockService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * CRUD do inventário de lentes IOL (catarata) DA CLÍNICA —
 * App\Http\Controllers\Setting\IolLensesController.
 *
 * Isolamento: entity_iol_lenses é dado DA CLÍNICA (escopado por entity_id);
 * iol_lens_models é catálogo GLOBAL sem escopo, compartilhado entre todas as
 * clínicas — ver docblocks de EntityIolLens/IolLensModel/
 * IolLensCatalogService::findOrCreateModel().
 *
 * Rota protegida por `permission:settings.manage`
 * (App\Http\Middleware\EnsureEntityPermission) — ClientRule::Admin faz
 * bypass automático dentro de HasEntityRoles::hasPermissionInEntity(), então
 * os testes rodam como admin puro (mesmo padrão de tests/Feature/AclTest.php
 * para as demais rotas /panel/setting/*), sem precisar de uma Role
 * customizada com a permission atribuída.
 *
 * `iollenses` NÃO expõe rota de restore (confirmado via `php artisan
 * route:list`, diferente de covenants/skintypes/lenses/etc. no mesmo bloco
 * de routes/web.php) — por isso não há teste de restore aqui (item 10 do
 * pedido original cobre só o soft delete).
 *
 * Setup/estilo espelha tests/Feature/EyeImages/ExternalExamImportTest.php
 * (Storage::fake, UploadedFile::fake(), helpers createEntityUser/
 * panelSession de tests/Pest.php). Disco usado por
 * IolLensCatalogService::storeImage() é `public` (foto de produto não é
 * dado sensível de paciente — ver docblock do service), diferente do disco
 * `s3` fake usado pelos testes de exame.
 */
beforeEach(function () {
    Storage::fake('public');

    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);

    $this->admin           = User::factory()->create();
    $this->adminEntityUser = createEntityUser($this->entity, $this->admin, ClientRule::Admin->value);
});

function actingAsIolLensAdmin($test, ?User $admin = null, $entityUser = null)
{
    return $test->actingAs($admin ?? $test->admin)
        ->withSession(panelSession($entityUser ?? $test->adminEntityUser));
}

/**
 * Habilita o módulo de estoque (feature paga) pra uma entity — necessário
 * só nos testes do vínculo opcional Lente IOL ↔ Produto (GAP-FILL pós-Fase
 * 4): sem isso `hasInventoryModule` fica false e `panel.stock.products.search`
 * 403a (mesmo par de trava permission:stock.manage + feature:has_inventory_module
 * de todo o resto do módulo — ver StockReportsTest::beforeEach() pro mesmo
 * setup). Os demais testes deste arquivo NÃO chamam isso de propósito: o
 * CRUD de lente em si nunca dependeu do módulo de estoque.
 */
function enableInventoryModuleFor(Entity $entity): void
{
    $plan = Plan::factory()->create(['active' => true]);
    PlanFeature::factory()->enabled(FeatureKey::HasInventoryModule)->for($plan)->create();
    Subscription::factory()->create([
        'entity_id' => $entity->id, 'plan_id' => $plan->id, 'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
    ]);
}

function iolLensPayload(array $overrides = []): array
{
    return array_merge([
        'manufacturer' => 'Alcon',
        'model_name'   => 'AcrySof IQ',
        'category'     => 'monofocal',
        'diopter_min'  => 10,
        'diopter_max'  => 30,
        'price'        => 2500.5,
        'active'       => true,
    ], $overrides);
}

/**
 * POST panel.setting.iollenses.store com Accept: application/json — força
 * respostas de erro em JSON (validação) mesmo em rota Inertia-friendly,
 * mesmo padrão usado por ExternalExamImportTest::importExternalExam().
 */
function storeIolLens($test, array $overrides = [], ?User $admin = null, $entityUser = null)
{
    return actingAsIolLensAdmin($test, $admin, $entityUser)
        ->post(route('panel.setting.iollenses.store'), iolLensPayload($overrides), ['Accept' => 'application/json']);
}

function updateIolLens($test, string $id, array $overrides = [], ?User $admin = null, $entityUser = null)
{
    return actingAsIolLensAdmin($test, $admin, $entityUser)
        ->put(route('panel.setting.iollenses.update', $id), iolLensPayload($overrides), ['Accept' => 'application/json']);
}

it('admin cria uma lente com todos os campos + imagem — EntityIolLens criado, image_url presente, arquivo no disco fake', function () {
    $res = storeIolLens($this, [
        'category'    => 'multifocal',
        'diopter_min' => 15.5,
        'diopter_max' => 28.0,
        'price'       => 3200.90,
        'active'      => false,
        'image'       => UploadedFile::fake()->image('lens.jpg'),
    ]);

    $res->assertRedirect(route('panel.setting.iollenses.index'));

    $lens = EntityIolLens::where('entity_id', $this->entity->id)->first();

    expect($lens)->not->toBeNull();
    expect($lens->manufacturer)->toBe('Alcon');
    expect($lens->model_name)->toBe('AcrySof IQ');
    expect($lens->category)->toBe('multifocal');
    expect((float) $lens->diopter_min)->toBe(15.5);
    expect((float) $lens->diopter_max)->toBe(28.0);
    expect((float) $lens->price)->toBe(3200.9);
    expect($lens->active)->toBeFalse();
    expect($lens->image_path)->not->toBeNull();
    expect($lens->image_url)->not->toBeNull();

    Storage::disk('public')->assertExists($lens->image_path);
});

it('cria lente com fabricante/modelo inexistente no catálogo global — findOrCreateModel registra um novo IolLensModel e a inventory aponta pra ele', function () {
    expect(IolLensModel::count())->toBe(0);

    $res = storeIolLens($this, [
        'manufacturer' => 'Zeiss Nova',
        'model_name'   => 'CT Asphina 509MP',
    ]);

    $res->assertRedirect(route('panel.setting.iollenses.index'));

    expect(IolLensModel::count())->toBe(1);

    $globalModel = IolLensModel::first();
    expect($globalModel->manufacturer)->toBe('Zeiss Nova');
    expect($globalModel->model_name)->toBe('CT Asphina 509MP');
    expect($globalModel->normalized_key)->toBe('zeiss nova|ct asphina 509mp');
    expect($globalModel->created_by_entity_id)->toBe($this->entity->id);

    $lens = EntityIolLens::where('entity_id', $this->entity->id)->firstOrFail();
    expect($lens->iol_lens_model_id)->toBe($globalModel->id);
});

it('cria lente escolhendo um iol_lens_model_id já existente (autocomplete) — NÃO duplica o catálogo global', function () {
    $existing = IolLensModel::create([
        'manufacturer'   => 'Johnson & Johnson',
        'model_name'     => 'Tecnis Eyhance',
        'category'       => 'monofocal',
        'normalized_key' => mb_strtolower('Johnson & Johnson|Tecnis Eyhance', 'UTF-8'),
    ]);

    expect(IolLensModel::count())->toBe(1);

    $res = storeIolLens($this, [
        'iol_lens_model_id' => $existing->id,
        'manufacturer'      => 'Johnson & Johnson',
        'model_name'        => 'Tecnis Eyhance',
    ]);

    $res->assertRedirect(route('panel.setting.iollenses.index'));

    // Catálogo global não cresce: já existia um model com esse normalized_key.
    expect(IolLensModel::count())->toBe(1);

    $lens = EntityIolLens::where('entity_id', $this->entity->id)->firstOrFail();
    expect($lens->iol_lens_model_id)->toBe($existing->id);
});

it('duas clínicas cadastrando fabricante+modelo idênticos deduplicam no catálogo global (normalized_key) mas mantêm inventário próprio', function () {
    $entityB      = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $adminB       = User::factory()->create();
    $adminBEntity = createEntityUser($entityB, $adminB, ClientRule::Admin->value);

    $manufacturer = 'Bausch + Lomb';
    $modelName    = 'enVista';

    storeIolLens($this, [
        'manufacturer' => $manufacturer,
        'model_name'   => $modelName,
        'diopter_min'  => 6,
        'diopter_max'  => 30,
        'price'        => 1800,
    ])->assertRedirect(route('panel.setting.iollenses.index'));

    storeIolLens($this, [
        'manufacturer' => $manufacturer,
        'model_name'   => $modelName,
        'diopter_min'  => 8,
        'diopter_max'  => 34,
        'price'        => 2100,
    ], $adminB, $adminBEntity)->assertRedirect(route('panel.setting.iollenses.index'));

    $normalizedKey = mb_strtolower("{$manufacturer}|{$modelName}", 'UTF-8');
    expect(IolLensModel::where('normalized_key', $normalizedKey)->count())->toBe(1);

    $lensA = EntityIolLens::where('entity_id', $this->entity->id)->firstOrFail();
    $lensB = EntityIolLens::where('entity_id', $entityB->id)->firstOrFail();

    // Mesmo model global reaproveitado pelas duas clínicas...
    expect($lensA->iol_lens_model_id)->toBe($lensB->iol_lens_model_id);
    expect($lensA->id)->not->toBe($lensB->id);

    // ...mas cada uma com sua própria linha de inventário (dioptria/valor diferentes).
    expect((float) $lensA->diopter_min)->toBe(6.0);
    expect((float) $lensB->diopter_min)->toBe(8.0);
    expect((float) $lensA->price)->toBe(1800.0);
    expect((float) $lensB->price)->toBe(2100.0);
});

it('isolamento multi-tenant: lente da Entity A não aparece na listagem da Entity B; show/update/destroy por id direto retornam 404', function () {
    storeIolLens($this)->assertRedirect(route('panel.setting.iollenses.index'));
    $lensA = EntityIolLens::where('entity_id', $this->entity->id)->firstOrFail();

    $entityB      = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $adminB       = User::factory()->create();
    $adminBEntity = createEntityUser($entityB, $adminB, ClientRule::Admin->value);

    $indexRes = actingAsIolLensAdmin($this, $adminB, $adminBEntity)
        ->get(route('panel.setting.iollenses.index'));

    $indexRes->assertOk();
    $indexRes->assertInertia(fn ($page) => $page->has('items.data', 0));

    actingAsIolLensAdmin($this, $adminB, $adminBEntity)
        ->getJson(route('panel.setting.iollenses.show', $lensA->id))
        ->assertNotFound();

    updateIolLens($this, $lensA->id, [], $adminB, $adminBEntity)
        ->assertNotFound();

    actingAsIolLensAdmin($this, $adminB, $adminBEntity)
        ->delete(route('panel.setting.iollenses.destroy', $lensA->id))
        ->assertNotFound();

    // Nenhuma das tentativas cross-tenant alterou/excluiu o registro da Entity A.
    expect(EntityIolLens::find($lensA->id))->not->toBeNull();
});

it('diopter_max menor que diopter_min retorna 422', function () {
    $res = storeIolLens($this, ['diopter_min' => 20, 'diopter_max' => 10]);

    $res->assertStatus(422);
    $res->assertJsonValidationErrors('diopter_max');

    expect(EntityIolLens::count())->toBe(0);
});

it('upload de imagem com mimetype real não permitido (arquivo disfarçado de .jpg) retorna 422', function () {
    // Nome/extensão sugerem imagem, mas o mimetype REAL declarado é
    // text/plain — testa que a validação usa o mimetype real (finfo), não
    // confia só na extensão do nome do arquivo (ver docblock de
    // EntityIolLensRequest::rules()).
    $res = storeIolLens($this, [
        'image' => UploadedFile::fake()->create('foto.jpg', 10, 'text/plain'),
    ]);

    $res->assertStatus(422);
    $res->assertJsonValidationErrors('image');

    expect(EntityIolLens::count())->toBe(0);
});

it('search() com menos de 2 caracteres não busca — retorna vazio mesmo havendo correspondência no catálogo', function () {
    IolLensModel::create([
        'manufacturer'   => 'A-Lens',
        'model_name'     => 'Modelo Único',
        'normalized_key' => mb_strtolower('A-Lens|Modelo Único', 'UTF-8'),
    ]);

    $res = actingAsIolLensAdmin($this)->getJson(route('panel.setting.iollenses.search', ['q' => 'a']));

    $res->assertOk();
    expect($res->json('data'))->toBe([]);
});

it('search() com termo válido (>=2 chars) retorna lentes do catálogo global, ordenadas com correspondência de PREFIXO primeiro', function () {
    $prefixMatch = IolLensModel::create([
        'manufacturer'   => 'AcrySof Labs',
        'model_name'     => 'Toric',
        'normalized_key' => mb_strtolower('AcrySof Labs|Toric', 'UTF-8'),
    ]);

    $containsMatch = IolLensModel::create([
        'manufacturer'   => 'Alcon',
        'model_name'     => 'Elite AcrySof',
        'normalized_key' => mb_strtolower('Alcon|Elite AcrySof', 'UTF-8'),
    ]);

    $unrelated = IolLensModel::create([
        'manufacturer'   => 'Bausch + Lomb',
        'model_name'     => 'enVista',
        'normalized_key' => mb_strtolower('Bausch + Lomb|enVista', 'UTF-8'),
    ]);

    $res = actingAsIolLensAdmin($this)->getJson(route('panel.setting.iollenses.search', ['q' => 'acry']));

    $res->assertOk();

    $ids = collect($res->json('data'))->pluck('id');

    expect($ids)->toHaveCount(2);
    expect($ids->first())->toBe($prefixMatch->id);
    expect($ids->last())->toBe($containsMatch->id);
    expect($ids)->not->toContain($unrelated->id);
});

it('editar uma lente SEM enviar nova imagem mantém a imagem antiga (arquivo não é apagado do disco)', function () {
    storeIolLens($this, ['image' => UploadedFile::fake()->image('lens-original.jpg')])
        ->assertRedirect(route('panel.setting.iollenses.index'));

    $lens         = EntityIolLens::where('entity_id', $this->entity->id)->firstOrFail();
    $originalPath = $lens->image_path;

    expect($originalPath)->not->toBeNull();
    Storage::disk('public')->assertExists($originalPath);

    updateIolLens($this, $lens->id, ['price' => 9999.99])
        ->assertRedirect(route('panel.setting.iollenses.index'));

    $lens->refresh();
    expect($lens->image_path)->toBe($originalPath);
    expect((float) $lens->price)->toBe(9999.99);
    Storage::disk('public')->assertExists($originalPath);
});

it('excluir uma lente faz soft delete (deleted_at preenchido, some da consulta padrão, mas continua recuperável via withTrashed)', function () {
    storeIolLens($this)->assertRedirect(route('panel.setting.iollenses.index'));
    $lens = EntityIolLens::where('entity_id', $this->entity->id)->firstOrFail();

    $res = actingAsIolLensAdmin($this)->delete(route('panel.setting.iollenses.destroy', $lens->id));

    $res->assertRedirect(route('panel.setting.iollenses.index'));

    expect(EntityIolLens::find($lens->id))->toBeNull();

    $trashed = EntityIolLens::withTrashed()->find($lens->id);
    expect($trashed)->not->toBeNull();
    expect($trashed->trashed())->toBeTrue();

    // NOTA: `iollenses` não expõe rota de restore (confirmado via
    // `php artisan route:list` — diferente de covenants/skintypes/lenses/
    // etc. registrados no mesmo bloco de routes/web.php, que têm
    // `{resource}/{id}/restore`). Item 10 do pedido original pede um teste
    // de restore "se o endpoint existir" — não existe, então esse teste foi
    // deliberadamente omitido.
});

// ── Vínculo opcional com estoque (GAP-FILL pós-Fase 4) ─────────────────────
// entity_product_id + App\Models\EntityProduct — ver docblock de
// App\Models\EntityIolLens sobre a decisão (aditivo, sem migração).

it('[GAP] cria lente já vinculada a um produto do estoque DA MESMA clínica — persistido + refletido no resource', function () {
    enableInventoryModuleFor($this->entity);
    $product = EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'Lente IOL física', 'unit' => 'un', 'active' => true]);
    app(StockService::class)->manualIn($product, 3, 500.00);

    $res = storeIolLens($this, ['entity_product_id' => $product->id]);
    $res->assertRedirect(route('panel.setting.iollenses.index'));

    $lens = EntityIolLens::where('entity_id', $this->entity->id)->firstOrFail();
    expect($lens->entity_product_id)->toBe($product->id);

    $show = actingAsIolLensAdmin($this)->getJson(route('panel.setting.iollenses.show', $lens->id));
    $show->assertOk();
    // (float) explícito: json_encode() de 3.0 vira `3` sem casa decimal —
    // json_decode() volta int, quebrando toBe(3.0) por tipo estrito (mesma
    // pegadinha já documentada em StockServiceTest/PurchaseOrderServiceTest).
    expect($show->json('data.entity_product_id'))->toBe($product->id)
        ->and($show->json('data.stock.name'))->toBe('Lente IOL física')
        ->and((float) $show->json('data.stock.qty_on_hand'))->toBe(3.0);
});

it('[GAP][SEGURANÇA] vincular a um entity_product_id de OUTRA clínica retorna 422 — isolamento multi-tenant', function () {
    enableInventoryModuleFor($this->entity);
    $otherEntity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $otherProduct = EntityProduct::create(['entity_id' => $otherEntity->id, 'name' => 'Produto de outra clínica', 'unit' => 'un', 'active' => true]);

    $res = storeIolLens($this, ['entity_product_id' => $otherProduct->id]);

    $res->assertStatus(422);
    $res->assertJsonValidationErrors('entity_product_id');
    expect(EntityIolLens::count())->toBe(0);
});

it('[GAP] lente sem vínculo (entity_product_id omitido) continua funcionando normalmente — stock ausente no resource', function () {
    $res = storeIolLens($this);
    $res->assertRedirect(route('panel.setting.iollenses.index'));

    $lens = EntityIolLens::where('entity_id', $this->entity->id)->firstOrFail();
    expect($lens->entity_product_id)->toBeNull();

    $show = actingAsIolLensAdmin($this)->getJson(route('panel.setting.iollenses.show', $lens->id));
    $show->assertOk();
    expect($show->json('data.entity_product_id'))->toBeNull()
        ->and($show->json('data.stock'))->toBeNull();
});

it('[GAP] editar lente pra REMOVER um vínculo existente funciona (entity_product_id vira null)', function () {
    enableInventoryModuleFor($this->entity);
    $product = EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'Lente vinculada', 'unit' => 'un', 'active' => true]);

    storeIolLens($this, ['entity_product_id' => $product->id])->assertRedirect(route('panel.setting.iollenses.index'));
    $lens = EntityIolLens::where('entity_id', $this->entity->id)->firstOrFail();
    expect($lens->entity_product_id)->toBe($product->id);

    updateIolLens($this, $lens->id, ['entity_product_id' => null])->assertRedirect(route('panel.setting.iollenses.index'));

    $lens->refresh();
    expect($lens->entity_product_id)->toBeNull();
});

// Dois `it()` separados (não 2 asserts num só) DE PROPÓSITO: FeatureGateService
// é singleton com cache de assinatura POR PROCESSO ($subscriptionCache, ver
// docblock da classe) — chamar index() 2x na MESMA function de teste leria a
// assinatura da PRIMEIRA chamada (cacheada), mesmo após criar uma nova
// Subscription no meio. Cada `it()` do Pest tem seu próprio boot/container,
// então cada um vê o estado real e isolado.
it('[GAP] index() expõe hasInventoryModule=false quando a clínica NÃO tem o módulo de estoque no plano', function () {
    $res = actingAsIolLensAdmin($this)->get(route('panel.setting.iollenses.index'));
    $res->assertOk();
    $res->assertInertia(fn ($page) => $page->where('hasInventoryModule', false));
});

it('[GAP] index() expõe hasInventoryModule=true quando a clínica TEM o módulo de estoque no plano', function () {
    enableInventoryModuleFor($this->entity);

    $res = actingAsIolLensAdmin($this)->get(route('panel.setting.iollenses.index'));
    $res->assertOk();
    $res->assertInertia(fn ($page) => $page->where('hasInventoryModule', true));
});

it('[GAP] ProductsController::search() — menos de 2 caracteres retorna vazio', function () {
    enableInventoryModuleFor($this->entity);
    EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'Lente X', 'unit' => 'un', 'active' => true]);

    $res = actingAsIolLensAdmin($this)->getJson(route('panel.stock.products.search', ['q' => 'l']));

    $res->assertOk();
    expect($res->json('data'))->toBe([]);
});

it('[GAP] ProductsController::search() — retorna só produtos ATIVOS da MESMA clínica, campo product_name (não name) pra não colidir com o label do SearchSelect', function () {
    enableInventoryModuleFor($this->entity);
    $match        = EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'Lente Multifocal', 'code' => 'PRD-1', 'unit' => 'un', 'active' => true]);
    $inactive     = EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'Lente Inativa', 'unit' => 'un', 'active' => false]);
    $otherEntity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $otherProduct = EntityProduct::create(['entity_id' => $otherEntity->id, 'name' => 'Lente Multifocal outra clínica', 'unit' => 'un', 'active' => true]);

    $res = actingAsIolLensAdmin($this)->getJson(route('panel.stock.products.search', ['q' => 'multifocal']));

    $res->assertOk();
    $ids = collect($res->json('data'))->pluck('id');

    expect($ids)->toHaveCount(1)
        ->and($ids->first())->toBe($match->id)
        ->and($ids)->not->toContain($inactive->id)
        ->and($ids)->not->toContain($otherProduct->id)
        ->and($res->json('data.0.product_name'))->toBe('Lente Multifocal')
        ->and($res->json('data.0.label'))->toBe('Lente Multifocal (PRD-1)');
});

it('[GAP][REGRA DE NEGÓCIO] ProductsController::search() sem o módulo de estoque no plano retorna 403', function () {
    // SEM enableInventoryModuleFor() — feature:has_inventory_module barra
    // antes mesmo de chegar no controller.
    $res = actingAsIolLensAdmin($this)->getJson(route('panel.stock.products.search', ['q' => 'lente']));

    $res->assertForbidden();
});
