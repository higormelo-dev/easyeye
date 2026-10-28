<?php

declare(strict_types=1);

use App\Enums\{ClientRule, FeatureKey, SubscriptionStatus};
use App\Models\{Entity, EntityProduct, Notice, Plan, PlanFeature, Subscription, User};
use App\Services\Stock\{StockAlertService, StockService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// tests/Unit não herda TestCase::class + RefreshDatabase de Pest.php (só
// 'Feature' herda) — mesmo padrão dos demais testes de Unit/Stock.
uses(TestCase::class, RefreshDatabase::class);

/**
 * GAP fechado nesta revisão (Fases 1-2 só tinham badge/filtro na tela,
 * sem nenhum empurrão proativo) — App\Services\Stock\StockAlertService.
 */
beforeEach(function () {
    $this->service = app(StockAlertService::class);
    $this->entity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->plan    = Plan::factory()->create(['active' => true]);
    PlanFeature::factory()->enabled(FeatureKey::HasInventoryModule)->for($this->plan)->create();
    Subscription::factory()->create([
        'entity_id' => $this->entity->id, 'plan_id' => $this->plan->id, 'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
    ]);

    $this->admin           = User::factory()->create();
    $this->adminEntityUser = createEntityUser($this->entity, $this->admin, ClientRule::Admin->value, isOwner: true);
});

it('sem nada crítico, não cria aviso', function () {
    EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'Produto OK', 'unit' => 'un', 'min_qty' => 0, 'active' => true]);

    $notice = $this->service->checkEntity($this->entity);

    expect($notice)->toBeNull()
        ->and(Notice::query()->where('entity_id', $this->entity->id)->count())->toBe(0);
});

it('produto abaixo do mínimo gera aviso pinned com o owner como autor', function () {
    EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'Colírio', 'unit' => 'un', 'min_qty' => 10, 'qty_on_hand' => 0, 'active' => true]);

    $notice = $this->service->checkEntity($this->entity);

    expect($notice)->not->toBeNull()
        ->and($notice->pinned)->toBeTrue()
        ->and((string) $notice->author_id)->toBe((string) $this->adminEntityUser->id)
        ->and($notice->content)->toContain('Colírio')
        ->and($notice->content)->toContain(StockAlertService::MARKER);
});

it('lote vencendo em 30 dias entra no aviso; lote vencido marca prioridade urgente', function () {
    $product = EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'OPM', 'unit' => 'un', 'requires_lot' => true, 'active' => true]);
    $lot     = app(StockService::class)->findOrCreateLot($product, 'L1', now()->subDay()); // já vencido
    app(StockService::class)->manualIn($product, 5, 100.00, lot: $lot);

    $notice = $this->service->checkEntity($this->entity);

    expect($notice)->not->toBeNull()
        ->and($notice->priority)->toBe(1) // urgente — tem lote vencido
        ->and($notice->content)->toContain('L1')
        ->and($notice->content)->toContain('VENCIDO');
});

it('rodar de novo SUBSTITUI o aviso anterior (não acumula 1 por dia)', function () {
    EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'Colírio', 'unit' => 'un', 'min_qty' => 10, 'qty_on_hand' => 0, 'active' => true]);

    $this->service->checkEntity($this->entity);
    $this->service->checkEntity($this->entity);
    $this->service->checkEntity($this->entity);

    expect(Notice::query()->where('entity_id', $this->entity->id)->count())->toBe(1);
});

it('quando o problema é resolvido, o aviso anterior é removido (não fica órfão)', function () {
    $product = EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'Colírio', 'unit' => 'un', 'min_qty' => 10, 'qty_on_hand' => 0, 'active' => true]);
    $this->service->checkEntity($this->entity);
    expect(Notice::query()->where('entity_id', $this->entity->id)->count())->toBe(1);

    app(StockService::class)->manualIn($product, 20, 5.00); // repõe acima do mínimo

    $notice = $this->service->checkEntity($this->entity->fresh());
    expect($notice)->toBeNull()
        ->and(Notice::query()->where('entity_id', $this->entity->id)->count())->toBe(0);
});

it('clínica SEM o módulo de estoque no plano é ignorada (sem aviso, mesmo com produto crítico hipotético)', function () {
    $entityNoModule = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $planNoModule   = Plan::factory()->create(['active' => true]);
    Subscription::factory()->create([
        'entity_id' => $entityNoModule->id, 'plan_id' => $planNoModule->id, 'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
    ]);

    $notice = $this->service->checkEntity($entityNoModule);

    expect($notice)->toBeNull();
});

it('checkAllEntities() percorre várias clínicas e soma os avisos criados', function () {
    EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'Colírio', 'unit' => 'un', 'min_qty' => 10, 'qty_on_hand' => 0, 'active' => true]);

    $otherEntity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $otherPlan   = Plan::factory()->create(['active' => true]);
    PlanFeature::factory()->enabled(FeatureKey::HasInventoryModule)->for($otherPlan)->create();
    Subscription::factory()->create([
        'entity_id' => $otherEntity->id, 'plan_id' => $otherPlan->id, 'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
    ]);
    createEntityUser($otherEntity, User::factory()->create(), ClientRule::Admin->value, isOwner: true);
    EntityProduct::create(['entity_id' => $otherEntity->id, 'name' => 'Gaze', 'unit' => 'un', 'min_qty' => 5, 'qty_on_hand' => 0, 'active' => true]);

    expect($this->service->checkAllEntities())->toBe(2);
});
