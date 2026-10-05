<?php

use App\Exceptions\Billing\GatewayUnauthorizedException;
use App\Jobs\Billing\ProcessBillingWebhookJob;
use App\Models\Billing\WebhookEvent;
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

    // Recusa de autenticação é 401, não erro do servidor.
    $response->assertStatus(401);
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

test('webhook com token errado responde 401 pela rota (não 500)', function () {
    config(['billing.gateways.asaas.webhook_secret' => 'segredo-teste-com-mais-de-32-caracteres']);

    $this->withHeaders(['asaas-access-token' => 'token-errado'])
        ->postJson('/api/billing/webhooks/asaas', ['id' => 'evt_bad_token', 'event' => 'PAYMENT_RECEIVED'])
        ->assertStatus(401)
        ->assertJson(['ok' => false]);

    $this->assertDatabaseCount('webhook_events', 0);
});

test('credenciais do webhook (Authorization do Pagar.me, token do Asaas) não são gravadas nos headers do evento', function () {
    config([
        'billing.gateways.asaas.webhook_secret'   => 'segredo-teste-com-mais-de-32-caracteres',
        'billing.gateways.pagarme.webhook_secret' => 'easyeye:s3nh4-forte',
    ]);

    $asaas = $this->withHeaders(['asaas-access-token' => 'segredo-teste-com-mais-de-32-caracteres', 'x-request-id' => 'req-asaas-1'])
        ->postJson('/api/billing/webhooks/asaas', ['id' => 'evt_headers_001', 'event' => 'PAYMENT_CREATED'])
        ->assertOk();

    $pagarme = $this->withHeaders(['Authorization' => 'Basic ' . base64_encode('easyeye:s3nh4-forte')])
        ->postJson('/api/billing/webhooks/pagarme', ['id' => 'hook_headers_001', 'type' => 'charge.created', 'data' => ['id' => 'ch_headers_001', 'status' => 'pending']])
        ->assertOk();

    $asaasHeaders   = array_change_key_case(WebhookEvent::query()->findOrFail($asaas->json('event_id'))->headers);
    $pagarmeHeaders = array_change_key_case(WebhookEvent::query()->findOrFail($pagarme->json('event_id'))->headers);

    expect($asaasHeaders)->not->toHaveKey('asaas-access-token')
        ->and($asaasHeaders)->toHaveKey('x-request-id')
        ->and($pagarmeHeaders)->not->toHaveKey('authorization')
        ->and(json_encode($pagarmeHeaders))->not->toContain(base64_encode('easyeye:s3nh4-forte'));
});
