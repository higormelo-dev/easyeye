<?php

use App\Enums\SubscriptionStatus;
use App\Models\Entity;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionSetting;
use App\Services\TrialService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Garante configurações de trial no DB
    SubscriptionSetting::setValue('trial_days', 7);
    SubscriptionSetting::setValue('grace_period_days', 3);

    // Cria um plano básico para os testes
    $this->plan = Plan::factory()->create(['slug' => 'basico', 'active' => true, 'sort_order' => 1]);
});

test('trial é iniciado ao criar empresa cliente', function () {
    $entity = Entity::factory()->create(['is_client' => true]);

    expect(Subscription::forEntity($entity->id)->count())->toBe(1);
    expect(Subscription::forEntity($entity->id)->first()->status)->toBe(SubscriptionStatus::Trial);
});

test('trial não é iniciado para entidade não-cliente', function () {
    $entity = Entity::factory()->create(['is_client' => false]);

    expect(Subscription::forEntity($entity->id)->count())->toBe(0);
});

test('trial dura o número de dias configurado', function () {
    SubscriptionSetting::setValue('trial_days', 14);

    $entity = Entity::factory()->create(['is_client' => true]);
    $sub    = Subscription::forEntity($entity->id)->first();

    expect(now()->diffInDays($sub->trial_ends_at))->toBeBetween(13, 14);
});

test('trial manual pode ser iniciado pelo admin', function () {
    $entity  = Entity::factory()->create(['is_client' => true]);
    $service = app(TrialService::class);

    // Cancela o trial automático e inicia um manual com plano e dias específicos
    $sub = $service->startManualTrial($entity, $this->plan, 30);

    expect($sub->status)->toBe(SubscriptionStatus::Trial);
    expect(now()->diffInDays($sub->trial_ends_at))->toBeBetween(29, 30);
});

test('trials vencidos são expirados pelo scheduler', function () {
    $entity = Entity::factory()->create(['is_client' => true]);

    // Força expiração do trial
    Subscription::forEntity($entity->id)->update([
        'trial_ends_at' => now()->subDay(),
    ]);

    $expired = app(TrialService::class)->expireOverdueTrials();

    expect($expired)->toBe(1);
    expect(Subscription::forEntity($entity->id)->first()->status)->toBe(SubscriptionStatus::Expired);
});

test('trial expirado ganha período de graça', function () {
    $entity = Entity::factory()->create(['is_client' => true]);

    Subscription::forEntity($entity->id)->update(['trial_ends_at' => now()->subDay()]);
    app(TrialService::class)->expireOverdueTrials();

    $sub = Subscription::forEntity($entity->id)->first();
    expect($sub->grace_period_ends_at)->not->toBeNull();
    expect($sub->grace_period_ends_at->isFuture())->toBeTrue();
});
