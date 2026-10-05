<?php

declare(strict_types=1);

use App\DTOs\Billing\{CancelSubscriptionDTO, CreateChargeDTO, CreateSubscriptionDTO, CustomerDTO, GatewayWebhookInputDTO};
use App\Enums\Billing\PaymentStatus;
use App\Exceptions\Billing\GatewayIntegrationException;
use App\Models\Entity;
use App\Services\Billing\GatewayCredentialResolver;
use App\Services\Billing\Gateways\PagBankGateway;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Contrato do PagBank com a API de Pedidos (Order), conferido na
 * documentação oficial:
 * - POST /orders, Bearer, x-idempotency-key: https://developer.pagbank.com.br/reference/criar-pedido
 * - Boleto (holder com endereço): https://developer.pagbank.com.br/reference/criar-pagar-pedido-com-boleto
 * - Pix (charges[].payment_method PIX): https://developer.pagbank.com.br/reference/criar-pedido-com-qr-code-pix-v2
 * - Webhook (corpo = pedido): https://developer.pagbank.com.br/reference/webhooks
 * - x-authenticity-token: https://developer.pagbank.com.br/reference/confirmar-autenticidade-da-notificacao
 * - Estorno (CANCELED + summary.refunded): https://developer.pagbank.com.br/reference/cancelar-pagamento
 * - Erros (error_messages): https://developer.pagbank.com.br/reference/codigos-de-erro-order.
 *
 * Os payloads abaixo são os exemplos da documentação, com os nossos ids.
 */
beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-05 10:00:00', 'America/Sao_Paulo'));
    Http::preventStrayRequests();

    config([
        'app.url'                                 => 'https://app.easyeye.test',
        'billing.gateways.pagbank.base_url'       => 'https://api.pagseguro.com',
        'billing.gateways.pagbank.secret'         => 'tok_api_pagbank',
        'billing.gateways.pagbank.webhook_secret' => null,
    ]);
    url()->forceRootUrl('https://app.easyeye.test');
    url()->forceScheme('https');
});

afterEach(fn () => Carbon::setTestNow());

function pbGateway(): PagBankGateway
{
    return new PagBankGateway(new GatewayCredentialResolver());
}

function pbAddress(): array
{
    return [
        'street'      => 'Avenida Brigadeiro Faria Lima',
        'number'      => '1384',
        'complement'  => 'apto 12',
        'locality'    => 'Pinheiros',
        'city'        => 'São Paulo',
        'region_code' => 'SP',
        'postal_code' => '01452-002',
    ];
}

function pbChargeDto(array $overrides = []): CreateChargeDTO
{
    $args = array_replace([
        'entityId'       => '9d6b0c1e-0000-4000-8000-000000000001',
        'invoiceId'      => '9d6b0c1e-0000-4000-8000-0000000000aa',
        'subscriptionId' => '9d6b0c1e-0000-4000-8000-0000000000bb',
        'customerId'     => '9d6b0c1e-0000-4000-8000-000000000001',
        'amount'         => 343.43,
        'currency'       => 'BRL',
        'description'    => 'Fatura INV-2026-0001',
        'dueDate'        => '2026-10-20',
        'paymentMethod'  => null,
        'metadata'       => [
            'customer_name' => 'Clínica Visão Clara',
            'email'         => 'financeiro@visaoclara.test',
            'document'      => '11.222.333/0001-81',
            'phone'         => '(34) 99999-9998',
            'address'       => pbAddress(),
        ],
        'idempotencyKey' => 'activation:9d6b0c1e:1',
    ], $overrides);

    return new CreateChargeDTO(...$args);
}

/** Resposta do POST /orders com boleto (exemplo da doc). */
function pbBoletoOrder(string $status = 'WAITING'): array
{
    return [
        'id'           => 'ORDE_2FAC517E-0969-4D10-BF5F-601CBA2D1063',
        'reference_id' => '9d6b0c1e-0000-4000-8000-0000000000aa',
        'created_at'   => '2026-10-05T10:00:01.145-03:00',
        'customer'     => ['name' => 'Clínica Visão Clara', 'email' => 'financeiro@visaoclara.test', 'tax_id' => '11222333000181'],
        'items'        => [['reference_id' => '9d6b0c1e-0000-4000-8000-0000000000aa', 'name' => 'Fatura INV-2026-0001', 'quantity' => 1, 'unit_amount' => 34343]],
        'charges'      => [[
            'id'               => 'CHAR_60269EC9-9723-444D-AA49-C42813AF9613',
            'reference_id'     => '9d6b0c1e-0000-4000-8000-0000000000aa',
            'status'           => $status,
            'created_at'       => '2026-10-05T10:00:01.356-03:00',
            'description'      => 'Fatura INV-2026-0001',
            'amount'           => ['value' => 34343, 'currency' => 'BRL', 'summary' => ['total' => 34343, 'paid' => 0, 'refunded' => 0]],
            'payment_response' => $status === 'DECLINED'
                ? ['code' => '10000', 'message' => 'NAO AUTORIZADO PELO PAGSEGURO']
                : ['code' => '20000', 'message' => 'SUCESSO'],
            'payment_method' => [
                'type'   => 'BOLETO',
                'boleto' => [
                    'id'                => '6DD9494E-463C-4689-9436-AA3ADA873214',
                    'barcode'           => '08197081080010000001701152240436612400000034343',
                    'formatted_barcode' => '08197.08108 00100.000017 01152.240436 6 12400000034343',
                    'due_date'          => '2026-10-20',
                ],
            ],
            'links' => [
                ['rel' => 'SELF', 'href' => 'https://boleto.pagseguro.com.br/6dd9494e-463c-4689-9436-aa3ada873214.pdf', 'media' => 'application/pdf', 'type' => 'GET'],
                ['rel' => 'SELF', 'href' => 'https://boleto.pagseguro.com.br/6dd9494e-463c-4689-9436-aa3ada873214.png', 'media' => 'image/png', 'type' => 'GET'],
                ['rel' => 'SELF', 'href' => 'https://api.pagseguro.com/charges/CHAR_60269EC9-9723-444D-AA49-C42813AF9613', 'media' => 'application/json', 'type' => 'GET'],
            ],
        ]],
        'notification_urls' => ['https://app.easyeye.test/api/billing/webhooks/pagbank'],
        'links'             => [
            ['rel' => 'SELF', 'href' => 'https://api.pagseguro.com/orders/ORDE_2FAC517E-0969-4D10-BF5F-601CBA2D1063', 'media' => 'application/json', 'type' => 'GET'],
            ['rel' => 'PAY', 'href' => 'https://api.pagseguro.com/orders/ORDE_2FAC517E-0969-4D10-BF5F-601CBA2D1063/pay', 'media' => 'application/json', 'type' => 'POST'],
        ],
    ];
}

/** Pedido com Pix (exemplo "Response - WAITING" / "Webhook - PAID" da doc). */
function pbPixOrder(string $status = 'WAITING', int $refunded = 0): array
{
    return [
        'id'           => 'ORDE_3D560F48-E086-4F3C-A5A1-B7AB7BEACC2C',
        'reference_id' => '9d6b0c1e-0000-4000-8000-0000000000aa',
        'created_at'   => '2026-10-05T10:00:01.568-03:00',
        'customer'     => ['name' => 'Clínica Visão Clara', 'email' => 'financeiro@visaoclara.test', 'tax_id' => '11222333000181'],
        'charges'      => [array_filter([
            'id'           => 'CHAR_114DB991-F5EA-496A-8D2C-4497F53CED22',
            'reference_id' => '9d6b0c1e-0000-4000-8000-0000000000aa',
            'status'       => $status,
            'created_at'   => '2026-10-05T10:00:02.091-03:00',
            'paid_at'      => $status === 'PAID' || $refunded > 0 ? '2026-10-06T08:12:00.846-03:00' : null,
            'description'  => 'Fatura INV-2026-0001',
            'amount'       => ['value' => 20534, 'currency' => 'BRL', 'summary' => [
                'total'    => 20534,
                'paid'     => $status === 'PAID' || $refunded > 0 ? 20534 : 0,
                'refunded' => $refunded,
            ]],
            'payment_response' => ['code' => '20000', 'message' => 'SUCESSO'],
            'payment_method'   => ['type' => 'PIX', 'pix' => ['expiration_date' => '2026-10-20T23:59:59.000-03:00']],
            'metadata'         => ['ps_order_id' => 'ORDE_3D560F48-E086-4F3C-A5A1-B7AB7BEACC2C'],
            'links'            => [
                ['rel' => 'SELF', 'href' => 'https://sandbox.api.pagseguro.com/charges/CHAR_114DB991-F5EA-496A-8D2C-4497F53CED22', 'media' => 'application/json', 'type' => 'GET'],
                ['rel' => 'QRCODE.PNG', 'href' => 'https://sandbox.api.pagseguro.com/qrcode/QRCO_0F63EB5F-61B8-4466-A4C9-7F824193C234/png', 'media' => 'image/png', 'type' => 'GET'],
                ['rel' => 'QRCODE.BASE64', 'href' => 'https://sandbox.api.pagseguro.com/qrcode/QRCO_0F63EB5F-61B8-4466-A4C9-7F824193C234/base64', 'media' => 'text/plain', 'type' => 'GET'],
            ],
            'qr_code' => [
                'id'   => 'QRCO_0F63EB5F-61B8-4466-A4C9-7F824193C234',
                'text' => '00020101021226850014br.gov.bcb.pix2563api-h.pagseguro.com/pix/v2/0F63EB5F-61B8-4466-A4C9-7F824193C2345204899953039865802BR5921Pagseguro Internet SA6009SAO PAULO62070503***63045677',
            ],
        ], fn ($value) => $value !== null)],
        'notification_urls' => ['https://app.easyeye.test/api/billing/webhooks/pagbank'],
    ];
}

function pbWebhook(array $payload, ?string $token = 'tok_api_pagbank'): GatewayWebhookInputDTO
{
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return new GatewayWebhookInputDTO(
        gatewayCode: 'pagbank',
        headers: $token === null ? [] : ['x-authenticity-token' => [hash('sha256', $token . '-' . $body)]],
        body: $body,
        payload: $payload,
        externalEventId: pbGateway()->webhookEventKey($payload),
    );
}

describe('createCharge (POST /orders)', function () {
    it('cria o pedido com boleto: endpoint, Bearer, idempotência, comprador, holder com endereço e notification_urls', function () {
        Http::fake(['https://api.pagseguro.com/orders' => Http::response(pbBoletoOrder(), 201)]);

        $result = pbGateway()->createCharge(pbChargeDto());

        expect($result->success)->toBeTrue()
            ->and($result->externalPaymentId)->toBe('ORDE_2FAC517E-0969-4D10-BF5F-601CBA2D1063')
            ->and($result->status)->toBe('pending')
            ->and(PaymentStatus::fromGatewayStatus($result->status))->toBe(PaymentStatus::Pending)
            ->and($result->amount)->toBe(343.43)
            ->and($result->paymentUrl)->toBe('https://boleto.pagseguro.com.br/6dd9494e-463c-4689-9436-aa3ada873214.pdf');

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $request->method() === 'POST'
                && $request->url() === 'https://api.pagseguro.com/orders'
                && $request->header('Authorization')[0] === 'Bearer tok_api_pagbank'
                // A chave tem ":" (fora de [\w-]+): vai o sha256 dela.
                && $request->header('X-Idempotency-Key')[0] === hash('sha256', 'activation:9d6b0c1e:1')
                && $body['reference_id'] === '9d6b0c1e-0000-4000-8000-0000000000aa'
                && $body['customer'] === [
                    'name'   => 'Clínica Visão Clara',
                    'email'  => 'financeiro@visaoclara.test',
                    'tax_id' => '11222333000181',
                    'phones' => [['country' => '55', 'area' => '34', 'number' => '999999998', 'type' => 'MOBILE']],
                ]
                && $body['items'][0]['unit_amount'] === 34343
                && $body['items'][0]['quantity'] === 1
                && $body['charges'][0]['reference_id'] === '9d6b0c1e-0000-4000-8000-0000000000aa'
                && $body['charges'][0]['amount'] === ['value' => 34343, 'currency' => 'BRL']
                && $body['charges'][0]['payment_method']['type'] === 'BOLETO'
                && $body['charges'][0]['payment_method']['boleto']['due_date'] === '2026-10-20'
                && $body['charges'][0]['payment_method']['boleto']['holder']['tax_id'] === '11222333000181'
                && $body['charges'][0]['payment_method']['boleto']['holder']['address'] === [
                    'street'      => 'Avenida Brigadeiro Faria Lima',
                    'number'      => '1384',
                    'complement'  => 'apto 12',
                    'locality'    => 'Pinheiros',
                    'city'        => 'São Paulo',
                    'region'      => 'SP',
                    'region_code' => 'SP',
                    'country'     => 'BRA',
                    'postal_code' => '01452002',
                ]
                && $body['notification_urls'] === ['https://app.easyeye.test/api/billing/webhooks/pagbank'];
        });
    });

    it('nunca usa o endpoint antigo /charges (cobrança avulsa) para o corpo de pedido', function () {
        Http::fake(['https://api.pagseguro.com/orders' => Http::response(pbBoletoOrder(), 201)]);

        pbGateway()->createCharge(pbChargeDto());

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/charges'));
    });

    it('Pix: payment_method PIX com expiration_date no fim do dia do vencimento (Brasília) e link do QR Code', function () {
        Http::fake(['https://api.pagseguro.com/orders' => Http::response(pbPixOrder(), 201)]);

        $result = pbGateway()->createCharge(pbChargeDto(['paymentMethod' => 'pix', 'amount' => 205.34]));

        expect($result->success)->toBeTrue()
            ->and($result->externalPaymentId)->toBe('ORDE_3D560F48-E086-4F3C-A5A1-B7AB7BEACC2C')
            ->and($result->status)->toBe('pending')
            ->and($result->amount)->toBe(205.34)
            ->and($result->paymentUrl)->toBe('https://sandbox.api.pagseguro.com/qrcode/QRCO_0F63EB5F-61B8-4466-A4C9-7F824193C234/png');

        Http::assertSent(fn (Request $request) => $request['charges'][0]['payment_method'] === [
            'type' => 'PIX',
            'pix'  => ['expiration_date' => '2026-10-20T23:59:59-03:00'],
        ] && $request['charges'][0]['amount']['value'] === 20534);
    });

    it('Pix antigo (qr_codes no pedido, sem cobrança): pendente, valor e QRCODE.PNG do qr_codes', function () {
        Http::fake(['https://api.pagseguro.com/orders' => Http::response([
            'id'       => 'ORDE_F87334AC-BB8B-42E2-AA85-8579F70AA328',
            'charges'  => [],
            'qr_codes' => [[
                'id'              => 'QRCO_86FE511B-E945-4FE1-BB5D-297974C0DB74',
                'expiration_date' => '2026-10-20T23:59:59.000-03:00',
                'amount'          => ['value' => 500],
                'text'            => '00020101021226600016BR.COM.PAGSEGURO',
                'links'           => [
                    ['rel' => 'QRCODE.PNG', 'href' => 'https://sandbox.api.pagseguro.com/qrcode/QRCO_86FE511B-E945-4FE1-BB5D-297974C0DB74/png', 'media' => 'image/png', 'type' => 'GET'],
                    ['rel' => 'QRCODE.BASE64', 'href' => 'https://sandbox.api.pagseguro.com/qrcode/QRCO_86FE511B-E945-4FE1-BB5D-297974C0DB74/base64', 'media' => 'text/plain', 'type' => 'GET'],
                ],
            ]],
        ], 201)]);

        $result = pbGateway()->createCharge(pbChargeDto(['paymentMethod' => 'pix']));

        expect($result->status)->toBe('pending')
            ->and($result->amount)->toBe(5.0)
            ->and($result->paymentUrl)->toBe('https://sandbox.api.pagseguro.com/qrcode/QRCO_86FE511B-E945-4FE1-BB5D-297974C0DB74/png');
    });

    it('sem endereço completo do pagador, a cobrança sai em Pix (boleto exige holder.address)', function () {
        Http::fake(['https://api.pagseguro.com/orders' => Http::response(pbPixOrder(), 201)]);

        $address = pbAddress();
        unset($address['postal_code']);

        pbGateway()->createCharge(pbChargeDto(['metadata' => [
            'customer_name' => 'Clínica Visão Clara',
            'email'         => 'financeiro@visaoclara.test',
            'document'      => '11222333000181',
            'address'       => $address,
        ]]));

        Http::assertSent(fn (Request $request) => $request['charges'][0]['payment_method']['type'] === 'PIX'
            && ! isset($request['customer']['phones']));
    });

    it('sem endereço na metadata, usa o cadastro da empresa para o boleto', function () {
        $entity = Entity::factory()->make([
            'address'  => 'Rua Doutor Antonio Bento',
            'number'   => '123',
            'district' => 'Santo Amaro',
            'city'     => 'São Paulo',
            'state'    => 'SP',
            'zipcode'  => '04750001',
        ]);
        $entity->skipAutoTrial = true;
        $entity->save();

        Http::fake(['https://api.pagseguro.com/orders' => Http::response(pbBoletoOrder(), 201)]);

        pbGateway()->createCharge(pbChargeDto([
            'entityId' => (string) $entity->id,
            'metadata' => ['customer_name' => 'Clínica Visão Clara', 'email' => 'financeiro@visaoclara.test', 'document' => '11222333000181'],
        ]));

        Http::assertSent(function (Request $request) {
            $address = $request['charges'][0]['payment_method']['boleto']['holder']['address'] ?? [];

            return $request['charges'][0]['payment_method']['type'] === 'BOLETO'
                && $address['postal_code'] === '04750001'
                && $address['region_code'] === 'SP'
                && $address['locality'] === 'SANTO AMARO';
        });
    });

    it('chave de idempotência já no formato aceito vai como está', function () {
        Http::fake(['https://api.pagseguro.com/orders' => Http::response(pbBoletoOrder(), 201)]);

        pbGateway()->createCharge(pbChargeDto(['idempotencyKey' => 'renew_9d6b0c1e-2']));

        Http::assertSent(fn (Request $request) => $request->header('X-Idempotency-Key')[0] === 'renew_9d6b0c1e-2');
    });

    it('sandbox: base_url https://sandbox.api.pagseguro.com', function () {
        config(['billing.gateways.pagbank.base_url' => 'https://sandbox.api.pagseguro.com']);
        Http::fake(['https://sandbox.api.pagseguro.com/orders' => Http::response(pbBoletoOrder(), 201)]);

        expect(pbGateway()->createCharge(pbChargeDto())->success)->toBeTrue();
    });

    it('sem URL https, o pedido vai sem notification_urls (o PagBank exige SSL)', function () {
        config(['billing.gateways.pagbank.notification_url' => 'http://localhost/api/billing/webhooks/pagbank']);
        Http::fake(['https://api.pagseguro.com/orders' => Http::response(pbBoletoOrder(), 201)]);

        pbGateway()->createCharge(pbChargeDto());

        Http::assertSent(fn (Request $request) => ! array_key_exists('notification_urls', $request->data()));
    });

    it('cobrança DECLINED (análise de risco) volta como failed — inutilizável', function () {
        Http::fake(['https://api.pagseguro.com/orders' => Http::response(pbBoletoOrder('DECLINED'), 201)]);

        $result = pbGateway()->createCharge(pbChargeDto());

        expect($result->success)->toBeTrue()
            ->and($result->status)->toBe('failed')
            ->and(PaymentStatus::fromGatewayStatus($result->status)->isUnusable())->toBeTrue();
    });

    it('erro 400 da API: falha com o status HTTP e a mensagem de error_messages', function () {
        Http::fake(['https://api.pagseguro.com/orders' => Http::response([
            'error_messages' => [[
                'code'           => '40002',
                'description'    => 'invalid_parameter',
                'parameter_name' => 'charges[0].payment_method.boleto.holder.address.postal_code',
            ]],
        ], 400)]);

        $result = pbGateway()->createCharge(pbChargeDto());

        expect($result->success)->toBeFalse()
            ->and($result->errorCode)->toBe('400')
            ->and($result->errorMessage)->toContain('40002')
            ->and($result->errorMessage)->toContain('holder.address.postal_code');
    });

    it('409 (chave de idempotência em uso) é falha com o status', function () {
        Http::fake(['https://api.pagseguro.com/orders' => Http::response([
            'error_messages' => [['code' => '40005', 'description' => 'idempotency_key_in_use']],
        ], 409)]);

        $result = pbGateway()->createCharge(pbChargeDto());

        expect($result->success)->toBeFalse()
            ->and($result->errorCode)->toBe('409')
            ->and($result->errorMessage)->toContain('idempotency_key_in_use');
    });

    it('sem CPF/CNPJ do pagador nem chama a API (customer.tax_id é obrigatório)', function () {
        Http::fake();

        $result = pbGateway()->createCharge(pbChargeDto(['metadata' => ['customer_name' => 'Clínica', 'document' => null]]));

        expect($result->success)->toBeFalse()
            ->and($result->errorCode)->toBe('422');

        Http::assertNothingSent();
    });
});

describe('renovação local: cliente e assinatura sem chamada à API', function () {
    it('upsertCustomer devolve o id da empresa; createSubscription não cria recorrência; cancelSubscription é local', function () {
        Http::fake();
        $gateway = pbGateway();

        $customerId = $gateway->upsertCustomer(new CustomerDTO(
            entityId: 'ent-1',
            name: 'Clínica',
            email: 'a@b.test',
            document: '11222333000181',
            phone: null,
        ));

        $subscription = $gateway->createSubscription(new CreateSubscriptionDTO(
            entityId: 'ent-1',
            subscriptionId: 'sub-1',
            planId: 'plan-1',
            customerId: $customerId,
            amount: 299.90,
            currency: 'BRL',
            interval: 'month',
        ));

        $cancel = $gateway->cancelSubscription(new CancelSubscriptionDTO(
            entityId: 'ent-1',
            subscriptionId: 'sub-1',
            externalSubscriptionId: 'qualquer',
        ));

        expect($customerId)->toBe('ent-1')
            ->and($subscription->success)->toBeTrue()
            ->and($subscription->externalSubscriptionId)->toBeNull()
            ->and($gateway->subscriptionIssuesFirstCharge())->toBeFalse()
            ->and($cancel->success)->toBeTrue()
            ->and($cancel->status)->toBe('cancelled');

        Http::assertNothingSent();
    });
});

describe('fetchPayment', function () {
    it('pedido (ORDE_) consulta GET /orders/{id}; cobrança (CHAR_) consulta GET /charges/{id}', function () {
        Http::fake([
            'https://api.pagseguro.com/orders/ORDE_2FAC517E-0969-4D10-BF5F-601CBA2D1063'  => Http::response(pbBoletoOrder()),
            'https://api.pagseguro.com/charges/CHAR_60269EC9-9723-444D-AA49-C42813AF9613' => Http::response(pbBoletoOrder()['charges'][0]),
        ]);

        expect(pbGateway()->fetchPayment('ORDE_2FAC517E-0969-4D10-BF5F-601CBA2D1063')['id'])->toBe('ORDE_2FAC517E-0969-4D10-BF5F-601CBA2D1063')
            ->and(pbGateway()->fetchPayment('CHAR_60269EC9-9723-444D-AA49-C42813AF9613')['id'])->toBe('CHAR_60269EC9-9723-444D-AA49-C42813AF9613');

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->header('Authorization')[0] === 'Bearer tok_api_pagbank');
    });

    it('erro HTTP na consulta vira GatewayIntegrationException', function () {
        Http::fake(['https://api.pagseguro.com/orders/*' => Http::response([], 404)]);

        pbGateway()->fetchPayment('ORDE_INEXISTENTE');
    })->throws(GatewayIntegrationException::class);
});

describe('validateWebhookSignature (x-authenticity-token = sha256("{token}-{corpo}"))', function () {
    it('aceita a assinatura feita com o token da API', function () {
        expect(pbGateway()->validateWebhookSignature(pbWebhook(pbPixOrder('PAID'))))->toBeTrue();
    });

    it('aceita a assinatura feita com o webhook_secret configurado', function () {
        config(['billing.gateways.pagbank.webhook_secret' => 'tok_webhook_pagbank']);

        expect(pbGateway()->validateWebhookSignature(pbWebhook(pbPixOrder('PAID'), 'tok_webhook_pagbank')))->toBeTrue();
    });

    it('rejeita assinatura de outro token, corpo alterado, header ausente e o antigo Authorization: Bearer', function () {
        $gateway = pbGateway();
        $valid   = pbWebhook(pbPixOrder('PAID'));

        $tampered = new GatewayWebhookInputDTO(
            gatewayCode: 'pagbank',
            headers: $valid->headers,
            body: str_replace('20534', '1', $valid->body),
            payload: $valid->payload,
        );

        $bearer = new GatewayWebhookInputDTO(
            gatewayCode: 'pagbank',
            headers: ['authorization' => 'Bearer tok_api_pagbank'],
            body: $valid->body,
            payload: $valid->payload,
        );

        expect($gateway->validateWebhookSignature(pbWebhook(pbPixOrder('PAID'), 'token-errado')))->toBeFalse()
            ->and($gateway->validateWebhookSignature($tampered))->toBeFalse()
            ->and($gateway->validateWebhookSignature(pbWebhook(pbPixOrder('PAID'), null)))->toBeFalse()
            ->and($gateway->validateWebhookSignature($bearer))->toBeFalse();
    });

    it('sem token configurado, rejeita (fail-closed)', function () {
        config(['billing.gateways.pagbank.secret' => null, 'billing.gateways.pagbank.webhook_secret' => null]);

        expect(pbGateway()->validateWebhookSignature(pbWebhook(pbPixOrder('PAID'), '')))->toBeFalse();
    });
});

describe('parseWebhook (corpo = pedido, sem tipo de evento)', function () {
    it('Pix PAID: pago, id do pedido, fatura pelo reference_id, valor, vencimento e link', function () {
        $event = pbGateway()->parseWebhook(pbWebhook(pbPixOrder('PAID')));

        expect($event->eventType)->toBe('paid')
            ->and($event->status)->toBe('paid')
            ->and($event->externalPaymentId)->toBe('ORDE_3D560F48-E086-4F3C-A5A1-B7AB7BEACC2C')
            ->and($event->externalSubscriptionId)->toBeNull()
            ->and($event->metadata)->toBe(['invoice_id' => '9d6b0c1e-0000-4000-8000-0000000000aa'])
            ->and($event->amount)->toBe(205.34)
            ->and($event->currency)->toBe('BRL')
            ->and($event->dueDate)->toBe('2026-10-20')
            ->and($event->paymentUrl)->toBe('https://sandbox.api.pagseguro.com/qrcode/QRCO_0F63EB5F-61B8-4466-A4C9-7F824193C234/png')
            ->and($event->occurredAt)->toStartWith('2026-10-06T08:12:00');
    });

    it('boleto WAITING: cobrança emitida (created) com o PDF e o vencimento', function () {
        $event = pbGateway()->parseWebhook(pbWebhook(pbBoletoOrder('WAITING')));

        expect($event->eventType)->toBe('created')
            ->and($event->status)->toBe('pending')
            ->and($event->dueDate)->toBe('2026-10-20')
            ->and($event->paymentUrl)->toBe('https://boleto.pagseguro.com.br/6dd9494e-463c-4689-9436-aa3ada873214.pdf');
    });

    it('mapeia os status da cobrança', function (string $status, int $refunded, string $eventType, string $paymentStatus) {
        $event = pbGateway()->parseWebhook(pbWebhook(pbPixOrder($status, $refunded)));

        expect($event->eventType)->toBe($eventType)
            ->and($event->status)->toBe($paymentStatus);
    })->with([
        'AUTHORIZED'                 => ['AUTHORIZED', 0, 'authorized', 'authorized'],
        'IN_ANALYSIS'                => ['IN_ANALYSIS', 0, 'created', 'pending'],
        'DECLINED'                   => ['DECLINED', 0, 'failed', 'failed'],
        'CANCELED sem pagamento'     => ['CANCELED', 0, 'payment_cancelled', 'cancelled'],
        'CANCELED com estorno total' => ['CANCELED', 20534, 'refunded', 'refunded'],
        'PAID com estorno parcial'   => ['PAID', 10000, 'unknown', 'paid'],
    ]);

    it('notificação de cobrança avulsa (CHAR_): pedido vem de metadata.ps_order_id', function () {
        $charge = pbPixOrder('PAID')['charges'][0];

        $event = pbGateway()->parseWebhook(pbWebhook($charge));

        expect($event->externalPaymentId)->toBe('ORDE_3D560F48-E086-4F3C-A5A1-B7AB7BEACC2C')
            ->and($event->eventType)->toBe('paid')
            ->and($event->metadata)->toBe(['invoice_id' => '9d6b0c1e-0000-4000-8000-0000000000aa']);
    });

    it('corpo fora do formato (notificação pós-transacional em form-urlencoded): unknown, sem mudança', function () {
        $event = pbGateway()->parseWebhook(new GatewayWebhookInputDTO(
            gatewayCode: 'pagbank',
            headers: [],
            body: 'notificationCode=093C100E7FA87FA8C0B664B79F8359773B96&notificationType=transaction',
            payload: [],
        ));

        expect($event->eventType)->toBe('unknown')
            ->and($event->externalPaymentId)->toBeNull()
            ->and($event->status)->toBeNull();
    });
});

describe('webhookEventKey', function () {
    it('WAITING, PAID e estorno do mesmo pedido têm chaves diferentes; o reenvio, a mesma', function () {
        $gateway = pbGateway();

        $waiting  = $gateway->webhookEventKey(pbPixOrder('WAITING'));
        $paid     = $gateway->webhookEventKey(pbPixOrder('PAID'));
        $refunded = $gateway->webhookEventKey(pbPixOrder('CANCELED', 20534));

        expect($waiting)->not->toBe($paid)
            ->and($paid)->not->toBe($refunded)
            ->and($gateway->webhookEventKey(pbPixOrder('PAID')))->toBe($paid)
            ->and($paid)->toContain('ORDE_3D560F48-E086-4F3C-A5A1-B7AB7BEACC2C');
    });
});
