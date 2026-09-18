<?php

declare(strict_types=1);

use App\Enums\{ClientRule, FeatureKey, SubscriptionStatus};
use App\Models\{Entity, EntityProduct, Plan, PlanFeature, Subscription, User};
use Illuminate\Http\UploadedFile;

/**
 * Importação em massa de produtos via CSV (GAP fechado — revisão pós-Fase
 * 4, "melhorar o módulo de estoque") — smoke test HTTP de
 * App\Http\Controllers\Stock\ProductImportsController. Lógica de
 * parsing/validação já coberta em
 * tests/Unit/Stock/ProductImportServiceTest.php; aqui só confirma que as
 * rotas resolvem, respeitam a dupla trava (permission + feature) e o
 * fluxo preview → confirm entrega o resultado esperado ponta a ponta.
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

function actingAsImportAdmin($test, ?User $admin = null, $entityUser = null)
{
    return $test->actingAs($admin ?? $test->admin)->withSession(panelSession($entityUser ?? $test->adminEntityUser));
}

function makeCsvUpload(string $content): UploadedFile
{
    return UploadedFile::fake()->createWithContent('produtos.csv', $content);
}

it('index() renderiza a tela de importação', function () {
    $res = actingAsImportAdmin($this)->get(route('panel.stock.products.import.index'));

    $res->assertOk();
    $res->assertInertia(fn ($page) => $page->component('Panel/Stock/Products/Import'));
});

it('template() baixa o CSV modelo com header + exemplo', function () {
    $res = actingAsImportAdmin($this)->get(route('panel.stock.products.import.template'));

    $res->assertOk();
    $res->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    expect($res->streamedContent())->toContain('nome;unidade;sku');
});

it('[GAP] fluxo completo: preview() lê o CSV, confirm() cria os produtos de fato', function () {
    $csv = "nome;unidade;sku\nLente IOL;un;LENTE-1\nColírio;fr;";

    $previewRes = actingAsImportAdmin($this)->postJson(route('panel.stock.products.import.preview'), [
        'file' => makeCsvUpload($csv),
    ]);

    $previewRes->assertOk();
    expect($previewRes->json('valid'))->toHaveCount(2)
        ->and($previewRes->json('errors'))->toBe([]);

    $confirmRes = actingAsImportAdmin($this)->postJson(route('panel.stock.products.import.confirm'), [
        'rows' => $previewRes->json('valid'),
    ]);

    $confirmRes->assertOk();
    expect($confirmRes->json('created'))->toBe(2);
    expect(EntityProduct::where('entity_id', $this->entity->id)->count())->toBe(2);
    expect(EntityProduct::where('entity_id', $this->entity->id)->where('sku', 'LENTE-1')->exists())->toBeTrue();
});

it('[GAP] confirm() rejeita payload com linha sem nome/unidade (422 — validação do FormRequest)', function () {
    actingAsImportAdmin($this)->postJson(route('panel.stock.products.import.confirm'), [
        'rows' => [['name' => '', 'unit' => 'un']],
    ])->assertStatus(422)->assertJsonValidationErrors('rows.0.name');

    actingAsImportAdmin($this)->postJson(route('panel.stock.products.import.confirm'), [
        'rows' => [['name' => 'Produto', 'unit' => 'unidade-invalida']],
    ])->assertStatus(422)->assertJsonValidationErrors('rows.0.unit');
});

it('[REGRA DE NEGÓCIO] clínica sem o módulo de estoque no plano recebe 403 em toda rota de importação', function () {
    $entityNoModule = entityWithoutInventoryModule();
    $admin          = User::factory()->create();
    $entityUser     = createEntityUser($entityNoModule, $admin, ClientRule::Admin->value);

    $this->actingAs($admin)->withSession(panelSession($entityUser))
        ->get(route('panel.stock.products.import.index'), ['Accept' => 'application/json'])
        ->assertForbidden();
});
