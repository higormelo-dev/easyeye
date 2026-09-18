<?php

use App\Enums\ClientRule;
use App\Models\{Entity, EntityIolLens, EntityProduct, IolLensModel, ProductCategory, User};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * CRUD do inventário de lentes IOL (catarata) DA CLÍNICA —
 * App\Http\Controllers\Stock\IolLensesController.
 *
 * Toda lente É um produto de estoque real agora (1:1 obrigatório via
 * App\Services\IolLensStockBridgeService — ver docblock de
 * App\Models\EntityIolLens). Fabricante/nome/preço/foto/status vivem no
 * EntityProduct vinculado; a lente em si guarda só o que é clínico/óptico
 * (category = tipo óptico, diopter_min/max).
 *
 * MOVIDO pro módulo de Estoque (era `panel.setting.iollenses.*`, disponível
 * pra TODA clínica independente de plano) — decisão do usuário: cadastro de
 * lente agora É parte do módulo pago, atrás do MESMO gate duplo do resto de
 * Estoque (`permission:stock.manage` + `feature:has_inventory_module`, ver
 * routes/web.php). `beforeEach()` provisiona plano com a feature habilitada
 * pra $this->entity — mesmo padrão de tests/Feature/Stock/StockCountsTest.php
 * — e `giveInventoryModuleAccess()` faz o mesmo pra qualquer entity extra
 * criada num teste (isolamento multi-tenant precisa das DUAS clínicas com a
 * feature, senão o 403 do gate mascara o que o teste realmente verifica).
 *
 * Isolamento: entity_iol_lenses/entity_products são dado DA CLÍNICA
 * (escopados por entity_id); iol_lens_models é catálogo GLOBAL sem escopo,
 * compartilhado entre todas as clínicas — ver docblocks de
 * EntityIolLens/IolLensModel/IolLensCatalogService::findOrCreateModel().
 *
 * ClientRule::Admin faz bypass automático da permission dentro de
 * HasEntityRoles::hasPermissionInEntity() (mesmo padrão de
 * tests/Feature/AclTest.php), então os testes de admin não precisam de uma
 * Role customizada com `stock.manage` atribuída — só da feature no plano.
 *
 * `iollenses` NÃO expõe rota de restore (confirmado via `php artisan
 * route:list`, diferente de covenants/skintypes/lenses/etc. no bloco de
 * Configurações de routes/web.php) — por isso não há teste de restore aqui.
 *
 * Setup/estilo espelha tests/Feature/EyeImages/ExternalExamImportTest.php
 * (Storage::fake, UploadedFile::fake(), helpers createEntityUser/
 * panelSession de tests/Pest.php). Disco usado por
 * IolLensCatalogService::storeImage() é `public` (foto de produto não é
 * dado sensível de paciente — ver docblock do service).
 *
 * Os testes do antigo vínculo MANUAL opcional lente↔produto (GAP-FILL
 * pós-Fase 4, incluindo cobertura de ProductsController::search()) foram
 * REMOVIDOS daqui — o vínculo deixou de existir como conceito (agora é
 * sempre automático). A cobertura de search() em si (endpoint genérico do
 * módulo de estoque, usado por outros pickers como
 * ProcedureProductsController) foi relocada pra
 * tests/Feature/Stock/ProductsTest.php.
 *
 * `giveInventoryModuleAccess()` é helper compartilhado (tests/Pest.php) —
 * ver docblock lá pro motivo de não redeclarar por arquivo de teste.
 */
beforeEach(function () {
    Storage::fake('public');

    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    giveInventoryModuleAccess($this->entity);

    $this->admin           = User::factory()->create();
    $this->adminEntityUser = createEntityUser($this->entity, $this->admin, ClientRule::Admin->value);
});

function actingAsIolLensAdmin($test, ?User $admin = null, $entityUser = null)
{
    return $test->actingAs($admin ?? $test->admin)
        ->withSession(panelSession($entityUser ?? $test->adminEntityUser));
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
 * POST panel.stock.iollenses.store com Accept: application/json — força
 * respostas de erro em JSON (validação) mesmo em rota Inertia-friendly,
 * mesmo padrão usado por ExternalExamImportTest::importExternalExam().
 */
function storeIolLens($test, array $overrides = [], ?User $admin = null, $entityUser = null)
{
    return actingAsIolLensAdmin($test, $admin, $entityUser)
        ->post(route('panel.stock.iollenses.store'), iolLensPayload($overrides), ['Accept' => 'application/json']);
}

function updateIolLens($test, string $id, array $overrides = [], ?User $admin = null, $entityUser = null)
{
    return actingAsIolLensAdmin($test, $admin, $entityUser)
        ->put(route('panel.stock.iollenses.update', $id), iolLensPayload($overrides), ['Accept' => 'application/json']);
}

it('admin cria uma lente com todos os campos + imagem — EntityIolLens + EntityProduct vinculado criados, image_url presente, arquivo no disco fake', function () {
    $res = storeIolLens($this, [
        'category'    => 'multifocal',
        'diopter_min' => 15.5,
        'diopter_max' => 28.0,
        'price'       => 3200.90,
        'active'      => false,
        'image'       => UploadedFile::fake()->image('lens.jpg'),
    ]);

    $res->assertRedirect(route('panel.stock.iollenses.index'));

    $lens = EntityIolLens::where('entity_id', $this->entity->id)->with('entityProduct')->first();

    expect($lens)->not->toBeNull();
    expect($lens->entity_product_id)->not->toBeNull();

    $product = $lens->entityProduct;
    expect($product)->not->toBeNull();
    expect($product->entity_id)->toBe($this->entity->id);
    expect($product->manufacturer)->toBe('Alcon');
    expect($product->name)->toBe('AcrySof IQ');
    expect($lens->category)->toBe('multifocal');
    expect((float) $lens->diopter_min)->toBe(15.5);
    expect((float) $lens->diopter_max)->toBe(28.0);
    expect((float) $product->sale_price)->toBe(3200.9);
    expect($product->active)->toBeFalse();
    expect($product->is_opm)->toBeTrue();
    expect($product->image_path)->not->toBeNull();
    expect($product->image_url)->not->toBeNull();
    expect((float) $product->qty_on_hand)->toBe(0.0);

    Storage::disk('public')->assertExists($product->image_path);
});

it('cria a categoria de estoque "Lentes IOL" automaticamente na primeira lente da clínica, e REAPROVEITA na segunda (não duplica)', function () {
    expect(ProductCategory::where('entity_id', $this->entity->id)->count())->toBe(0);

    storeIolLens($this, ['model_name' => 'Lente A'])->assertRedirect(route('panel.stock.iollenses.index'));

    expect(ProductCategory::where('entity_id', $this->entity->id)->count())->toBe(1);
    $category = ProductCategory::where('entity_id', $this->entity->id)->firstOrFail();
    expect($category->name)->toBe('Lentes IOL');

    storeIolLens($this, ['model_name' => 'Lente B'])->assertRedirect(route('panel.stock.iollenses.index'));

    expect(ProductCategory::where('entity_id', $this->entity->id)->count())->toBe(1);

    $lensA = EntityIolLens::whereHas('entityProduct', fn ($q) => $q->where('name', 'Lente A'))->firstOrFail();
    $lensB = EntityIolLens::whereHas('entityProduct', fn ($q) => $q->where('name', 'Lente B'))->firstOrFail();

    expect($lensA->entityProduct->product_category_id)->toBe($category->id);
    expect($lensB->entityProduct->product_category_id)->toBe($category->id);
});

it('cria lente com fabricante/modelo inexistente no catálogo global — findOrCreateModel registra um novo IolLensModel e a inventory aponta pra ele', function () {
    expect(IolLensModel::count())->toBe(0);

    $res = storeIolLens($this, [
        'manufacturer' => 'Zeiss Nova',
        'model_name'   => 'CT Asphina 509MP',
    ]);

    $res->assertRedirect(route('panel.stock.iollenses.index'));

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

    $res->assertRedirect(route('panel.stock.iollenses.index'));

    // Catálogo global não cresce: já existia um model com esse normalized_key.
    expect(IolLensModel::count())->toBe(1);

    $lens = EntityIolLens::where('entity_id', $this->entity->id)->firstOrFail();
    expect($lens->iol_lens_model_id)->toBe($existing->id);
});

it('duas clínicas cadastrando fabricante+modelo idênticos deduplicam no catálogo global (normalized_key) mas mantêm inventário/produto/categoria próprios', function () {
    $entityB = Entity::factory()->create(['is_client' => true, 'active' => true]);
    giveInventoryModuleAccess($entityB);
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
    ])->assertRedirect(route('panel.stock.iollenses.index'));

    storeIolLens($this, [
        'manufacturer' => $manufacturer,
        'model_name'   => $modelName,
        'diopter_min'  => 8,
        'diopter_max'  => 34,
        'price'        => 2100,
    ], $adminB, $adminBEntity)->assertRedirect(route('panel.stock.iollenses.index'));

    $normalizedKey = mb_strtolower("{$manufacturer}|{$modelName}", 'UTF-8');
    expect(IolLensModel::where('normalized_key', $normalizedKey)->count())->toBe(1);

    $lensA = EntityIolLens::where('entity_id', $this->entity->id)->with('entityProduct')->firstOrFail();
    $lensB = EntityIolLens::where('entity_id', $entityB->id)->with('entityProduct')->firstOrFail();

    // Mesmo model global reaproveitado pelas duas clínicas...
    expect($lensA->iol_lens_model_id)->toBe($lensB->iol_lens_model_id);
    expect($lensA->id)->not->toBe($lensB->id);

    // ...mas cada uma com seu próprio produto/categoria de estoque.
    expect($lensA->entity_product_id)->not->toBe($lensB->entity_product_id);
    expect($lensA->entityProduct->product_category_id)->not->toBe($lensB->entityProduct->product_category_id);
    expect((float) $lensA->diopter_min)->toBe(6.0);
    expect((float) $lensB->diopter_min)->toBe(8.0);
    expect((float) $lensA->entityProduct->sale_price)->toBe(1800.0);
    expect((float) $lensB->entityProduct->sale_price)->toBe(2100.0);
});

it('isolamento multi-tenant: lente da Entity A não aparece na listagem da Entity B; show/update/destroy por id direto retornam 404', function () {
    storeIolLens($this)->assertRedirect(route('panel.stock.iollenses.index'));
    $lensA = EntityIolLens::where('entity_id', $this->entity->id)->firstOrFail();

    $entityB = Entity::factory()->create(['is_client' => true, 'active' => true]);
    giveInventoryModuleAccess($entityB);
    $adminB       = User::factory()->create();
    $adminBEntity = createEntityUser($entityB, $adminB, ClientRule::Admin->value);

    $indexRes = actingAsIolLensAdmin($this, $adminB, $adminBEntity)
        ->get(route('panel.stock.iollenses.index'));

    $indexRes->assertOk();
    $indexRes->assertInertia(fn ($page) => $page->has('items.data', 0));

    actingAsIolLensAdmin($this, $adminB, $adminBEntity)
        ->getJson(route('panel.stock.iollenses.show', $lensA->id))
        ->assertNotFound();

    updateIolLens($this, $lensA->id, [], $adminB, $adminBEntity)
        ->assertNotFound();

    actingAsIolLensAdmin($this, $adminB, $adminBEntity)
        ->delete(route('panel.stock.iollenses.destroy', $lensA->id))
        ->assertNotFound();

    // Nenhuma das tentativas cross-tenant alterou/excluiu o registro da Entity A.
    expect(EntityIolLens::find($lensA->id))->not->toBeNull();
});

it('diopter_max menor que diopter_min retorna 422', function () {
    $res = storeIolLens($this, ['diopter_min' => 20, 'diopter_max' => 10]);

    $res->assertStatus(422);
    $res->assertJsonValidationErrors('diopter_max');

    expect(EntityIolLens::count())->toBe(0);
    expect(EntityProduct::count())->toBe(0);
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

    $res = actingAsIolLensAdmin($this)->getJson(route('panel.stock.iollenses.search', ['q' => 'a']));

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

    $res = actingAsIolLensAdmin($this)->getJson(route('panel.stock.iollenses.search', ['q' => 'acry']));

    $res->assertOk();

    $ids = collect($res->json('data'))->pluck('id');

    expect($ids)->toHaveCount(2);
    expect($ids->first())->toBe($prefixMatch->id);
    expect($ids->last())->toBe($containsMatch->id);
    expect($ids)->not->toContain($unrelated->id);
});

it('editar uma lente SEM enviar nova imagem mantém a imagem antiga no produto (arquivo não é apagado do disco)', function () {
    storeIolLens($this, ['image' => UploadedFile::fake()->image('lens-original.jpg')])
        ->assertRedirect(route('panel.stock.iollenses.index'));

    $lens         = EntityIolLens::where('entity_id', $this->entity->id)->with('entityProduct')->firstOrFail();
    $originalPath = $lens->entityProduct->image_path;

    expect($originalPath)->not->toBeNull();
    Storage::disk('public')->assertExists($originalPath);

    updateIolLens($this, $lens->id, ['price' => 9999.99])
        ->assertRedirect(route('panel.stock.iollenses.index'));

    $lens->refresh();
    expect($lens->entityProduct->image_path)->toBe($originalPath);
    expect((float) $lens->entityProduct->sale_price)->toBe(9999.99);
    Storage::disk('public')->assertExists($originalPath);
});

it('editar uma lente troca a imagem e apaga a antiga do disco', function () {
    storeIolLens($this, ['image' => UploadedFile::fake()->image('lens-original.jpg')])
        ->assertRedirect(route('panel.stock.iollenses.index'));

    $lens         = EntityIolLens::where('entity_id', $this->entity->id)->with('entityProduct')->firstOrFail();
    $originalPath = $lens->entityProduct->image_path;

    updateIolLens($this, $lens->id, ['image' => UploadedFile::fake()->image('lens-new.jpg')])
        ->assertRedirect(route('panel.stock.iollenses.index'));

    $lens->refresh();
    $newPath = $lens->entityProduct->image_path;

    expect($newPath)->not->toBe($originalPath);
    Storage::disk('public')->assertExists($newPath);
    Storage::disk('public')->assertMissing($originalPath);
});

it('excluir uma lente faz soft delete DELA E do produto de estoque vinculado (deleted_at preenchido, some da consulta padrão, recuperável via withTrashed)', function () {
    storeIolLens($this)->assertRedirect(route('panel.stock.iollenses.index'));
    $lens      = EntityIolLens::where('entity_id', $this->entity->id)->firstOrFail();
    $productId = $lens->entity_product_id;

    $res = actingAsIolLensAdmin($this)->delete(route('panel.stock.iollenses.destroy', $lens->id));

    $res->assertRedirect(route('panel.stock.iollenses.index'));

    expect(EntityIolLens::find($lens->id))->toBeNull();
    expect(EntityProduct::find($productId))->toBeNull();

    $trashedLens = EntityIolLens::withTrashed()->find($lens->id);
    expect($trashedLens)->not->toBeNull();
    expect($trashedLens->trashed())->toBeTrue();

    $trashedProduct = EntityProduct::withTrashed()->find($productId);
    expect($trashedProduct)->not->toBeNull();
    expect($trashedProduct->trashed())->toBeTrue();

    // NOTA: `iollenses` não expõe rota de restore (confirmado via
    // `php artisan route:list` — diferente de covenants/skintypes/lenses/
    // etc. registrados no mesmo bloco de routes/web.php, que têm
    // `{resource}/{id}/restore`).
});

it('resource de listagem/show expõe manufacturer/model_name/price/image_url/active vindos do produto vinculado — mesmo formato de chaves de antes da migração pro estoque (zero mudança pro frontend)', function () {
    storeIolLens($this, [
        'manufacturer' => 'Alcon',
        'model_name'   => 'AcrySof IQ',
        'price'        => 1234.56,
    ])->assertRedirect(route('panel.stock.iollenses.index'));

    $lens = EntityIolLens::where('entity_id', $this->entity->id)->firstOrFail();

    $show = actingAsIolLensAdmin($this)->getJson(route('panel.stock.iollenses.show', $lens->id));
    $show->assertOk();

    expect($show->json('data.manufacturer'))->toBe('Alcon')
        ->and($show->json('data.model_name'))->toBe('AcrySof IQ')
        ->and((float) $show->json('data.price'))->toBe(1234.56)
        ->and($show->json('data.active'))->toBeTrue()
        ->and($show->json('data.stock.qty_on_hand'))->not->toBeNull();

    $index = actingAsIolLensAdmin($this)->get(route('panel.stock.iollenses.index'));
    $index->assertOk();
    $index->assertInertia(fn ($page) => $page
        ->where('items.data.0.manufacturer', 'Alcon')
        ->where('items.data.0.model_name', 'AcrySof IQ'));
});

it('[REGRA DE NEGÓCIO] clínica sem o módulo de estoque no plano recebe 403 ao acessar/cadastrar lente IOL', function () {
    // Assinatura ATIVA, mas o plano NÃO tem a feature has_inventory_module
    // habilitada — mesmo padrão de tests/Feature/Stock/StockCountsTest.php.
    // NÃO usar entity sem nenhuma Subscription: é um cenário diferente
    // (ausência total de plano), fora do escopo deste teste. Cobre a
    // mudança de decisão desta migração: cadastro de lente deixou de ser
    // universal (ver docblock do arquivo) e passou a exigir o módulo pago,
    // igual ao resto de Estoque.
    $entityNoModule = entityWithoutInventoryModule();
    $admin          = User::factory()->create();
    $entityUser     = createEntityUser($entityNoModule, $admin, ClientRule::Admin->value);

    actingAsIolLensAdmin($this, $admin, $entityUser)
        ->get(route('panel.stock.iollenses.index'), ['Accept' => 'application/json'])
        ->assertForbidden();

    storeIolLens($this, [], $admin, $entityUser)->assertForbidden();
});
