<?php

use App\Models\Billing\Gateway;
use App\Models\Billing\TenantGatewaySetting;
use App\Models\Entity;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Billing\GatewayResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('gateway resolver uses pinned gateway first then tenant setting', function () {
    $entity = Entity::factory()->create(['is_client' => false]);
    $plan = Plan::factory()->create();

    $pagarme = Gateway::query()->create([
        'code' => 'pagarme',
        'name' => 'Pagar.me',
        'active' => true,
        'priority' => 10,
    ]);

    $asaas = Gateway::query()->create([
        'code' => 'asaas',
        'name' => 'Asaas',
        'active' => true,
        'priority' => 20,
    ]);

    TenantGatewaySetting::query()->create([
        'entity_id' => $entity->id,
        'setting_key' => 'default',
        'default_gateway_id' => $pagarme->id,
        'fallback_enabled' => false,
    ]);

    $subscription = Subscription::query()->create([
        'entity_id' => $entity->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'starts_at' => now(),
        'ends_at' => now()->addMonth(),
    ]);

    $resolver = app(GatewayResolver::class);

    expect($resolver->resolveForSubscription($subscription)->code())->toBe('pagarme');

    $subscription->update(['pinned_gateway' => $asaas->code]);

    expect($resolver->resolveForSubscription($subscription)->code())->toBe('asaas');
});

