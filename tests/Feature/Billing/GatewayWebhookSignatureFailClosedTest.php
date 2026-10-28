<?php

use App\DTOs\Billing\GatewayWebhookInputDTO;
use App\Services\Billing\GatewayCredentialResolver;
use App\Services\Billing\Gateways\{AbstractHttpGateway, AsaasGateway};
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// BUGFIX (revisao de seguranca): validateWebhookSignature() falhava ABERTO quando
// nenhum webhook_secret estava configurado (retornava true), permitindo que um
// atacante não autenticado forjasse webhooks de pagamento. Estes testes cobrem o
// fallback genérico em AbstractHttpGateway e a implementação concreta da Asaas,
// garantindo fail-closed sem quebrar o fluxo legítimo com secret configurado.

function buildGenericWebhookGateway(): AbstractHttpGateway
{
    return new class(new GatewayCredentialResolver()) extends AbstractHttpGateway {
        public function code(): string
        {
            return 'asaas';
        }
    };
}

function buildWebhookInput(array $headers, string $body): GatewayWebhookInputDTO
{
    return new GatewayWebhookInputDTO(
        gatewayCode: 'asaas',
        headers: $headers,
        body: $body,
        payload: json_decode($body, true) ?: [],
    );
}

describe('AbstractHttpGateway::validateWebhookSignature (fallback genérico)', function () {
    test('rejeita quando nenhum webhook_secret está configurado', function () {
        config(['billing.gateways.asaas.webhook_secret' => null]);

        $gateway = buildGenericWebhookGateway();
        $payload = buildWebhookInput(['x-signature' => 'qualquer-assinatura'], '{"foo":"bar"}');

        expect($gateway->validateWebhookSignature($payload))->toBeFalse();
    });

    test('aceita assinatura HMAC válida quando webhook_secret está configurado', function () {
        config(['billing.gateways.asaas.webhook_secret' => 'segredo-teste']);

        $gateway   = buildGenericWebhookGateway();
        $body      = '{"foo":"bar"}';
        $signature = hash_hmac('sha256', $body, 'segredo-teste');
        $payload   = buildWebhookInput(['x-signature' => $signature], $body);

        expect($gateway->validateWebhookSignature($payload))->toBeTrue();
    });

    test('rejeita assinatura inválida quando webhook_secret está configurado', function () {
        config(['billing.gateways.asaas.webhook_secret' => 'segredo-teste']);

        $gateway = buildGenericWebhookGateway();
        $payload = buildWebhookInput(['x-signature' => 'assinatura-forjada'], '{"foo":"bar"}');

        expect($gateway->validateWebhookSignature($payload))->toBeFalse();
    });

    test('aceita assinatura HMAC valida mesmo quando o header chega como ARRAY (formato real do Symfony HeaderBag::all())', function () {
        // BUGFIX (revisao de seguranca, achado de follow-up): WebhookController
        // usa $request->headers->all(), que SEMPRE devolve cada header como
        // array — mesmo de valor único. Sem normalizar isso no DTO, um webhook
        // REAL e corretamente assinado seria rejeitado (is_string() falso pra
        // array de 1 elemento) assim que um webhook_secret fosse configurado.
        config(['billing.gateways.asaas.webhook_secret' => 'segredo-teste']);

        $gateway   = buildGenericWebhookGateway();
        $body      = '{"foo":"bar"}';
        $signature = hash_hmac('sha256', $body, 'segredo-teste');
        // Formato real de Symfony\Component\HttpFoundation\HeaderBag::all().
        $payload = buildWebhookInput(['x-signature' => [$signature]], $body);

        expect($gateway->validateWebhookSignature($payload))->toBeTrue();
    });
});

describe('AsaasGateway::validateWebhookSignature', function () {
    test('rejeita quando nenhum webhook_secret está configurado', function () {
        config(['billing.gateways.asaas.webhook_secret' => null]);

        $gateway = new AsaasGateway(new GatewayCredentialResolver());
        $payload = buildWebhookInput(['asaas-access-token' => 'qualquer-token'], '{"event":"PAYMENT_RECEIVED"}');

        expect($gateway->validateWebhookSignature($payload))->toBeFalse();
    });

    test('aceita token correto quando webhook_secret está configurado', function () {
        config(['billing.gateways.asaas.webhook_secret' => 'token-correto']);

        $gateway = new AsaasGateway(new GatewayCredentialResolver());
        $payload = buildWebhookInput(['asaas-access-token' => 'token-correto'], '{"event":"PAYMENT_RECEIVED"}');

        expect($gateway->validateWebhookSignature($payload))->toBeTrue();
    });

    test('rejeita token incorreto quando webhook_secret está configurado', function () {
        config(['billing.gateways.asaas.webhook_secret' => 'token-correto']);

        $gateway = new AsaasGateway(new GatewayCredentialResolver());
        $payload = buildWebhookInput(['asaas-access-token' => 'token-errado'], '{"event":"PAYMENT_RECEIVED"}');

        expect($gateway->validateWebhookSignature($payload))->toBeFalse();
    });
});
