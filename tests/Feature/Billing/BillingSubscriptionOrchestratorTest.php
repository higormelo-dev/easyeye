<?php

use App\Enums\BillingCycle;
use App\Models\Entity;
use App\Models\Plan;
use App\Services\Billing\BillingSubscriptionOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('orchestrator activates subscription and persists invoice payment and attempt', function () {
    $entity = Entity::factory()->create([
        'is_client' => false,
        'name' => 'Clinica Orchestrator',
        'email' => 'billing@example.com',
    ]);

    $plan = Plan::factory()->create([
        'price' => 129.90,
        'active' => true,
    ]);

    Http::fake([
        'https://api.asaas.com/v3/customers' => Http::response([
            'id' => 'cus_test_123',
        ], 200),
        'https://api.asaas.com/v3/subscriptions' => Http::response([
            'id' => 'sub_test_123',
            'status' => 'active',
            'customer' => 'cus_test_123',
        ], 200),
        'https://api.asaas.com/v3/payments' => Http::response([
            'id' => 'pay_test_123',
            'status' => 'paid',
            'amount' => 129.90,
        ], 200),
    ]);

    $subscription = app(BillingSubscriptionOrchestrator::class)
        ->activateWithGateway($entity, $plan, BillingCycle::Monthly, 'asaas');

    $this->assertDatabaseCount('subscriptions', 1);
    $this->assertDatabaseCount('invoices', 1);
    $this->assertDatabaseCount('payments', 1);
    $this->assertDatabaseCount('payment_attempts', 1);

    $this->assertDatabaseHas('subscriptions', [
        'id' => $subscription->id,
        'gateway' => 'asaas',
        'pinned_gateway' => 'asaas',
        'billing_state' => 'paid',
    ]);

    $this->assertDatabaseHas('invoices', [
        'subscription_id' => $subscription->id,
        'status' => 'paid',
        'gateway_code' => 'asaas',
    ]);

    $this->assertDatabaseHas('payments', [
        'subscription_id' => $subscription->id,
        'status' => 'paid',
        'gateway_code' => 'asaas',
        'external_payment_id' => 'pay_test_123',
    ]);
});

