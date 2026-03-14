<?php

use App\Enums\BillingCycle;
use App\Enums\FeatureKey;
use App\Models\Entity;
use App\Models\FeatureUsage;
use App\Models\Plan;
use App\Models\SubscriptionSetting;
use App\Services\SubscriptionService;
use App\Services\UsageMeterService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    SubscriptionSetting::setValue('trial_days', 7);

    $this->entity = Entity::factory()->create(['is_client' => false]);
    $this->plan   = Plan::factory()->create(['active' => true]);
    $this->sub    = app(SubscriptionService::class)->activate($this->entity, $this->plan, BillingCycle::Monthly);
    $this->meter  = app(UsageMeterService::class);
});

test('incrementa créditos mensais corretamente', function () {
    $this->meter->increment($this->entity->id, FeatureKey::AiMonthlyCredits, $this->sub->id, 5);
    $this->meter->increment($this->entity->id, FeatureKey::AiMonthlyCredits, $this->sub->id, 3);

    expect($this->meter->getCurrentUsage($this->entity->id, FeatureKey::AiMonthlyCredits, $this->sub->id))->toBe(8);
});

test('uso de créditos é isolado por período mensal', function () {
    // Mês anterior via factory
    FeatureUsage::factory()
        ->forFeature(FeatureKey::AiMonthlyCredits)
        ->previousMonth()
        ->create([
            'entity_id'       => $this->entity->id,
            'subscription_id' => $this->sub->id,
            'used'            => 100,
        ]);

    $this->meter->increment($this->entity->id, FeatureKey::AiMonthlyCredits, $this->sub->id, 10);

    expect($this->meter->getCurrentUsage($this->entity->id, FeatureKey::AiMonthlyCredits, $this->sub->id))->toBe(10);
});

test('getCurrentUsage retorna 0 sem registros', function () {
    expect($this->meter->getCurrentUsage($this->entity->id, FeatureKey::AiMonthlyCredits, $this->sub->id))->toBe(0);
});

test('subscription factory states funcionam corretamente', function () {
    $trial     = \App\Models\Subscription::factory()->trial(14)->make();
    $expired   = \App\Models\Subscription::factory()->expired()->make();
    $grace     = \App\Models\Subscription::factory()->inGracePeriod()->make();
    $lifetime  = \App\Models\Subscription::factory()->lifetime()->make();

    expect($trial->isOnTrial())->toBeTrue();
    expect($expired->isActive())->toBeFalse();
    expect($grace->inGracePeriod())->toBeTrue();
    expect($grace->hasAccess())->toBeTrue();
    expect($lifetime->isActive())->toBeTrue();
    expect($lifetime->ends_at)->toBeNull();
});
