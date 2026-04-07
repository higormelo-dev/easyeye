<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('webhook ingestion is idempotent for same event payload', function () {
    $payload = [
        'id' => 'evt_same_001',
        'event' => 'payment.paid',
        'status' => 'paid',
        'amount' => 99.90,
    ];

    $first = $this->postJson('/api/billing/webhooks/asaas', $payload);
    $second = $this->postJson('/api/billing/webhooks/asaas', $payload);

    $first->assertOk()->assertJson(['ok' => true]);
    $second->assertOk()->assertJson(['ok' => true]);

    $this->assertDatabaseCount('webhook_events', 1);
    $this->assertDatabaseHas('webhook_events', [
        'gateway_code' => 'asaas',
        'external_event_id' => 'evt_same_001',
        'status' => 'processed',
    ]);
});

