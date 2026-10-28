<?php

declare(strict_types=1);

use App\DTOs\Billing\{CancelSubscriptionDTO, CreateChargeDTO, CreateSubscriptionDTO, CustomerDTO, GatewayWebhookInputDTO};
use App\Exceptions\Billing\{GatewayIntegrationException, GatewayUnauthorizedException};
use App\Services\Billing\{GatewayRegistry, WebhookIngestionService};
use App\Services\Billing\Gateways\MercadoPagoGateway;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Contrato do Mercado Pago (API de Pagamentos) com o uso que o EasyEye faz
 * dele: renovação local, cada ciclo um Pix avulso (POST /v1/payments) e o
 * webhook (tópico "payment", só com o id) resolvido pela consulta ao
 * pagamento. Payloads no formato da documentação oficial:
 *  - https://www.mercadopago.com.br/developers/pt/reference/customers/_customers_search/get
 *  - https://www.mercadopago.com.br/developers/pt/reference/customers/_customers/post
 *  - https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-api-payments/create-payment/post
 *  - https://www.mercadopago.com.br/developers/en/docs/checkout-api-payments/integration-configuration/integrate-pix
 *  - https://www.mercadopago.com.br/developers/pt/docs/your-integrations/notifications/webhooks
 *  - https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-api-payments/create-refund/post
 *  - https://www.mercadopago.com.br/developers/pt/reference/online-payments/subscriptions/get-preapproval/get.
 */
beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-05 10:00:00'));
    Http::preventStrayRequests();

    config([
        'billing.gateways.mercadopago.base_url'       => 'https://api.mercadopago.com',
        'billing.gateways.mercadopago.secret'         => 'APP_USR-8877-122-easyeye',
        'billing.gateways.mercadopago.webhook_secret' => 'mp_webhook_secret_easyeye',
    ]);
});

afterEach(fn () => Carbon::setTestNow());

function mpg(): MercadoPagoGateway
{
    return app(GatewayRegistry::class)->get('mercadopago');
}

function mpgCustomer(array $overrides = []): CustomerDTO
{
    return new CustomerDTO(...array_replace([
        'entityId'          => 'ent-1',
        'name'              => 'Clínica  Olhar   Ltda',
        'email'             => 'financeiro@clinicaolhar.com.br',
        'document'          => '12.345.678/0001-99',
        'phone'             => '+55 (11) 98765-4321',
        'externalReference' => 'ent-1',
    ], $overrides));
}

function mpgCharge(array $overrides = []): CreateChargeDTO
{
    return new CreateChargeDTO(...array_replace([
        'entityId'       => 'ent-1',
        'invoiceId'      => '9d1c7a52-6f0e-4a8e-9b0e-0d7f3b1c2a10',
        'subscriptionId' => 'sub-1',
        'customerId'     => '1234567-cbXRL1mI2X',
        'amount'         => 299.9,
        'currency'       => 'BRL',
        'description'    => 'Fatura INV-20261015-ABC',
        'dueDate'        => '2026-10-15',
        'paymentMethod'  => null,
        'metadata'       => ['email' => 'financeiro@clinicaolhar.com.br', 'customer_name' => 'Clínica Olhar', 'document' => '12345678000199', 'attempt_number' => 2],
        'idempotencyKey' => 'renewal:sub-1:2026-10-15:1',
    ], $overrides));
}

/** Pagamento Pix como a API devolve (create e GET /v1/payments/{id}). */
function mpgPayment(array $overrides = []): array
{
    return array_replace_recursive([
        'id'                  => 5466310457,
        'date_created'        => '2026-10-05T10:00:01.000-03:00',
        'date_approved'       => null,
        'date_of_expiration'  => '2026-10-15T23:59:59.000-03:00',
        'operation_type'      => 'regular_payment',
        'payment_method_id'   => 'pix',
        'payment_type_id'     => 'bank_transfer',
        'status'              => 'pending',
        'status_detail'       => 'pending_waiting_transfer',
        'currency_id'         => 'BRL',
        'description'         => 'Fatura INV-20261015-ABC',
        'live_mode'           => true,
        'transaction_amount'  => 299.9,
        'external_reference'  => '9d1c7a52-6f0e-4a8e-9b0e-0d7f3b1c2a10',
        'metadata'            => ['invoice_id' => '9d1c7a52-6f0e-4a8e-9b0e-0d7f3b1c2a10', 'subscription_id' => 'sub-1', 'entity_id' => 'ent-1'],
        'transaction_details' => [
            'net_received_amount'   => 0,
            'total_paid_amount'     => 299.9,
            'overpaid_amount'       => 0,
            'external_resource_url' => null,
            'installment_amount'    => 0,
            'financial_institution' => null,
            'transaction_id'        => null,
        ],
        'point_of_interaction' => [
            'type'             => 'PIX',
            'sub_type'         => null,
            'transaction_data' => [
                'qr_code_base64' => 'iVBORw0KGgoAAAANSUhEUgAABRQAAAUUCAYAAACu5p7oAAAABGdBTUEAALGPC',
                'qr_code'        => '00020126600014br.gov.bcb.pix0117john@yourdomain.com0217additional data520400005303986540510.005802BR5913Maria Silva6008Brasilia62070503***6304E2CA',
                'ticket_url'     => 'https://www.mercadopago.com.br/payments/5466310457/ticket?caller_id=123456&hash=123e4567-e89b-12d3-a456-426655440000',
                'transaction_id' => null,
            ],
        ],
    ], $overrides);
}

/** Notificação Webhooks (formato da doc) e headers assinados como o Mercado Pago assina. */
function mpgNotification(string $dataId = '5466310457', string $type = 'payment', string $action = 'payment.updated'): array
{
    return [
        'id'           => 12345,
        'live_mode'    => true,
        'type'         => $type,
        'date_created' => '2026-10-05T10:04:58.396-03:00',
        'user_id'      => 44444,
        'api_version'  => 'v1',
        'action'       => $action,
        'data'         => ['id' => $dataId],
    ];
}

function mpgSign(?string $dataId, ?string $requestId, string $ts = '1759669498', string $secret = 'mp_webhook_secret_easyeye'): array
{
    $manifest = ($dataId !== null ? 'id:' . strtolower($dataId) . ';' : '')
        . ($requestId !== null ? "request-id:{$requestId};" : '')
        . "ts:{$ts};";

    return array_filter([
        'x-signature'  => "ts={$ts},v1=" . hash_hmac('sha256', $manifest, $secret),
        'x-request-id' => $requestId,
    ]);
}

function mpgWebhook(array $payload, array $headers): GatewayWebhookInputDTO
{
    return new GatewayWebhookInputDTO(
        gatewayCode: 'mercadopago',
        headers: $headers,
        body: json_encode($payload),
        payload: $payload,
        externalEventId: (string) ($payload['id'] ?? ''),
    );
}

// ── Clientes ─────────────────────────────────────────────────────────────────

describe('upsertCustomer', function () {
    it('acha o cliente pelo e-mail em GET /v1/customers/search e não cria outro', function () {
        Http::fake(['https://api.mercadopago.com/v1/customers/search*' => Http::response([
            'paging'  => ['limit' => 10, 'offset' => 0, 'total' => 1],
            'results' => [[
                'id'             => '1234567-cbXRL1mI2X',
                'email'          => 'financeiro@clinicaolhar.com.br',
                'first_name'     => 'Clínica Olhar Ltda',
                'identification' => ['type' => 'CNPJ', 'number' => '12345678000199'],
                'live_mode'      => true,
                'metadata'       => [],
            ]],
        ])]);

        expect(mpg()->upsertCustomer(mpgCustomer()))->toBe('1234567-cbXRL1mI2X');

        Http::assertSent(fn (Request $r) => $r->method() === 'GET'
            && str_starts_with($r->url(), 'https://api.mercadopago.com/v1/customers/search?')
            && $r['email'] === 'financeiro@clinicaolhar.com.br'
            && $r->header('Authorization') === ['Bearer APP_USR-8877-122-easyeye']);
        Http::assertSentCount(1);
    });

    it('sem resultado, cria em POST /v1/customers com e-mail, nome, CNPJ e telefone no formato da doc', function () {
        Http::fake([
            'https://api.mercadopago.com/v1/customers/search*' => Http::response(['paging' => ['limit' => 10, 'offset' => 0, 'total' => 0], 'results' => []]),
            'https://api.mercadopago.com/v1/customers'         => Http::response([
                'id'    => '000000001-sT93QZFAsfxU9P5',
                'email' => 'financeiro@clinicaolhar.com.br',
            ], 201),
        ]);

        expect(mpg()->upsertCustomer(mpgCustomer()))->toBe('000000001-sT93QZFAsfxU9P5');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->url() === 'https://api.mercadopago.com/v1/customers'
            && $r->data() === [
                'email'          => 'financeiro@clinicaolhar.com.br',
                'first_name'     => 'Clínica Olhar Ltda',
                'phone'          => ['area_code' => '11', 'number' => '987654321'],
                'identification' => ['type' => 'CNPJ', 'number' => '12345678000199'],
                'metadata'       => ['entity_id' => 'ent-1'],
            ]);
    });

    it('CPF com 11 dígitos vai como CPF; documento inválido e telefone curto não vão (nunca identification sem número)', function () {
        Http::fake([
            'https://api.mercadopago.com/v1/customers/search*' => Http::response(['results' => []]),
            'https://api.mercadopago.com/v1/customers'         => Http::response(['id' => '000000002-abc'], 201),
        ]);

        mpg()->upsertCustomer(mpgCustomer(['document' => '191.191.191-00', 'phone' => null]));
        mpg()->upsertCustomer(mpgCustomer(['document' => '123', 'phone' => '1234']));

        $posts = collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn (Request $r) => $r->method() === 'POST')->values();

        expect($posts[0]->data()['identification'])->toBe(['type' => 'CPF', 'number' => '19119119100'])
            ->and($posts[0]->data())->not->toHaveKey('phone')
            ->and($posts[1]->data())->not->toHaveKey('identification')
            ->and($posts[1]->data())->not->toHaveKey('phone');
    });

    it('"the customer already exist" (cause 101) numa corrida: busca de novo e devolve o existente', function () {
        Http::fake([
            'https://api.mercadopago.com/v1/customers/search*' => Http::sequence()
                ->push(['results' => []])
                ->push(['results' => [['id' => '1234567-cbXRL1mI2X', 'email' => 'financeiro@clinicaolhar.com.br']]]),
            'https://api.mercadopago.com/v1/customers' => Http::response([
                'message' => 'the customer already exist',
                'error'   => 'bad_request',
                'status'  => 400,
                'cause'   => [['code' => '101', 'description' => 'the customer already exist.']],
            ], 400),
        ]);

        expect(mpg()->upsertCustomer(mpgCustomer()))->toBe('1234567-cbXRL1mI2X');
    });

    it('sem e-mail (obrigatório no cliente e no pagador do Pix) falha antes de chamar a API', function () {
        Http::fake();

        expect(fn () => mpg()->upsertCustomer(mpgCustomer(['email' => null])))->toThrow(GatewayIntegrationException::class, 'e-mail');

        Http::assertNothingSent();
    });

    it('busca com erro 5xx ou credencial inválida não cria às cegas', function (int $status) {
        Http::fake(['https://api.mercadopago.com/v1/customers/search*' => Http::response(['message' => 'error'], $status)]);

        expect(fn () => mpg()->upsertCustomer(mpgCustomer()))->toThrow(GatewayIntegrationException::class);

        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    })->with([500, 503, 401, 429]);
});

// ── Assinatura (renovação local) ─────────────────────────────────────────────

it('createSubscription não cria preapproval: renovação local, sem id de recorrência', function () {
    Http::fake();

    $result = mpg()->createSubscription(new CreateSubscriptionDTO(
        entityId: 'ent-1',
        subscriptionId: 'sub-1',
        planId: 'plan-1',
        customerId: '1234567-cbXRL1mI2X',
        amount: 299.9,
        currency: 'BRL',
        interval: 'month',
    ));

    expect($result->success)->toBeTrue()
        ->and($result->externalSubscriptionId)->toBeNull()
        ->and($result->externalCustomerId)->toBe('1234567-cbXRL1mI2X')
        ->and(mpg()->subscriptionIssuesFirstCharge())->toBeFalse();

    Http::assertNothingSent();
});

// ── Cobrança Pix ─────────────────────────────────────────────────────────────

describe('createCharge', function () {
    it('Pix: payload da doc (payer com e-mail e CNPJ, X-Idempotency-Key, vencimento ISO com milissegundos) e link ticket_url', function () {
        Http::fake(['https://api.mercadopago.com/v1/payments' => Http::response(mpgPayment(), 201)]);

        $result = mpg()->createCharge(mpgCharge());

        expect($result->success)->toBeTrue()
            ->and($result->externalPaymentId)->toBe('5466310457')
            ->and($result->status)->toBe('pending')
            ->and($result->amount)->toBe(299.9)
            ->and($result->paymentUrl)->toBe('https://www.mercadopago.com.br/payments/5466310457/ticket?caller_id=123456&hash=123e4567-e89b-12d3-a456-426655440000');

        Http::assertSent(function (Request $r) {
            $body = $r->data();

            return $r->method() === 'POST'
                && $r->url() === 'https://api.mercadopago.com/v1/payments'
                && $r->header('X-Idempotency-Key') === ['renewal:sub-1:2026-10-15:1']
                && $r->header('Authorization') === ['Bearer APP_USR-8877-122-easyeye']
                && $body['transaction_amount'] === 299.9
                && $body['payment_method_id'] === 'pix'
                && $body['description'] === 'Fatura INV-20261015-ABC'
                && $body['payer'] === [
                    'email'          => 'financeiro@clinicaolhar.com.br',
                    'first_name'     => 'Clínica Olhar',
                    'identification' => ['type' => 'CNPJ', 'number' => '12345678000199'],
                ]
                && $body['external_reference'] === '9d1c7a52-6f0e-4a8e-9b0e-0d7f3b1c2a10'
                && preg_match('/^2026-10-15T23:59:59\.000[+-]\d{2}:\d{2}$/', $body['date_of_expiration']) === 1
                // Sem dado pessoal no metadata (já vai em payer) e sem notification_url
                // (a URL assinada é a de "Suas integrações").
                && $body['metadata'] === [
                    'attempt_number'  => 2,
                    'entity_id'       => 'ent-1',
                    'invoice_id'      => '9d1c7a52-6f0e-4a8e-9b0e-0d7f3b1c2a10',
                    'subscription_id' => 'sub-1',
                ]
                && ! array_key_exists('notification_url', $body)
                && ! array_key_exists('id', $body['payer']);
        });
    });

    it('sem chave de idempotência do chamador, manda uma (o header é obrigatório na API)', function () {
        Http::fake(['https://api.mercadopago.com/v1/payments' => Http::response(mpgPayment(), 201)]);

        mpg()->createCharge(mpgCharge(['idempotencyKey' => null]));

        Http::assertSent(fn (Request $r) => preg_match('/^[0-9a-f-]{36}$/', (string) ($r->header('X-Idempotency-Key')[0] ?? '')) === 1);
    });

    it('vencimento dentro da janela do Pix: no mínimo 30 min, no máximo 30 dias da emissão', function (?string $dueDate, string $now, string $expected) {
        Carbon::setTestNow(CarbonImmutable::parse($now));
        Http::fake(['https://api.mercadopago.com/v1/payments' => Http::response(mpgPayment(), 201)]);

        mpg()->createCharge(mpgCharge(['dueDate' => $dueDate]));

        Http::assertSent(fn (Request $r) => str_starts_with((string) $r['date_of_expiration'], $expected));
    })->with([
        'vence hoje, emitido às 23:50'       => ['2026-10-05', '2026-10-05 23:50:00', '2026-10-06T00:25:00.000'],
        'vencimento a 60 dias'               => ['2026-12-04', '2026-10-05 10:00:00', '2026-11-04T09:55:00.000'],
        'sem vencimento: 3 dias, fim do dia' => [null, '2026-10-05 10:00:00', '2026-10-08T23:59:59.000'],
    ]);

    it('boleto: bolbradesco, vencimento mínimo de 1 dia e link em transaction_details.external_resource_url', function () {
        Http::fake(['https://api.mercadopago.com/v1/payments' => Http::response(mpgPayment([
            'payment_method_id'    => 'bolbradesco',
            'payment_type_id'      => 'ticket',
            'status_detail'        => 'pending_waiting_payment',
            'point_of_interaction' => ['type' => 'UNSPECIFIED', 'transaction_data' => ['ticket_url' => null]],
            'transaction_details'  => ['external_resource_url' => 'https://www.mercadopago.com.br/payments/5466310457/ticket?caller_id=123456&payment_method_id=bolbradesco'],
        ]), 201)]);

        $result = mpg()->createCharge(mpgCharge(['paymentMethod' => 'boleto', 'dueDate' => '2026-10-05']));

        expect($result->paymentUrl)->toBe('https://www.mercadopago.com.br/payments/5466310457/ticket?caller_id=123456&payment_method_id=bolbradesco');

        Http::assertSent(fn (Request $r) => $r['payment_method_id'] === 'bolbradesco'
            && str_starts_with((string) $r['date_of_expiration'], '2026-10-06T23:59:59.000'));
    });

    it('status da resposta normalizado (approved → paid, rejected → failed, in_process → pending)', function (string $mpStatus, string $expected) {
        Http::fake(['https://api.mercadopago.com/v1/payments' => Http::response(mpgPayment(['status' => $mpStatus]), 201)]);

        expect(mpg()->createCharge(mpgCharge())->status)->toBe($expected);
    })->with([
        ['approved', 'paid'],
        ['pending', 'pending'],
        ['in_process', 'pending'],
        ['rejected', 'failed'],
        ['cancelled', 'cancelled'],
    ]);

    it('erro 400 da API vira falha com status HTTP e a causa da doc (ex.: 4050 e-mail inválido)', function () {
        Http::fake(['https://api.mercadopago.com/v1/payments' => Http::response([
            'message' => 'payer.email must be a valid email',
            'error'   => 'bad_request',
            'status'  => 400,
            'cause'   => [['code' => 4050, 'description' => 'payer.email must be a valid email']],
        ], 400)]);

        $result = mpg()->createCharge(mpgCharge(['metadata' => []]));

        expect($result->success)->toBeFalse()
            ->and($result->errorCode)->toBe('400')
            ->and($result->errorMessage)->toContain('HTTP 400')
            ->and($result->errorMessage)->toContain('4050');
    });

    it('timeout lança GatewayIntegrationException (a mesma chave de idempotência torna a nova tentativa segura)', function () {
        Http::fake(['https://api.mercadopago.com/v1/payments' => Http::failedConnection()]);

        expect(fn () => mpg()->createCharge(mpgCharge()))->toThrow(GatewayIntegrationException::class);
    });

    it('moeda diferente de BRL não sai', function () {
        Http::fake();

        $result = mpg()->createCharge(mpgCharge(['currency' => 'USD']));

        expect($result->success)->toBeFalse()->and($result->errorCode)->toBe('unsupported_currency');
        Http::assertNothingSent();
    });
});

// ── Assinatura do webhook ────────────────────────────────────────────────────

describe('validateWebhookSignature', function () {
    it('aceita o manifesto da doc: "id:<data.id>;request-id:<x-request-id>;ts:<ts>;"', function () {
        $payload = mpgNotification();

        expect(mpg()->validateWebhookSignature(mpgWebhook($payload, mpgSign('5466310457', 'bb56a2f1-6aae-46ac-982e-9dcd3581d08e'))))->toBeTrue();
    });

    it('recusa o manifesto sem o ";" final (não é o que o Mercado Pago assina)', function () {
        $payload   = mpgNotification();
        $requestId = 'bb56a2f1-6aae-46ac-982e-9dcd3581d08e';
        $hash      = hash_hmac('sha256', "id:5466310457;request-id:{$requestId};ts:1759669498", 'mp_webhook_secret_easyeye');

        expect(mpg()->validateWebhookSignature(mpgWebhook($payload, ['x-signature' => "ts=1759669498,v1={$hash}", 'x-request-id' => $requestId])))->toBeFalse();
    });

    it('sem x-request-id o par sai do manifesto', function () {
        expect(mpg()->validateWebhookSignature(mpgWebhook(mpgNotification(), mpgSign('5466310457', null))))->toBeTrue();
    });

    it('data.id alfanumérico entra em minúsculas no manifesto', function () {
        $payload = mpgNotification('ORD01JQ4S4KY8HWQ6NA5PXB65B3D3', 'order', 'order.updated');

        expect(mpg()->validateWebhookSignature(mpgWebhook($payload, mpgSign('ORD01JQ4S4KY8HWQ6NA5PXB65B3D3', 'req-1'))))->toBeTrue();
    });

    it('headers como o Symfony entrega (arrays) também valem', function () {
        $headers = array_map(fn ($v) => [$v], mpgSign('5466310457', 'req-1'));

        expect(mpg()->validateWebhookSignature(mpgWebhook(mpgNotification(), $headers)))->toBeTrue();
    });

    it('recusa assinatura com outro segredo, outro data.id, sem ts/v1, ts não numérico ou sem header', function (array $headers) {
        expect(mpg()->validateWebhookSignature(mpgWebhook(mpgNotification(), $headers)))->toBeFalse();
    })->with([
        'outro segredo'   => [mpgSign('5466310457', 'req-1', '1759669498', 'outro-segredo')],
        'outro data.id'   => [mpgSign('999', 'req-1')],
        'sem v1'          => [['x-signature' => 'ts=1759669498', 'x-request-id' => 'req-1']],
        'sem ts'          => [['x-signature' => 'v1=' . str_repeat('a', 64), 'x-request-id' => 'req-1']],
        'ts não numérico' => [['x-signature' => 'ts=abc,v1=' . str_repeat('a', 64), 'x-request-id' => 'req-1']],
        'sem header'      => [[]],
    ]);

    it('sem webhook_secret configurado falha fechado', function () {
        config(['billing.gateways.mercadopago.webhook_secret' => null]);

        expect(mpg()->validateWebhookSignature(mpgWebhook(mpgNotification(), mpgSign('5466310457', 'req-1'))))->toBeFalse();
    });

    it('ingestão real: assinatura válida entra, inválida é recusada', function () {
        $payload = mpgNotification();
        $body    = json_encode($payload);

        $event = app(WebhookIngestionService::class)->ingest('mercadopago', mpgSign('5466310457', 'req-1'), $body);

        expect($event->external_event_id)->toBe('12345');

        expect(fn () => app(WebhookIngestionService::class)->ingest('mercadopago', mpgSign('5466310457', 'req-1', '1759669498', 'forjado'), $body))
            ->toThrow(GatewayUnauthorizedException::class);
    });
});

// ── Webhook: tópico payment ──────────────────────────────────────────────────

describe('parseWebhook', function () {
    it('payment.updated consulta GET /v1/payments/{id} e mapeia cada status', function (string $mpStatus, string $eventType, string $status) {
        Http::fake(['https://api.mercadopago.com/v1/payments/5466310457' => Http::response(mpgPayment(['status' => $mpStatus]))]);

        $event = mpg()->parseWebhook(mpgWebhook(mpgNotification(), []));

        expect($event->eventType)->toBe($eventType)
            ->and($event->status)->toBe($status)
            ->and($event->externalPaymentId)->toBe('5466310457')
            ->and($event->externalEventId)->toBe('12345')
            ->and($event->amount)->toBe(299.9)
            ->and($event->metadata['invoice_id'])->toBe('9d1c7a52-6f0e-4a8e-9b0e-0d7f3b1c2a10')
            ->and($event->metadata['subscription_id'])->toBe('sub-1')
            ->and($event->metadata['mp_status'])->toBe($mpStatus)
            ->and($event->dueDate)->toBe('2026-10-15')
            ->and($event->paymentUrl)->toStartWith('https://www.mercadopago.com.br/payments/5466310457/ticket');

        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && $r->url() === 'https://api.mercadopago.com/v1/payments/5466310457');
    })->with([
        ['approved', 'paid', 'paid'],
        ['pending', 'created', 'pending'],
        ['in_process', 'created', 'pending'],
        ['authorized', 'created', 'authorized'],
        ['in_mediation', 'unknown', 'in_mediation'],
        ['rejected', 'failed', 'failed'],
        ['cancelled', 'payment_cancelled', 'cancelled'],
        ['refunded', 'refunded', 'refunded'],
        ['charged_back', 'chargeback', 'chargeback'],
    ]);

    it('fatura pelo metadata quando não há external_reference', function () {
        Http::fake(['https://api.mercadopago.com/v1/payments/5466310457' => Http::response(array_replace(mpgPayment(['status' => 'approved']), ['external_reference' => null]))]);

        expect(mpg()->parseWebhook(mpgWebhook(mpgNotification(), []))->metadata['invoice_id'])->toBe('9d1c7a52-6f0e-4a8e-9b0e-0d7f3b1c2a10');
    });

    it('falha na consulta lança exceção (o webhook fica para nova tentativa)', function (int $status) {
        Http::fake(['https://api.mercadopago.com/v1/payments/5466310457' => Http::response(['message' => 'error', 'status' => $status], $status)]);

        expect(fn () => mpg()->parseWebhook(mpgWebhook(mpgNotification(), [])))->toThrow(GatewayIntegrationException::class);
    })->with([404, 500]);

    it('data.id de pagamento não numérico nunca vira caminho da API', function () {
        Http::fake();

        $event = mpg()->parseWebhook(mpgWebhook(mpgNotification('../v1/customers?x=1'), []));

        expect($event->eventType)->toBe('unknown')->and($event->externalPaymentId)->toBeNull();
        Http::assertNothingSent();
    });

    it('outros tópicos (merchant_order, claims…) só ficam registrados', function () {
        Http::fake();

        expect(mpg()->parseWebhook(mpgWebhook(mpgNotification('8123', 'topic_merchant_order_wh', 'merchant_order.updated'), []))->eventType)->toBe('unknown');
        Http::assertNothingSent();
    });

    it('subscription_preapproval (assinatura antiga): consulta o preapproval e canceled encerra', function (string $mpStatus, string $eventType, string $status) {
        Http::fake(['https://api.mercadopago.com/preapproval/2c938084726fca480172750000000000' => Http::response([
            'id'                 => '2c938084726fca480172750000000000',
            'external_reference' => 'sub-1',
            'status'             => $mpStatus,
        ])]);

        $event = mpg()->parseWebhook(mpgWebhook(mpgNotification('2c938084726fca480172750000000000', 'subscription_preapproval', 'updated'), []));

        expect($event->eventType)->toBe($eventType)
            ->and($event->status)->toBe($status)
            ->and($event->externalSubscriptionId)->toBe('2c938084726fca480172750000000000')
            ->and($event->externalPaymentId)->toBeNull();
    })->with([
        ['canceled', 'cancelled', 'cancelled'],
        ['cancelled', 'cancelled', 'cancelled'],
        ['authorized', 'unknown', 'active'],
        ['paused', 'unknown', 'paused'],
        ['pending', 'unknown', 'pending'],
    ]);
});

// ── Cancelamento e estorno ───────────────────────────────────────────────────

describe('cancelamento e estorno', function () {
    it('cancelSubscription (preapproval antigo): PUT /preapproval/{id} com status canceled', function () {
        Http::fake(['https://api.mercadopago.com/preapproval/2c938084726fca480172750000000000' => Http::response(['id' => '2c938084726fca480172750000000000', 'status' => 'canceled'])]);

        $result = mpg()->cancelSubscription(new CancelSubscriptionDTO('ent-1', 'sub-1', '2c938084726fca480172750000000000'));

        expect($result->success)->toBeTrue()->and($result->status)->toBe('cancelled');
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->data() === ['status' => 'canceled']);
    });

    it('cancelSubscription sem preapproval não chama a API; erro da API vira success=false', function () {
        Http::fake(['https://api.mercadopago.com/preapproval/*' => Http::response(['message' => 'not found', 'status' => 404], 404)]);

        expect(mpg()->cancelSubscription(new CancelSubscriptionDTO('ent-1', 'sub-1', ''))->success)->toBeTrue();
        Http::assertNothingSent();

        $failed = mpg()->cancelSubscription(new CancelSubscriptionDTO('ent-1', 'sub-1', 'abc123'));

        expect($failed->success)->toBeFalse()->and($failed->errorMessage)->toContain('404');
    });

    it('cancelPayment: PUT /v1/payments/{id} com status cancelled', function () {
        Http::fake(['https://api.mercadopago.com/v1/payments/5466310457' => Http::response(mpgPayment(['status' => 'cancelled', 'status_detail' => 'by_collector']))]);

        expect(mpg()->cancelPayment('5466310457'))->toBe('cancelled');
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->data() === ['status' => 'cancelled']);
    });

    it('refundPayment: POST /v1/payments/{id}/refunds com X-Idempotency-Key; sem valor = estorno total', function () {
        Http::fake(['https://api.mercadopago.com/v1/payments/5466310457/refunds' => Http::response([
            'id'         => 1009042015,
            'payment_id' => 5466310457,
            'amount'     => 100.0,
            'status'     => 'approved',
        ], 201)]);

        expect(mpg()->refundPayment('5466310457', 'refund:inv-1:1', 100.0)['id'])->toBe(1009042015);
        mpg()->refundPayment('5466310457', 'refund:inv-1:2');

        $sent = collect(Http::recorded())->map(fn ($pair) => $pair[0])->values();

        expect($sent[0]->method())->toBe('POST')
            ->and($sent[0]->header('X-Idempotency-Key'))->toBe(['refund:inv-1:1'])
            ->and($sent[0]->data())->toBe(['amount' => 100.0])
            ->and($sent[1]->data())->toBe([]);
    });

    it('estorno recusado (saldo insuficiente, prazo) lança exceção', function () {
        Http::fake(['https://api.mercadopago.com/v1/payments/5466310457/refunds' => Http::response(['message' => 'Invalid refund', 'status' => 400], 400)]);

        expect(fn () => mpg()->refundPayment('5466310457', 'refund:inv-1:1'))->toThrow(GatewayIntegrationException::class);
    });
});
