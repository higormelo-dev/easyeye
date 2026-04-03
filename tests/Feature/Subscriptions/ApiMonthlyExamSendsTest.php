<?php

use App\Enums\{FeatureKey};
use App\Exceptions\FeatureDeniedException;
use App\Models\{Entity, FeatureUsage, Plan, PlanFeature, SubscriptionSetting};
use App\Services\{FeatureGateService, SubscriptionService};

beforeEach(function () {
    SubscriptionSetting::setValue('trial_days', 7);
    SubscriptionSetting::setValue('grace_period_days', 3);

    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->plan   = Plan::factory()->create(['active' => true]);
    $this->gate   = app(FeatureGateService::class);
    $this->subSvc = app(SubscriptionService::class);
});

// ── Dependência de HasApiIntegrator ──────────────────────────────────────────

test('bloqueado quando HasApiIntegrator está desativado, mesmo com limite configurado', function () {
    PlanFeature::factory()->disabled(FeatureKey::HasApiIntegrator)->for($this->plan)->create();
    PlanFeature::factory()->limit(FeatureKey::ApiMonthlyExamSends, 100)->for($this->plan)->create();

    $this->subSvc->activate($this->entity, $this->plan);

    expect($this->gate->can($this->entity->id, FeatureKey::ApiMonthlyExamSends))->toBeFalse();
});

test('bloqueado quando HasApiIntegrator não está configurado no plano', function () {
    // Sem nenhuma feature configurada
    $this->subSvc->activate($this->entity, $this->plan);

    expect($this->gate->can($this->entity->id, FeatureKey::ApiMonthlyExamSends))->toBeFalse();
});

// ── Ilimitado ────────────────────────────────────────────────────────────────

test('ilimitado quando HasApiIntegrator ativo e ApiMonthlyExamSends não configurado', function () {
    PlanFeature::factory()->enabled(FeatureKey::HasApiIntegrator)->for($this->plan)->create();

    $this->subSvc->activate($this->entity, $this->plan);

    $status = $this->gate->status($this->entity->id, FeatureKey::ApiMonthlyExamSends);

    expect($status->allowed)->toBeTrue();
    expect($status->isUnlimited)->toBeTrue();
});

test('ilimitado quando ApiMonthlyExamSends = 0 (zero = ilimitado)', function () {
    PlanFeature::factory()->enabled(FeatureKey::HasApiIntegrator)->for($this->plan)->create();
    PlanFeature::factory()->limit(FeatureKey::ApiMonthlyExamSends, 0)->for($this->plan)->create();

    $this->subSvc->activate($this->entity, $this->plan);

    $status = $this->gate->status($this->entity->id, FeatureKey::ApiMonthlyExamSends);

    expect($status->allowed)->toBeTrue();
    expect($status->isUnlimited)->toBeTrue();
});

// ── Limite configurado ────────────────────────────────────────────────────────

test('permitido quando ainda há envios disponíveis no mês', function () {
    PlanFeature::factory()->enabled(FeatureKey::HasApiIntegrator)->for($this->plan)->create();
    PlanFeature::factory()->limit(FeatureKey::ApiMonthlyExamSends, 10)->for($this->plan)->create();

    $sub = $this->subSvc->activate($this->entity, $this->plan);

    // Usa 5 dos 10
    $this->gate->increment($this->entity->id, FeatureKey::ApiMonthlyExamSends, 5);

    $status = $this->gate->status($this->entity->id, FeatureKey::ApiMonthlyExamSends);

    expect($status->allowed)->toBeTrue();
    expect($status->used)->toBe(5);
    expect($status->remaining)->toBe(5);
    expect($status->limit)->toBe(10);
});

test('bloqueado ao atingir o limite mensal', function () {
    PlanFeature::factory()->enabled(FeatureKey::HasApiIntegrator)->for($this->plan)->create();
    PlanFeature::factory()->limit(FeatureKey::ApiMonthlyExamSends, 3)->for($this->plan)->create();

    $this->subSvc->activate($this->entity, $this->plan);

    // Consome os 3 permitidos
    $this->gate->increment($this->entity->id, FeatureKey::ApiMonthlyExamSends, 3);

    expect($this->gate->can($this->entity->id, FeatureKey::ApiMonthlyExamSends))->toBeFalse();
});

test('canOrFail lança FeatureDeniedException ao atingir limite', function () {
    PlanFeature::factory()->enabled(FeatureKey::HasApiIntegrator)->for($this->plan)->create();
    PlanFeature::factory()->limit(FeatureKey::ApiMonthlyExamSends, 2)->for($this->plan)->create();

    $this->subSvc->activate($this->entity, $this->plan);

    $this->gate->increment($this->entity->id, FeatureKey::ApiMonthlyExamSends, 2);

    expect(fn () => $this->gate->canOrFail($this->entity->id, FeatureKey::ApiMonthlyExamSends))
        ->toThrow(FeatureDeniedException::class);
});

// ── Isolamento mensal ─────────────────────────────────────────────────────────

test('uso do mês anterior não afeta o mês atual', function () {
    PlanFeature::factory()->enabled(FeatureKey::HasApiIntegrator)->for($this->plan)->create();
    PlanFeature::factory()->limit(FeatureKey::ApiMonthlyExamSends, 5)->for($this->plan)->create();

    $sub = $this->subSvc->activate($this->entity, $this->plan);

    // Simula 5 envios no mês anterior
    FeatureUsage::factory()
        ->forFeature(FeatureKey::ApiMonthlyExamSends)
        ->previousMonth()
        ->create([
            'entity_id'       => $this->entity->id,
            'subscription_id' => $sub->id,
            'used'            => 5,
        ]);

    // Mês atual: ainda 0 usos → permitido
    expect($this->gate->can($this->entity->id, FeatureKey::ApiMonthlyExamSends))->toBeTrue();

    $status = $this->gate->status($this->entity->id, FeatureKey::ApiMonthlyExamSends);
    expect($status->used)->toBe(0);
    expect($status->remaining)->toBe(5);
});

// ── canAndIncrement ───────────────────────────────────────────────────────────

test('canAndIncrement retorna true e incrementa quando há créditos', function () {
    PlanFeature::factory()->enabled(FeatureKey::HasApiIntegrator)->for($this->plan)->create();
    PlanFeature::factory()->limit(FeatureKey::ApiMonthlyExamSends, 5)->for($this->plan)->create();

    $this->subSvc->activate($this->entity, $this->plan);

    $result = $this->gate->canAndIncrement($this->entity->id, FeatureKey::ApiMonthlyExamSends);

    expect($result)->toBeTrue();

    $status = $this->gate->status($this->entity->id, FeatureKey::ApiMonthlyExamSends);
    expect($status->used)->toBe(1);
});

test('canAndIncrement retorna false sem incrementar quando esgotado', function () {
    PlanFeature::factory()->enabled(FeatureKey::HasApiIntegrator)->for($this->plan)->create();
    PlanFeature::factory()->limit(FeatureKey::ApiMonthlyExamSends, 2)->for($this->plan)->create();

    $this->subSvc->activate($this->entity, $this->plan);

    $this->gate->increment($this->entity->id, FeatureKey::ApiMonthlyExamSends, 2);

    $result = $this->gate->canAndIncrement($this->entity->id, FeatureKey::ApiMonthlyExamSends);

    expect($result)->toBeFalse();

    $status = $this->gate->status($this->entity->id, FeatureKey::ApiMonthlyExamSends);
    expect($status->used)->toBe(2); // não incrementou
});
