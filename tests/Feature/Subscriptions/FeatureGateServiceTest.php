<?php

use App\Enums\FeatureKey;
use App\Models\{Entity, Plan, PlanFeature, SubscriptionSetting};
use App\Services\{FeatureGateService, SubscriptionService};
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    SubscriptionSetting::setValue('trial_days', 7);
    SubscriptionSetting::setValue('grace_period_days', 3);

    $this->entity = Entity::factory()->create(['is_client' => false]);
    $this->plan   = Plan::factory()->create(['active' => true]);
    $this->gate   = app(FeatureGateService::class);
    $this->subSvc = app(SubscriptionService::class);
});

test('can retorna false sem assinatura', function () {
    expect($this->gate->can($this->entity->id, FeatureKey::MaxPatients))->toBeFalse();
});

test('feature booleana habilitada retorna true', function () {
    PlanFeature::factory()->enabled(FeatureKey::HasAiExamAssistant)->for($this->plan)->create();
    $this->subSvc->activate($this->entity, $this->plan);

    expect($this->gate->can($this->entity->id, FeatureKey::HasAiExamAssistant))->toBeTrue();
});

test('feature booleana desabilitada retorna false', function () {
    PlanFeature::factory()->disabled(FeatureKey::HasAiExamAssistant)->for($this->plan)->create();
    $this->subSvc->activate($this->entity, $this->plan);

    expect($this->gate->can($this->entity->id, FeatureKey::HasAiExamAssistant))->toBeFalse();
});

test('limite numérico zero significa ilimitado', function () {
    PlanFeature::factory()->limit(FeatureKey::MaxPatients, 0)->for($this->plan)->create();
    $this->subSvc->activate($this->entity, $this->plan);

    expect($this->gate->can($this->entity->id, FeatureKey::MaxPatients))->toBeTrue();
});

test('feature não definida no plano retorna false', function () {
    $this->subSvc->activate($this->entity, $this->plan);

    expect($this->gate->can($this->entity->id, FeatureKey::HasAiReportDrafting))->toBeFalse();
});
