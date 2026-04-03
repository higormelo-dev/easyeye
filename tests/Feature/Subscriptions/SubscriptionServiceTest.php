<?php

use App\Enums\{BillingCycle, SubscriptionStatus};
use App\Models\{Entity, Plan, Subscription, SubscriptionSetting};
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    SubscriptionSetting::setValue('trial_days', 7);
    SubscriptionSetting::setValue('grace_period_days', 3);

    $this->plan    = Plan::factory()->create(['slug' => 'pro', 'active' => true]);
    $this->entity  = Entity::factory()->create(['is_client' => false]); // sem auto-trial
    $this->service = app(SubscriptionService::class);
});

test('ativa assinatura mensal corretamente', function () {
    $sub = $this->service->activate($this->entity, $this->plan, BillingCycle::Monthly);

    expect($sub->status)->toBe(SubscriptionStatus::Active);
    expect($sub->ends_at)->not->toBeNull();
    expect(now()->diffInDays($sub->ends_at))->toBeBetween(28, 31);
});

test('ativa assinatura anual corretamente', function () {
    $sub = $this->service->activate($this->entity, $this->plan, BillingCycle::Yearly);

    expect(now()->diffInDays($sub->ends_at))->toBeBetween(364, 366);
});

test('assinatura lifetime tem ends_at nulo', function () {
    $sub = $this->service->activate($this->entity, $this->plan, BillingCycle::Lifetime);

    expect($sub->ends_at)->toBeNull();
    expect($sub->isActive())->toBeTrue();
});

test('ativar nova assinatura cancela a anterior', function () {
    $sub1 = $this->service->activate($this->entity, $this->plan, BillingCycle::Monthly);
    $this->service->activate($this->entity, $this->plan, BillingCycle::Yearly);

    $sub1->refresh();
    expect($sub1->status)->toBe(SubscriptionStatus::Cancelled);
    expect(Subscription::forEntity($this->entity->id)->accessible()->count())->toBe(1);
});

test('cancelamento adiciona período de graça', function () {
    $this->service->activate($this->entity, $this->plan);
    $sub = $this->service->cancel($this->entity);

    expect($sub->status)->toBe(SubscriptionStatus::Cancelled);
    expect($sub->grace_period_ends_at)->not->toBeNull();
    expect($sub->grace_period_ends_at->isFuture())->toBeTrue();
    expect($sub->inGracePeriod())->toBeTrue();
    expect($sub->hasAccess())->toBeTrue();
});

test('hasAccess retorna false sem assinatura', function () {
    expect($this->service->hasAccess($this->entity))->toBeFalse();
});

test('assinaturas vencidas são expiradas pelo scheduler', function () {
    $sub = $this->service->activate($this->entity, $this->plan, BillingCycle::Monthly);
    $sub->update(['ends_at' => now()->subDay()]);

    $count = $this->service->expireOverdue();

    expect($count)->toBe(1);
    $sub->refresh();
    expect($sub->status)->toBe(SubscriptionStatus::Expired);
});
