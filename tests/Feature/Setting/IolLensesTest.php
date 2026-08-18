<?php

use App\Enums\ClientRule;
use App\Models\{Entity, EntityIolLens, IolLensModel, User};
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
