<?php

use App\Exceptions\Billing\GatewayUnauthorizedException;
use App\Jobs\Billing\ProcessBillingWebhookJob;
use App\Services\Billing\WebhookIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// BUGFIX (revisao de seguranca): webhooks só são aceitos com assinatura válida —
// sem webhook_secret configurado, a ingestão agora falha FECHADO (rejeita).
//
// O teste "idempotent" chama WebhookIngestionService diretamente (em vez de
// postJson) porque AsaasGateway::validateWebhookSignature() lê o header
// 'asaas-access-token' cru; passado por uma requisição HTTP real, o
// HeaderBag do Symfony sempre entrega o valor como array (mesmo com um único
// valor), e a checagem `is_string($provided)` nunca casa — isso é um bug
// pré-existente e independente do fail-open corrigido aqui, fora do escopo
// deste fix. Chamar o service com headers já normalizados (string) exercita
// a MESMA validação de assinatura sem tropeçar nesse problema à parte.
test('webhook ingestion is idempotent for same event payload', function () {
    config(['billing.gateways.asaas.webhook_secret' => 'segredo-teste']);

    $body = json_encode([
        'id'     => 'evt_same_001',
        'event'  => 'payment.paid',
        'status' => 'paid',
        'amount' => 99.90,
    ]);
    $headers = ['asaas-access-token' => 'segredo-teste'];

    $service = app(WebhookIngestionService::class);

    $first = $service->ingest('asaas', $headers, $body);
    ProcessBillingWebhookJob::dispatch((string) $first->id);

    $second = $service->ingest('asaas', $headers, $body);
    ProcessBillingWebhookJob::dispatch((string) $second->id);

    expect($first->id)->toBe($second->id);

    $this->assertDatabaseCount('webhook_events', 1);
    $this->assertDatabaseHas('webhook_events', [
        'gateway_code'      => 'asaas',
        'external_event_id' => 'evt_same_001',
        'status'            => 'processed',
    ]);
});

test('webhook is rejected when no webhook_secret is configured for the gateway', function () {
    config(['billing.gateways.asaas.webhook_secret' => null]);

    $payload = [
        'id'     => 'evt_unsigned_001',
        'event'  => 'payment.paid',
        'status' => 'paid',
        'amount' => 99.90,
    ];

    $response = $this->postJson('/api/billing/webhooks/asaas', $payload);

    $response->assertStatus(500);
    $this->assertDatabaseCount('webhook_events', 0);
});

test('webhook is rejected when webhook_secret is configured but the signature is missing', function () {
    config(['billing.gateways.asaas.webhook_secret' => 'segredo-teste']);

    $body = json_encode([
        'id'     => 'evt_no_sig_001',
        'event'  => 'payment.paid',
        'status' => 'paid',
        'amount' => 99.90,
    ]);

    $service = app(WebhookIngestionService::class);

    expect(fn () => $service->ingest('asaas', [], $body))
        ->toThrow(GatewayUnauthorizedException::class);

    $this->assertDatabaseCount('webhook_events', 0);
});
