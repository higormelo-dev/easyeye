<?php

declare(strict_types=1);

use App\Enums\{ClientRule, FeatureKey, SubscriptionStatus};
use App\Http\Resources\EntityProductResource;
use App\Models\{Entity, EntityProduct, Plan, PlanFeature, ProductCategory, Subscription, User};
use App\Services\Stock\StockService;

/**
 * Catálogo de produtos/materiais de estoque — App\Http\Controllers\Stock\
 * ProductsController.
 *
 * Rota com dupla trava: `permission:stock.manage` (RBAC, admin bypass) +
 * `feature:has_inventory_module` (plano). Diferente dos catálogos em
 * `setting.`, aqui a clínica PRECISA ter a feature habilitada no plano —
 * testado explicitamente abaixo (feature ausente = 403 antes mesmo de
 * checar permission).
 */
beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->plan   = Plan::factory()->create(['active' => true]);

    PlanFeature::factory()->enabled(FeatureKey::HasInventoryModule)->for($this->plan)->create();

    Subscription::factory()->create([
        'entity_id' => $this->entity->id,
        'plan_id'   => $this->plan->id,
        'status'    => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(),
        'ends_at'   => now()->addMonth(),
    ]);

    $this->admin           = User::factory()->create();
    $this->adminEntityUser = createEntityUser($this->entity, $this->admin, ClientRule::Admin->value);

    $this->category = ProductCategory::create(['entity_id' => $this->entity->id, 'name' => 'Colírios', 'active' => true]);
});

function actingAsProductAdmin($test, ?User $admin = null, $entityUser = null)
{
    return $test->actingAs($admin ?? $test->admin)
        ->withSession(panelSession($entityUser ?? $test->adminEntityUser));
}

function productPayload($test, array $overrides = []): array
{
    return array_merge([
        'product_category_id' => $test->category->id,
        'name'                => 'Colírio Diclofenaco 0,1%',
        'unit'                => 'un',
        'sale_price'          => 25.90,
        'min_qty'             => 5,
        'active'              => true,
    ], $overrides);
}

it('clínica SEM o módulo de estoque no plano recebe 403 ao acessar produtos', function () {
    $entityWithoutFeature = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $planWithoutFeature   = Plan::factory()->create(['active' => true]);

    Subscription::factory()->create([
        'entity_id' => $entityWithoutFeature->id,
        'plan_id'   => $planWithoutFeature->id,
        'status'    => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(),
        'ends_at'   => now()->addMonth(),
    ]);

    $admin      = User::factory()->create();
    $entityUser = createEntityUser($entityWithoutFeature, $admin, ClientRule::Admin->value);

    // Accept:application/json — FeatureDeniedException::render() só devolve
    // 403 JSON nesse caso; sem ele (navegação web normal) faz back()-with-
    // errors (302), mesmo padrão de FeatureDeniedException::render() e do
    // teste equivalente em AssistantChatTest.
    actingAsProductAdmin($this, $admin, $entityUser)
        ->get(route('panel.stock.products.index'), ['Accept' => 'application/json'])
        ->assertForbidden();
});

it('admin com feature habilitada cria um produto', function () {
    // ProductsController (custom, mesmo padrão de IolLensesController) SEMPRE
    // redireciona no sucesso — diferente de BaseSettingController, que
    // devolve JSON quando Accept:application/json. `Accept` aqui só importa
    // pra validação (422 vira JSON de qualquer forma).
    $res = actingAsProductAdmin($this)
        ->post(route('panel.stock.products.store'), productPayload($this), ['Accept' => 'application/json']);

    $res->assertRedirect(route('panel.stock.products.index'));

    $product = EntityProduct::query()->where('entity_id', $this->entity->id)->first();
    expect($product)->not->toBeNull()
        ->and($product->name)->toBe('Colírio Diclofenaco 0,1%')
        ->and($product->code)->toStartWith('PRD-')
        ->and((float) $product->qty_on_hand)->toBe(0.0)
        ->and((float) $product->cost_avg)->toBe(0.0);
});

it('não aceita qty_on_hand/cost_avg via mass-assignment no cadastro (saldo só muda por StockService)', function () {
    actingAsProductAdmin($this)
        ->post(route('panel.stock.products.store'), productPayload($this, [
            'qty_on_hand' => 999,
            'cost_avg'    => 500,
        ]), ['Accept' => 'application/json'])
        ->assertRedirect(route('panel.stock.products.index'));

    $product = EntityProduct::query()->where('entity_id', $this->entity->id)->first();
    expect((float) $product->qty_on_hand)->toBe(0.0)
        ->and((float) $product->cost_avg)->toBe(0.0);
});

it('sku duplicado na mesma clínica é rejeitado', function () {
    EntityProduct::create(array_merge(productPayload($this), ['entity_id' => $this->entity->id, 'sku' => 'ABC-123']));

    actingAsProductAdmin($this)
        ->post(route('panel.stock.products.store'), productPayload($this, ['sku' => 'ABC-123', 'name' => 'Outro produto']), ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('sku');
});

it('[ISOLAMENTO] admin de outra clínica recebe 404 ao tentar editar produto alheio', function () {
    $product = EntityProduct::create(array_merge(productPayload($this), ['entity_id' => $this->entity->id]));

    $otherEntity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $otherPlan   = Plan::factory()->create(['active' => true]);
    PlanFeature::factory()->enabled(FeatureKey::HasInventoryModule)->for($otherPlan)->create();
    Subscription::factory()->create([
        'entity_id' => $otherEntity->id,
        'plan_id'   => $otherPlan->id,
        'status'    => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(),
        'ends_at'   => now()->addMonth(),
    ]);
    $otherAdmin      = User::factory()->create();
    $otherEntityUser = createEntityUser($otherEntity, $otherAdmin, ClientRule::Admin->value);

    // product_category_id: null — a categoria de $this->entity não existe
    // pra $otherEntity (EntityProductRequest::rules() já barraria isso com
    // 422 antes de chegar no controller); aqui o alvo é especificamente o
    // assertOwnership() do controller, não a validação de categoria.
    actingAsProductAdmin($this, $otherAdmin, $otherEntityUser)
        ->put(route('panel.stock.products.update', $product->id), productPayload($this, ['name' => 'Hackeado', 'product_category_id' => null]), ['Accept' => 'application/json'])
        ->assertStatus(404);

    expect($product->fresh()->name)->toBe('Colírio Diclofenaco 0,1%');
});

it('admin desativa (soft delete) um produto', function () {
    $product = EntityProduct::create(array_merge(productPayload($this), ['entity_id' => $this->entity->id]));

    actingAsProductAdmin($this)
        ->delete(route('panel.stock.products.destroy', $product->id), [], ['Accept' => 'application/json'])
        ->assertRedirect(route('panel.stock.products.index'));

    expect($product->fresh()->trashed())->toBeTrue();
});

// ── BUGFIX (achado na revisão de gaps): min_qty=0 (default de todo produto
// recém-cadastrado) não é "abaixo do mínimo" — é "sem mínimo configurado" ──

it('[BUGFIX] produto recém-cadastrado (min_qty=0, saldo=0) NÃO aparece como abaixo do mínimo', function () {
    $product = EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'Produto novo', 'unit' => 'un', 'active' => true]); // min_qty=0 (default)

    expect($product->isBelowMinimum())->toBeFalse()
        ->and(EntityProduct::query()->belowMinimum()->whereKey($product->id)->exists())->toBeFalse()
        // Mesma serialização usada pela listagem (EntityProductResource) —
        // checa direto no Resource em vez de parsear a resposta Inertia da
        // rota index() (que não é JSON puro), mesmo padrão do resto desta suíte.
        ->and((new EntityProductResource($product))->resolve()['below_minimum'])->toBeFalse();
});

it('produto com mínimo REALMENTE configurado e saldo no/abaixo dele aparece como abaixo do mínimo', function () {
    $product = EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'Colírio', 'unit' => 'un', 'min_qty' => 5, 'active' => true]);
    // qty_on_hand não é fillable (ver doc do model) — seed via StockService,
    // mesmo caminho real de qualquer entrada de estoque.
    app(StockService::class)->manualIn($product, 5, 10.00);

    expect($product->fresh()->isBelowMinimum())->toBeTrue()
        ->and(EntityProduct::query()->belowMinimum()->whereKey($product->id)->exists())->toBeTrue();
});

// ── GAP fechado (revisão pós-Fase 4 — "melhorar o módulo de estoque"):
// código de barras pra leitor USB/Bluetooth ────────────────────────────────

it('[GAP] admin cadastra produto com código de barras', function () {
    actingAsProductAdmin($this)
        ->post(route('panel.stock.products.store'), ['name' => 'Lente com EAN', 'unit' => 'un', 'barcode' => '7891234567890'], ['Accept' => 'application/json'])
        ->assertRedirect(route('panel.stock.products.index'));

    $product = EntityProduct::where('name', 'Lente com EAN')->firstOrFail();
    expect($product->barcode)->toBe('7891234567890');
});

it('[GAP] código de barras duplicado NA MESMA clínica é rejeitado', function () {
    EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'A', 'unit' => 'un', 'active' => true, 'barcode' => '111']);

    actingAsProductAdmin($this)
        ->post(route('panel.stock.products.store'), ['name' => 'B', 'unit' => 'un', 'barcode' => '111'], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('barcode');
});

it('[GAP] o MESMO código de barras pode existir em clínicas DIFERENTES (único por entity_id, não global)', function () {
    $otherEntity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    EntityProduct::create(['entity_id' => $otherEntity->id, 'name' => 'Produto de outra clínica', 'unit' => 'un', 'active' => true, 'barcode' => '999']);

    actingAsProductAdmin($this)
        ->post(route('panel.stock.products.store'), ['name' => 'Produto local', 'unit' => 'un', 'barcode' => '999'], ['Accept' => 'application/json'])
        ->assertRedirect(route('panel.stock.products.index'));

    expect(EntityProduct::where('entity_id', $this->entity->id)->where('barcode', '999')->exists())->toBeTrue();
});

it('[GAP] scanBarcode() encontra o produto ativo pelo código exato', function () {
    $product = EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'Lente Scan', 'unit' => 'un', 'active' => true, 'barcode' => '7891234567890']);

    $res = actingAsProductAdmin($this)->getJson(route('panel.stock.products.scan-barcode', ['barcode' => '7891234567890']));

    $res->assertOk();
    expect($res->json('data.id'))->toBe($product->id);
});

it('[GAP] scanBarcode() com código inexistente retorna 404 (não 200 com dado vazio)', function () {
    actingAsProductAdmin($this)
        ->getJson(route('panel.stock.products.scan-barcode', ['barcode' => '0000000000000']))
        ->assertNotFound();
});

it('[GAP] scanBarcode() NÃO encontra produto INATIVO nem de OUTRA clínica', function () {
    EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'Inativo', 'unit' => 'un', 'active' => false, 'barcode' => '111']);

    $otherEntity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    EntityProduct::create(['entity_id' => $otherEntity->id, 'name' => 'De outra clínica', 'unit' => 'un', 'active' => true, 'barcode' => '222']);

    actingAsProductAdmin($this)->getJson(route('panel.stock.products.scan-barcode', ['barcode' => '111']))->assertNotFound();
    actingAsProductAdmin($this)->getJson(route('panel.stock.products.scan-barcode', ['barcode' => '222']))->assertNotFound();
});

// ── search() — autocomplete remoto usado por outros pickers do sistema
// (ex.: IolLensFormModal antes da migração de lentes pro estoque; hoje
// também usado por ProcedureProductsController pra montar a BOM de
// procedimento) — relocado de IolLensesTest.php quando o vínculo lente↔
// produto deixou de ser manual/opcional.

it('[GAP] search() — menos de 2 caracteres retorna vazio', function () {
    EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'Lente X', 'unit' => 'un', 'active' => true]);

    $res = actingAsProductAdmin($this)->getJson(route('panel.stock.products.search', ['q' => 'l']));

    $res->assertOk();
    expect($res->json('data'))->toBe([]);
});

it('[GAP] search() — retorna só produtos ATIVOS da MESMA clínica, campo product_name (não name) pra não colidir com o label do SearchSelect', function () {
    $match        = EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'Lente Multifocal', 'code' => 'PRD-1', 'unit' => 'un', 'active' => true]);
    $inactive     = EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'Lente Inativa', 'unit' => 'un', 'active' => false]);
    $otherEntity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $otherProduct = EntityProduct::create(['entity_id' => $otherEntity->id, 'name' => 'Lente Multifocal outra clínica', 'unit' => 'un', 'active' => true]);

    $res = actingAsProductAdmin($this)->getJson(route('panel.stock.products.search', ['q' => 'multifocal']));

    $res->assertOk();
    $ids = collect($res->json('data'))->pluck('id');

    expect($ids)->toHaveCount(1)
        ->and($ids->first())->toBe($match->id)
        ->and($ids)->not->toContain($inactive->id)
        ->and($ids)->not->toContain($otherProduct->id)
        ->and($res->json('data.0.product_name'))->toBe('Lente Multifocal')
        ->and($res->json('data.0.label'))->toBe('Lente Multifocal (PRD-1)');
});

it('[GAP][REGRA DE NEGÓCIO] search() sem o módulo de estoque no plano retorna 403', function () {
    $entityWithoutFeature = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $planWithoutFeature   = Plan::factory()->create(['active' => true]);

    Subscription::factory()->create([
        'entity_id' => $entityWithoutFeature->id,
        'plan_id'   => $planWithoutFeature->id,
        'status'    => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(),
        'ends_at'   => now()->addMonth(),
    ]);

    $adminWithoutFeature      = User::factory()->create();
    $entityUserWithoutFeature = createEntityUser($entityWithoutFeature, $adminWithoutFeature, ClientRule::Admin->value);

    $res = actingAsProductAdmin($this, $adminWithoutFeature, $entityUserWithoutFeature)
        ->getJson(route('panel.stock.products.search', ['q' => 'lente']));

    $res->assertForbidden();
});

// ── App\Concerns\HasEntityCode — pré-requisito (fase 0) da migração de
// lentes IOL pro estoque. Um teste de RACE real exigiria dois processos/
// conexões concorrentes de verdade — fora do escopo de um teste de feature
// (mesmo critério já documentado em tests/Unit/Stock/StockServiceTest.php).
// Em vez disso, simula o efeito de uma corrida: insere via SQL cru
// (contornando o trait) exatamente o código que HasEntityCode COMPUTARIA a
// seguir, forçando uma colisão real de verdade no INSERT do model logo
// depois — exercita o catch+retry de App\Concerns\HasEntityCode::save() de
// ponta a ponta.

it('[GAP] HasEntityCode retry: colisão real de code (simulando corrida) não derruba o create — model tenta de novo e persiste com código diferente', function () {
    $first = EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'Produto 1', 'unit' => 'un', 'active' => true]);
    expect($first->code)->toBe('PRD-0000000001');

    // Insere direto no banco (sem passar pelo trait) o código que o
    // PRÓXIMO create() naturalmente computaria — simula outro processo
    // vencendo a corrida um instante antes.
    \DB::table('entity_products')->insert([
        'id'          => (string) \Illuminate\Support\Str::uuid(),
        'entity_id'   => $this->entity->id,
        'code'        => 'PRD-0000000002',
        'name'        => 'Produto colidente',
        'unit'        => 'un',
        'active'      => true,
        'created_at'  => now(),
        'updated_at'  => now(),
    ]);

    $second = EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'Produto 2', 'unit' => 'un', 'active' => true]);

    // Sem o retry, isso quebraria com QueryException (unique violation) —
    // com o retry, o model recalcula e usa PRD-0000000003.
    expect($second->code)->toBe('PRD-0000000003');
    expect(EntityProduct::where('entity_id', $this->entity->id)->count())->toBe(3);
});
