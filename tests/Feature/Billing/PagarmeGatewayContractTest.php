<?php

declare(strict_types=1);

use App\DTOs\Billing\{CancelSubscriptionDTO, CreateChargeDTO, CreateSubscriptionDTO, CustomerDTO, GatewayWebhookInputDTO};
use App\Enums\Billing\{InvoiceStatus, PaymentStatus};
use App\Enums\{BillingCycle, SubscriptionStatus};
use App\Exceptions\Billing\{GatewayIntegrationException, GatewayUnauthorizedException};
use App\Jobs\Billing\ProcessBillingWebhookJob;
use App\Models\Billing\{Payment, WebhookEvent};
use App\Models\{Entity, Plan, PlanPrice, Subscription};
use App\Services\Billing\{BillingSubscriptionOrchestrator, GatewayRegistry, WebhookIngestionService};
use App\Services\Billing\Gateways\PagarmeGateway;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\{Carbon, Sleep};
use Illuminate\Support\Facades\Http;

/**
 * Contrato do Pagar.me (API Core v5) com o uso que o EasyEye faz dele:
 * renovação local, cada ciclo um pedido (POST /orders) Pix ou boleto, e o
 * webhook ligado pelo id da cobrança (ch_…). Payloads no formato da doc:
 *  - https://docs.pagar.me/reference/criar-cliente-1
 *  - https://docs.pagar.me/reference/criar-pedido-2, /pix-2, /boleto-1
 *  - https://docs.pagar.me/reference/exemplo-de-webhook-1
 *  - https://docs.pagar.me/page/chargeback-novo-status-na-cobran%C3%A7a
 */
beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-05 10:00:00', 'UTC'));
    Http::preventStrayRequests();

    config([
        'billing.gateways.pagarme.base_url'       => 'https://api.pagar.me/core/v5',
        'billing.gateways.pagarme.secret'         => 'sk_test_easyeye',
        'billing.gateways.pagarme.webhook_secret' => 'easyeye:s3nh4-forte',
    ]);
});

afterEach(fn () => Carbon::setTestNow());

function pgm(): PagarmeGateway
{
    return app(GatewayRegistry::class)->get('pagarme');
}

function pgmCharge(?string $method = null, ?string $idempotencyKey = 'inv-1:1'): CreateChargeDTO
{
    return new CreateChargeDTO(
        entityId: 'ent-1',
        invoiceId: '9d1c7a52-6f0e-4a8e-9b0e-0d7f3b1c2a10',
        subscriptionId: 'sub-1',
        customerId: 'cus_oy23JRQCM1cvzlmD',
        amount: 299.90,
        currency: 'BRL',
        description: 'Fatura INV-20261015-ABC',
        dueDate: '2026-10-15',
        paymentMethod: $method,
        metadata: ['email' => 'clinica@example.com', 'customer_name' => 'Clínica', 'document' => '12345678000199', 'attempt_number' => 2],
        idempotencyKey: $idempotencyKey,
    );
}

/** Pedido (resposta do POST /orders) com uma cobrança. */
function pgmOrder(array $charge = [], array $order = []): array
{
    return array_replace([
        'id'       => 'or_ZdnB5BBCmYhk534R',
        'code'     => '9d1c7a52-6f0e-4a8e-9b0e-0d7f3b1c2a10',
        'amount'   => 29990,
        'currency' => 'BRL',
        'closed'   => true,
        'status'   => 'pending',
        'charges'  => [array_replace_recursive([
            'id'               => 'ch_d22356Jf4WuGr8no',
            'code'             => '9d1c7a52-6f0e-4a8e-9b0e-0d7f3b1c2a10',
            'amount'           => 29990,
            'status'           => 'pending',
            'currency'         => 'BRL',
            'payment_method'   => 'pix',
            'last_transaction' => [
                'id'               => 'tran_opAqDj2390S1lKQO',
                'transaction_type' => 'pix',
                'status'           => 'waiting_payment',
                'qr_code'          => '00020101021226820014br.gov.bcb.pix',
                'qr_code_url'      => 'https://api.pagar.me/core/v5/transactions/tran_opAqDj2390S1lKQO/qrcode?payment_method=pix',
                'expires_at'       => '2026-10-16T02:59:59Z',
            ],
        ], $charge)],
    ], $order);
}

function pgmWebhook(array $payload, array $headers = []): GatewayWebhookInputDTO
{
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return new GatewayWebhookInputDTO(
        gatewayCode: 'pagarme',
        headers: $headers,
        body: $body,
        payload: $payload,
        externalEventId: pgm()->webhookEventKey($payload),
    );
}

function pgmBasic(string $credentials = 'easyeye:s3nh4-forte'): array
{
    return ['authorization' => ['Basic ' . base64_encode($credentials)]];
}

/** Ingestão real (com a autenticação básica) + processamento. */
function pgmIngest(array $payload): WebhookEvent
{
    $body  = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $event = app(WebhookIngestionService::class)->ingest('pagarme', pgmBasic(), $body);

    ProcessBillingWebhookJob::dispatchSync((string) $event->id);

    return $event->fresh();
}

describe('autenticação e idempotência', function () {
    it('usa Basic Auth com a secret key como usuário e senha vazia, e o header Idempotency-Key', function () {
        Http::fake(['https://api.pagar.me/core/v5/orders' => Http::response(pgmOrder())]);

        pgm()->createCharge(pgmCharge());

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.pagar.me/core/v5/orders'
            && $r->method() === 'POST'
            && $r->header('Authorization')[0] === 'Basic ' . base64_encode('sk_test_easyeye:')
            && $r->header('Idempotency-Key')[0] === 'inv-1:1');
    });

    it('409 (requisição com a mesma chave em andamento): repete com a MESMA chave e recebe o pedido original', function () {
        Sleep::fake();

        Http::fake(['https://api.pagar.me/core/v5/orders' => Http::sequence()
            ->push(['message' => 'Conflict'], 409)
            ->push(pgmOrder()),
        ]);

        $result = pgm()->createCharge(pgmCharge());

        expect($result->success)->toBeTrue()
            ->and($result->externalPaymentId)->toBe('ch_d22356Jf4WuGr8no');

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r) => $r->header('Idempotency-Key')[0] === 'inv-1:1');
        Sleep::assertSleptTimes(1);
    });
});

describe('cliente (POST /customers)', function () {
    it('CNPJ vira pessoa jurídica (company) e o telefone vai separado em DDI/DDD/número', function () {
        Http::fake(['https://api.pagar.me/core/v5/customers' => Http::response(['id' => 'cus_oy23JRQCM1cvzlmD', 'name' => 'Clínica Olhos'])]);

        $id = pgm()->upsertCustomer(new CustomerDTO(
            entityId: 'ent-1',
            name: 'Clínica Olhos',
            email: 'clinica@example.com',
            document: '12.345.678/0001-99',
            phone: '+55 (11) 98765-4321',
            externalReference: 'ent-1',
        ));

        expect($id)->toBe('cus_oy23JRQCM1cvzlmD');

        Http::assertSent(fn (Request $r) => $r['type'] === 'company'
            && $r['document'] === '12345678000199'
            && $r['document_type'] === 'CNPJ'
            && $r['code'] === 'ent-1'
            && $r['email'] === 'clinica@example.com'
            && $r['phones'] === ['mobile_phone' => ['country_code' => '55', 'area_code' => '11', 'number' => '987654321']]);
    });

    it('CPF vira pessoa física (individual); fixo vai como home_phone', function () {
        Http::fake(['https://api.pagar.me/core/v5/customers' => Http::response(['id' => 'cus_1'])]);

        pgm()->upsertCustomer(new CustomerDTO(entityId: 'ent-1', name: 'Dr. Fulano', email: null, document: '123.456.789-09', phone: '(31) 3333-4444'));

        Http::assertSent(fn (Request $r) => $r['type'] === 'individual'
            && $r['document_type'] === 'CPF'
            && ! isset($r['email'])
            && $r['phones'] === ['home_phone' => ['country_code' => '55', 'area_code' => '31', 'number' => '33334444']]);
    });

    it('erro de validação (422) vira exceção com a mensagem da API', function () {
        Http::fake(['https://api.pagar.me/core/v5/customers' => Http::response([
            'message' => 'The request is invalid.',
            'errors'  => ['customer.document' => ['The field document is invalid.']],
            'request' => ['name' => 'Clínica Olhos', 'document' => '123'],
        ], 422)]);

        expect(fn () => pgm()->upsertCustomer(new CustomerDTO(entityId: 'ent-1', name: 'Clínica Olhos', email: null, document: '123', phone: null)))
            ->toThrow(GatewayIntegrationException::class, 'customer.document: The field document is invalid.');
    });
});

describe('cobrança (POST /orders)', function () {
    it('sem método informado emite Pix com expires_in até o fim do dia do vencimento (Brasília)', function () {
        Http::fake(['https://api.pagar.me/core/v5/orders' => Http::response(pgmOrder())]);

        $result = pgm()->createCharge(pgmCharge());

        expect($result->success)->toBeTrue()
            ->and($result->externalPaymentId)->toBe('ch_d22356Jf4WuGr8no')
            ->and($result->status)->toBe('pending')
            ->and($result->amount)->toBe(299.90)
            ->and($result->paymentUrl)->toBe('https://api.pagar.me/core/v5/transactions/tran_opAqDj2390S1lKQO/qrcode?payment_method=pix');

        // 2026-10-05 07:00 (BRT) → 2026-10-15 23:59:59 (BRT) = 10 dias + 16:59:59.
        Http::assertSent(fn (Request $r) => $r['code'] === '9d1c7a52-6f0e-4a8e-9b0e-0d7f3b1c2a10'
            && $r['customer_id'] === 'cus_oy23JRQCM1cvzlmD'
            && $r['closed'] === true
            && $r['items'] === [['amount' => 29990, 'description' => 'Fatura INV-20261015-ABC', 'quantity' => 1, 'code' => '9d1c7a52-6f0e-4a8e-9b0e-0d7f3b1c2a10']]
            && $r['payments'] === [['payment_method' => 'pix', 'pix' => ['expires_in' => 925199]]]
            && $r['metadata'] === [
                'attempt_number'  => '2',
                'entity_id'       => 'ent-1',
                'invoice_id'      => '9d1c7a52-6f0e-4a8e-9b0e-0d7f3b1c2a10',
                'subscription_id' => 'sub-1',
            ]);
    });

    it('boleto: due_at no dia do vencimento e link da página do boleto', function () {
        Http::fake(['https://api.pagar.me/core/v5/orders' => Http::response(pgmOrder([
            'payment_method'   => 'boleto',
            'last_transaction' => [
                'transaction_type' => 'boleto',
                'status'           => 'generated',
                'url'              => 'https://api.pagar.me/core/v5/transactions/tran_1/boleto',
                'pdf'              => 'https://api.pagar.me/core/v5/transactions/tran_1/pdf',
                'line'             => '34191090080012345678901234567890123456789012',
                'qr_code_url'      => null,
            ],
        ]))]);

        $result = pgm()->createCharge(pgmCharge('boleto'));

        expect($result->success)->toBeTrue()
            ->and($result->status)->toBe('pending')
            ->and($result->paymentUrl)->toBe('https://api.pagar.me/core/v5/transactions/tran_1/boleto');

        Http::assertSent(fn (Request $r) => $r['payments'][0]['payment_method'] === 'boleto'
            && $r['payments'][0]['boleto']['due_at'] === '2026-10-15T23:59:59'
            && $r['payments'][0]['boleto']['instructions'] === 'Pagamento referente a Fatura INV-20261015-ABC'
            && ! isset($r['payments'][0]['pix']));
    });

    it('pedido criado com status failed (HTTP 200) é falha, com o motivo do gateway', function () {
        Http::fake(['https://api.pagar.me/core/v5/orders' => Http::response(pgmOrder(
            ['status' => 'failed', 'last_transaction' => ['status' => 'failed', 'gateway_response' => ['code' => '400', 'errors' => [['message' => 'customer.phones: O telefone é obrigatório.']]]]],
            ['status' => 'failed'],
        ))]);

        $result = pgm()->createCharge(pgmCharge());

        expect($result->success)->toBeFalse()
            ->and($result->externalPaymentId)->toBeNull()
            ->and($result->status)->toBe('failed')
            ->and($result->errorMessage)->toContain('O telefone é obrigatório');
    });

    it('erro HTTP devolve falha com o status e a mensagem (sem o eco do request)', function () {
        Http::fake(['https://api.pagar.me/core/v5/orders' => Http::response([
            'message' => 'The request is invalid.',
            'errors'  => ['order.items[0].amount' => ['The amount must be greater than 0.']],
            'request' => ['customer_id' => 'cus_1'],
        ], 422)]);

        $result = pgm()->createCharge(pgmCharge());

        expect($result->success)->toBeFalse()
            ->and($result->errorCode)->toBe('422')
            ->and($result->errorMessage)->toContain('order.items[0].amount: The amount must be greater than 0.')
            ->and($result->rawResponse)->not->toHaveKey('request');
    });

    it('consulta a cobrança gravada (ch_) em /charges e um pedido antigo (or_) em /orders', function () {
        Http::fake([
            'https://api.pagar.me/core/v5/charges/ch_d22356Jf4WuGr8no' => Http::response(['id' => 'ch_d22356Jf4WuGr8no', 'status' => 'paid']),
            'https://api.pagar.me/core/v5/orders/or_ZdnB5BBCmYhk534R'  => Http::response(['id' => 'or_ZdnB5BBCmYhk534R', 'status' => 'paid']),
        ]);

        expect(pgm()->fetchPayment('ch_d22356Jf4WuGr8no')['id'])->toBe('ch_d22356Jf4WuGr8no')
            ->and(pgm()->fetchPayment('or_ZdnB5BBCmYhk534R')['id'])->toBe('or_ZdnB5BBCmYhk534R');
    });
});

describe('assinatura (renovação local)', function () {
    it('createSubscription não chama a API e não devolve id externo', function () {
        $result = pgm()->createSubscription(new CreateSubscriptionDTO(
            entityId: 'ent-1',
            subscriptionId: 'sub-1',
            planId: 'plan-1',
            customerId: 'cus_1',
            amount: 299.90,
            currency: 'BRL',
            interval: 'month',
            metadata: ['pagarme_plan_id' => 'plan_xyz'],
        ));

        expect($result->success)->toBeTrue()
            ->and($result->externalSubscriptionId)->toBeNull()
            ->and($result->externalCustomerId)->toBe('cus_1')
            ->and(pgm()->subscriptionIssuesFirstCharge())->toBeFalse();

        Http::assertNothingSent();
    });

    it('cancelamento de assinatura antiga usa DELETE /subscriptions/{id}; 404 conta como cancelada', function () {
        Http::fake([
            'https://api.pagar.me/core/v5/subscriptions/sub_Lm0DbReSoI0nw4eB' => Http::response(['id' => 'sub_Lm0DbReSoI0nw4eB', 'status' => 'canceled']),
            'https://api.pagar.me/core/v5/subscriptions/sub_gone'             => Http::response(['message' => 'Subscription not found.'], 404),
        ]);

        $ok   = pgm()->cancelSubscription(new CancelSubscriptionDTO(entityId: 'ent-1', subscriptionId: 'sub-1', externalSubscriptionId: 'sub_Lm0DbReSoI0nw4eB'));
        $gone = pgm()->cancelSubscription(new CancelSubscriptionDTO(entityId: 'ent-1', subscriptionId: 'sub-1', externalSubscriptionId: 'sub_gone'));

        expect($ok->success)->toBeTrue()->and($ok->status)->toBe('cancelled')
            ->and($gone->success)->toBeTrue();

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE'
            && $r->url() === 'https://api.pagar.me/core/v5/subscriptions/sub_Lm0DbReSoI0nw4eB'
            && $r['cancel_pending_invoices'] === true);
    });
});

describe('autenticidade do webhook (autenticação básica do cadastro do webhook)', function () {
    it('aceita o usuário e a senha configurados', function () {
        expect(pgm()->validateWebhookSignature(pgmWebhook(['id' => 'hook_1', 'type' => 'order.paid'], pgmBasic())))->toBeTrue();
    });

    it('rejeita senha errada, header ausente, outro esquema e a antiga x-pagarme-signature', function () {
        $body = ['id' => 'hook_1', 'type' => 'order.paid'];
        $hmac = hash_hmac('sha256', json_encode($body), 'easyeye:s3nh4-forte');

        expect(pgm()->validateWebhookSignature(pgmWebhook($body, pgmBasic('easyeye:errada'))))->toBeFalse()
            ->and(pgm()->validateWebhookSignature(pgmWebhook($body)))->toBeFalse()
            ->and(pgm()->validateWebhookSignature(pgmWebhook($body, ['authorization' => 'Bearer easyeye:s3nh4-forte'])))->toBeFalse()
            ->and(pgm()->validateWebhookSignature(pgmWebhook($body, ['x-pagarme-signature' => "sha256={$hmac}"])))->toBeFalse();
    });

    it('webhook_secret só com a senha compara só a senha', function () {
        config(['billing.gateways.pagarme.webhook_secret' => 's3nh4-forte']);

        expect(pgm()->validateWebhookSignature(pgmWebhook(['id' => 'hook_1'], pgmBasic('qualquer:s3nh4-forte'))))->toBeTrue()
            ->and(pgm()->validateWebhookSignature(pgmWebhook(['id' => 'hook_1'], pgmBasic('qualquer:outra'))))->toBeFalse();
    });

    it('sem webhook_secret configurado rejeita (fail-closed)', function () {
        config(['billing.gateways.pagarme.webhook_secret' => null]);

        expect(pgm()->validateWebhookSignature(pgmWebhook(['id' => 'hook_1'], pgmBasic())))->toBeFalse();
    });
});

describe('parse do webhook', function () {
    it('order.paid: id da cobrança paga (ch_), pedido como referência, valor em reais e fatura pelo code', function () {
        $n = pgm()->parseWebhook(pgmWebhook([
            'id'         => 'hook_RyEKQO789TRpZjv5',
            'account'    => ['id' => 'acc_jZkdN857et650oNv', 'name' => 'EasyEye'],
            'type'       => 'order.paid',
            'created_at' => '2026-10-06T11:12:00',
            'data'       => pgmOrder(['status' => 'paid', 'paid_amount' => 29990, 'last_transaction' => ['status' => 'paid']], ['status' => 'paid']),
        ]));

        expect($n->eventType)->toBe('paid')
            ->and($n->externalEventId)->toBe('hook_RyEKQO789TRpZjv5')
            ->and($n->externalPaymentId)->toBe('ch_d22356Jf4WuGr8no')
            ->and($n->externalInvoiceId)->toBe('or_ZdnB5BBCmYhk534R')
            ->and($n->externalSubscriptionId)->toBeNull()
            ->and($n->status)->toBe('paid')
            ->and($n->amount)->toBe(299.90)
            ->and($n->metadata['invoice_id'])->toBe('9d1c7a52-6f0e-4a8e-9b0e-0d7f3b1c2a10')
            ->and($n->occurredAt)->toBe('2026-10-06T11:12:00+00:00');
    });

    it('charge.paid do mesmo pagamento aponta para o MESMO id de cobrança do order.paid', function () {
        $charge = pgmOrder(['status' => 'paid', 'metadata' => ['invoice_id' => '9d1c7a52-6f0e-4a8e-9b0e-0d7f3b1c2a10']])['charges'][0];

        $n = pgm()->parseWebhook(pgmWebhook(['id' => 'hook_2', 'type' => 'charge.paid', 'data' => $charge]));

        expect($n->eventType)->toBe('paid')
            ->and($n->externalPaymentId)->toBe('ch_d22356Jf4WuGr8no')
            ->and($n->externalSubscriptionId)->toBeNull()
            ->and($n->metadata['invoice_id'])->toBe('9d1c7a52-6f0e-4a8e-9b0e-0d7f3b1c2a10');
    });

    it('mapeia os eventos de cobrança da v5', function (string $type, string $status, string $expected) {
        $charge = pgmOrder(['status' => $status])['charges'][0];

        expect(pgm()->parseWebhook(pgmWebhook(['id' => 'hook_x', 'type' => $type, 'data' => $charge]))->eventType)->toBe($expected);
    })->with([
        'charge.created'        => ['charge.created', 'pending', 'created'],
        'charge.overpaid'       => ['charge.overpaid', 'overpaid', 'paid'],
        'charge.payment_failed' => ['charge.payment_failed', 'failed', 'failed'],
        'charge.refunded'       => ['charge.refunded', 'refunded', 'refunded'],
        'charge.underpaid'      => ['charge.underpaid', 'underpaid', 'unknown'],
        'charge.updated'        => ['charge.updated', 'pending', 'unknown'],
    ]);

    it('order.payment_failed fica só em registro (a falha vem do charge.payment_failed); subscription.* idem', function () {
        expect(pgm()->parseWebhook(pgmWebhook(['id' => 'hook_3', 'type' => 'order.payment_failed', 'data' => pgmOrder(['status' => 'failed'], ['status' => 'failed'])]))->eventType)->toBe('unknown')
            ->and(pgm()->parseWebhook(pgmWebhook(['id' => 'hook_4', 'type' => 'subscription.canceled', 'data' => ['id' => 'sub_1', 'status' => 'canceled', 'metadata' => ['subscription_id' => 'sub-1']]]))->eventType)->toBe('unknown')
            ->and(pgm()->parseWebhook(pgmWebhook(['id' => 'hook_5', 'type' => 'order.canceled', 'data' => pgmOrder(['status' => 'canceled'], ['status' => 'canceled'])]))->eventType)->toBe('payment_cancelled');
    });

    it('charge.chargedback (payload da doc) vira chargeback da cobrança', function () {
        $n = pgm()->parseWebhook(pgmWebhook([
            'id'         => 'hook_XdPmQbO7T5UpZo1A',
            'account'    => ['id' => 'acc_jab0WOEtkfV30ogJ', 'name' => 'EasyEye'],
            'type'       => 'charge.chargedback',
            'created_at' => '2022-08-30T13:29:27Z',
            'data'       => [
                'id'               => 'ch_w3oN9YVsJbfr7NM1',
                'code'             => 'R7NPFXA3OW-01',
                'amount'           => 26978,
                'paid_amount'      => 26978,
                'status'           => 'chargedback',
                'currency'         => 'BRL',
                'payment_method'   => 'credit_card',
                'due_at'           => '2022-08-30T23:59:59',
                'last_transaction' => ['id' => 'tran_1b2yXn7S0QcK6vOJ', 'status' => 'chargedback', 'operation_type' => 'chargeback'],
                'metadata'         => ['id' => 'my_subscription_id'],
            ],
        ]));

        expect($n->eventType)->toBe('chargeback')
            ->and($n->externalPaymentId)->toBe('ch_w3oN9YVsJbfr7NM1')
            ->and($n->status)->toBe('chargeback')
            ->and($n->amount)->toBe(269.78)
            ->and($n->dueDate)->toBeNull();
    });

    it('boleto: vencimento da cobrança vai como dueDate; Pix não tem', function () {
        $boleto = pgmOrder(['payment_method' => 'boleto', 'due_at' => '2026-10-15T23:59:59', 'last_transaction' => ['transaction_type' => 'boleto', 'due_at' => '2026-10-15T23:59:59', 'url' => 'https://api.pagar.me/core/v5/transactions/tran_1/boleto']])['charges'][0];
        $pix    = pgmOrder()['charges'][0];

        $nb = pgm()->parseWebhook(pgmWebhook(['id' => 'hook_6', 'type' => 'charge.created', 'data' => $boleto]));
        $np = pgm()->parseWebhook(pgmWebhook(['id' => 'hook_7', 'type' => 'charge.created', 'data' => $pix]));

        expect($nb->dueDate)->toBe('2026-10-15')
            ->and($nb->paymentUrl)->toBe('https://api.pagar.me/core/v5/transactions/tran_1/boleto')
            ->and($np->dueDate)->toBeNull();
    });
});

describe('fluxo real: ativação + webhooks order.paid e charge.paid', function () {
    beforeEach(function () {
        $this->clinic                = Entity::factory()->make(['is_client' => true, 'active' => true]);
        $this->clinic->skipAutoTrial = true;
        $this->clinic->save();

        $this->plan = Plan::factory()->create(['name' => 'Pro', 'billing_cycle' => 'monthly', 'price' => 299.90, 'active' => true]);
        PlanPrice::create(['plan_id' => $this->plan->id, 'billing_cycle' => 'monthly', 'price' => 299.90]);

        Subscription::factory()->trial(10)->for($this->clinic)->for($this->plan)->create();

        Http::fake([
            'https://api.pagar.me/core/v5/customers' => Http::response(['id' => 'cus_oy23JRQCM1cvzlmD']),
            'https://api.pagar.me/core/v5/orders'    => Http::response(pgmOrder()),
        ]);
    });

    it('o pagamento conta uma vez só: charge.paid ativa e o order.paid seguinte é duplicado', function () {
        $subscription = app(BillingSubscriptionOrchestrator::class)
            ->activateWithGateway($this->clinic, $this->plan, BillingCycle::Monthly, 'pagarme');
        $invoice = $subscription->currentInvoice;

        expect(Payment::sole()->external_payment_id)->toBe('ch_d22356Jf4WuGr8no')
            ->and($invoice->external_invoice_id)->toBe('ch_d22356Jf4WuGr8no');

        $paidOrder = pgmOrder(['status' => 'paid', 'paid_amount' => 29990, 'last_transaction' => ['status' => 'paid']], ['status' => 'paid', 'code' => $invoice->id]);

        $first = pgmIngest(['id' => 'hook_charge_paid', 'type' => 'charge.paid', 'created_at' => '2026-10-05T10:05:00', 'data' => $paidOrder['charges'][0]]);
        $again = pgmIngest(['id' => 'hook_order_paid', 'type' => 'order.paid', 'created_at' => '2026-10-05T10:05:01', 'data' => $paidOrder]);

        $subscription->refresh();

        expect($first->normalized_payload['outcome'])->toBe('activated')
            ->and($again->normalized_payload['outcome'])->toBe('duplicate')
            ->and($subscription->status)->toBe(SubscriptionStatus::Active)
            ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
            ->and(Payment::query()->count())->toBe(1)
            ->and(Payment::sole()->status)->toBe(PaymentStatus::Paid);
    });

    it('webhook sem a autenticação básica é recusado na ingestão', function () {
        $body = json_encode(['id' => 'hook_forjado', 'type' => 'order.paid', 'data' => pgmOrder(['status' => 'paid'], ['status' => 'paid'])]);

        expect(fn () => app(WebhookIngestionService::class)->ingest('pagarme', [], $body))
            ->toThrow(GatewayUnauthorizedException::class);

        expect(WebhookEvent::query()->count())->toBe(0);
    });
});
