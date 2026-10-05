<?php

use App\DTOs\Billing\{CancelSubscriptionDTO, CreateChargeDTO, CreateSubscriptionDTO, CustomerDTO, GatewayWebhookInputDTO};
use App\Exceptions\Billing\GatewayIntegrationException;
use App\Services\Billing\GatewayCredentialResolver;
use App\Services\Billing\Gateways\StripeBrGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * Contrato do StripeBrGateway com a API da Stripe. Payloads no formato da
 * documentação oficial:
 * - Invoice: https://docs.stripe.com/api/invoices/object
 * - Charge: https://docs.stripe.com/api/charges/object
 * - Dispute: https://docs.stripe.com/api/disputes/object
 * - InvoicePayment: https://docs.stripe.com/api/invoice-payment/list
 * - Event / Stripe-Signature: https://docs.stripe.com/webhooks?verify=verify-manually
 */

const STRIPE_TEST_WEBHOOK_SECRET = 'whsec_test_secret';

function stripeGateway(): StripeBrGateway
{
    // Credenciais só pela config: sem consulta a gateway_credentials.
    $resolver = new class() extends GatewayCredentialResolver {
        public function resolveSecret(string $gatewayCode, ?string $entityId = null): ?string
        {
            return null;
        }

        public function resolveWebhookSecret(string $gatewayCode, ?string $entityId = null): ?string
        {
            return null;
        }

        public function resolveExtra(string $gatewayCode, string $key, ?string $entityId = null): ?string
        {
            return null;
        }
    };

    return new StripeBrGateway($resolver);
}

function stripeEvent(string $type, array $object, array $extra = []): array
{
    return array_merge([
        'id'               => 'evt_1NG8Du2eZvKYlo2CUI79vXWy',
        'object'           => 'event',
        'api_version'      => '2025-03-31.basil',
        'created'          => 1759600000,
        'data'             => ['object' => $object],
        'livemode'         => false,
        'pending_webhooks' => 1,
        'request'          => ['id' => null, 'idempotency_key' => null],
        'type'             => $type,
    ], $extra);
}

function stripeWebhookInput(array $event, array $headers = []): GatewayWebhookInputDTO
{
    return new GatewayWebhookInputDTO(
        gatewayCode: 'stripe_br',
        headers: $headers,
        body: (string) json_encode($event),
        payload: $event,
    );
}

/** Parâmetros da query string de um GET. */
function stripeQuery(Request $request): array
{
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    return $query;
}

function stripeInvoiceObject(array $overrides = []): array
{
    return array_replace_recursive([
        'id'                 => 'in_1MtHbELkdIwHu7ixl4OzzPMv',
        'object'             => 'invoice',
        'amount_due'         => 19990,
        'amount_paid'        => 0,
        'amount_remaining'   => 19990,
        'auto_advance'       => false,
        'collection_method'  => 'send_invoice',
        'currency'           => 'brl',
        'customer'           => 'cus_NeZwdNtLEOXuvB',
        'due_date'           => 1760324399, // 2025-10-12 23:59:59 America/Sao_Paulo
        'hosted_invoice_url' => 'https://invoice.stripe.com/i/acct_1M2JTkLkdIwHu7ix/test_YWNjdF8xTTJKVGtM?s=ap',
        'invoice_pdf'        => 'https://pay.stripe.com/invoice/acct_1M2JTkLkdIwHu7ix/test_YWNjdF8xTTJKVGtM/pdf?s=ap',
        'metadata'           => [
            'invoice_id'      => '9d5b1c2e-0000-4000-8000-000000000001',
            'subscription_id' => '9d5b1c2e-0000-4000-8000-000000000002',
            'entity_id'       => '9d5b1c2e-0000-4000-8000-000000000003',
            'easyeye_charge'  => 'invoice',
        ],
        'parent' => null,
        'status' => 'open',
    ], $overrides);
}

function stripeChargeDto(array $overrides = []): CreateChargeDTO
{
    return new CreateChargeDTO(...array_merge([
        'entityId'       => '9d5b1c2e-0000-4000-8000-000000000003',
        'invoiceId'      => '9d5b1c2e-0000-4000-8000-000000000001',
        'subscriptionId' => '9d5b1c2e-0000-4000-8000-000000000002',
        'customerId'     => 'cus_NeZwdNtLEOXuvB',
        'amount'         => 199.90,
        'currency'       => 'BRL',
        'description'    => 'Fatura INV-20261012-ABCDEFGH',
        'dueDate'        => now('America/Sao_Paulo')->addDays(5)->toDateString(),
        'metadata'       => ['email' => 'clinica@example.com', 'customer_name' => 'Clínica', 'document' => '12345678000195'],
        'idempotencyKey' => 'inv-key:1',
    ], $overrides));
}

beforeEach(function () {
    Http::preventStrayRequests();

    config([
        'billing.gateways.stripe_br.base_url'       => 'https://api.stripe.com',
        'billing.gateways.stripe_br.secret'         => 'sk_test_123',
        'billing.gateways.stripe_br.webhook_secret' => STRIPE_TEST_WEBHOOK_SECRET,
    ]);
});

// ── Clientes ────────────────────────────────────────────────────────────────

describe('upsertCustomer', function () {
    test('reaproveita o cliente da empresa pela busca em metadata (GET /v1/customers/search)', function () {
        Http::fake([
            'api.stripe.com/v1/customers/search*' => Http::response([
                'object'   => 'search_result',
                'url'      => '/v1/customers/search',
                'has_more' => false,
                'data'     => [['id' => 'cus_NeGfPRiPKxeBi1', 'object' => 'customer', 'metadata' => ['entity_id' => 'ent-1']]],
            ]),
        ]);

        $id = stripeGateway()->upsertCustomer(new CustomerDTO('ent-1', 'Clínica Olhos', 'a@b.com', null, null));

        expect($id)->toBe('cus_NeGfPRiPKxeBi1');

        Http::assertSent(fn (Request $r) => $r->method() === 'GET'
            && str_starts_with($r->url(), 'https://api.stripe.com/v1/customers/search?')
            && stripeQuery($r)['query'] === "metadata['entity_id']:'ent-1'"
            && $r->hasHeader('Authorization', 'Bearer sk_test_123')
            && $r->hasHeader('Stripe-Version', StripeBrGateway::API_VERSION));
        Http::assertSentCount(1);
    });

    test('cria o cliente com corpo form-encoded, Idempotency-Key e CNPJ como ID fiscal (fora do metadata)', function () {
        Http::fake([
            'api.stripe.com/v1/customers/search*' => Http::response(['object' => 'search_result', 'data' => [], 'has_more' => false]),
            'api.stripe.com/v1/customers'         => Http::response(['id' => 'cus_NffrFeUfNV2Hib', 'object' => 'customer']),
        ]);

        $id = stripeGateway()->upsertCustomer(new CustomerDTO('ent-1', 'Clínica Olhos', 'a@b.com', '12.345.678/0001-95', '(11) 99999-0000'));

        expect($id)->toBe('cus_NffrFeUfNV2Hib');

        Http::assertSent(function (Request $r) {
            if ($r->method() !== 'POST' || $r->url() !== 'https://api.stripe.com/v1/customers') {
                return false;
            }

            return $r->isForm()
                && $r->hasHeader('Content-Type', 'application/x-www-form-urlencoded')
                && str_starts_with($r->header('Idempotency-Key')[0] ?? '', 'easyeye:customer:')
                && $r['name'] === 'Clínica Olhos'
                && $r['email'] === 'a@b.com'
                && $r['phone'] === '11999990000'
                && $r['preferred_locales'] === ['pt-BR']
                && $r['tax_id_data'] === [['type' => 'br_cnpj', 'value' => '12.345.678/0001-95']]
                && $r['metadata'] === ['entity_id' => 'ent-1'];
        });
    });

    test('recria sem tax_id_data quando a Stripe recusa o formato do CPF/CNPJ', function () {
        Http::fake(function (Request $r) {
            if ($r->method() === 'GET') {
                return Http::response(['object' => 'search_result', 'data' => [], 'has_more' => false]);
            }

            return isset($r['tax_id_data'])
                ? Http::response(['error' => [
                    'type'    => 'invalid_request_error',
                    'code'    => 'tax_id_invalid',
                    'message' => 'Invalid value for br_cpf.',
                    'param'   => 'tax_id_data[0][value]',
                ]], 400)
                : Http::response(['id' => 'cus_SemDocumento', 'object' => 'customer']);
        });

        $id = stripeGateway()->upsertCustomer(new CustomerDTO('ent-1', 'Dra. Ana', 'a@b.com', '123.456.789-00', null));

        expect($id)->toBe('cus_SemDocumento');
        Http::assertSentCount(3);
    });

    test('erro HTTP da Stripe vira GatewayIntegrationException com a mensagem do erro', function () {
        Http::fake([
            'api.stripe.com/v1/customers/search*' => Http::response(['object' => 'search_result', 'data' => []]),
            'api.stripe.com/v1/customers'         => Http::response(['error' => ['type' => 'api_error', 'message' => 'Something went wrong']], 500),
        ]);

        expect(fn () => stripeGateway()->upsertCustomer(new CustomerDTO('ent-1', 'Clínica', null, null, null)))
            ->toThrow(GatewayIntegrationException::class, '[api_error] Something went wrong');
    });
});

// ── Assinaturas ─────────────────────────────────────────────────────────────

describe('assinatura (renovação local)', function () {
    test('createSubscription não chama a Stripe e devolve externalSubscriptionId nulo', function () {
        Http::fake();

        $result = stripeGateway()->createSubscription(new CreateSubscriptionDTO(
            entityId: 'ent-1',
            subscriptionId: 'sub-1',
            planId: 'plan-1',
            customerId: 'cus_NffrFeUfNV2Hib',
            amount: 199.90,
            currency: 'BRL',
            interval: 'month',
            metadata: ['stripe_price_id' => 'price_123'],
        ));

        expect($result->success)->toBeTrue()
            ->and($result->externalSubscriptionId)->toBeNull()
            ->and($result->externalCustomerId)->toBe('cus_NffrFeUfNV2Hib')
            ->and(stripeGateway()->subscriptionIssuesFirstCharge())->toBeFalse();

        Http::assertNothingSent();
    });

    test('cancelSubscription sem id externo não chama a Stripe; com id, DELETE /v1/subscriptions/{id}', function () {
        Http::fake(['api.stripe.com/v1/subscriptions/*' => Http::response(['id' => 'sub_1MowQVLkdIwHu7ixeRlqHVzs', 'object' => 'subscription', 'status' => 'canceled'])]);

        expect(stripeGateway()->cancelSubscription(new CancelSubscriptionDTO('ent-1', 'sub-1', ''))->success)->toBeTrue();
        Http::assertNothingSent();

        $result = stripeGateway()->cancelSubscription(new CancelSubscriptionDTO('ent-1', 'sub-1', 'sub_1MowQVLkdIwHu7ixeRlqHVzs'));

        expect($result->success)->toBeTrue()->and($result->status)->toBe('cancelled');
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE'
            && $r->url() === 'https://api.stripe.com/v1/subscriptions/sub_1MowQVLkdIwHu7ixeRlqHVzs');
    });
});

// ── Cobranças ───────────────────────────────────────────────────────────────

describe('createCharge (fatura hospedada)', function () {
    test('cria rascunho send_invoice, item e finaliza; devolve in_ e o hosted_invoice_url', function () {
        Http::fake([
            'api.stripe.com/v1/invoices/*/finalize' => Http::response(stripeInvoiceObject()),
            'api.stripe.com/v1/invoiceitems'        => Http::response(['id' => 'ii_1MtGUtLkdIwHu7ixBYwjAM00', 'object' => 'invoiceitem', 'amount' => 19990, 'invoice' => 'in_1MtHbELkdIwHu7ixl4OzzPMv']),
            'api.stripe.com/v1/invoices'            => Http::response(stripeInvoiceObject(['status' => 'draft', 'hosted_invoice_url' => null, 'amount_due' => 0])),
        ]);

        $dto    = stripeChargeDto();
        $result = stripeGateway()->createCharge($dto);

        expect($result->success)->toBeTrue()
            ->and($result->externalPaymentId)->toBe('in_1MtHbELkdIwHu7ixl4OzzPMv')
            ->and($result->status)->toBe('pending')
            ->and($result->amount)->toBe(199.90)
            ->and($result->paymentUrl)->toStartWith('https://invoice.stripe.com/i/');

        $expectedDue = now('America/Sao_Paulo')->addDays(5)->endOfDay()->getTimestamp();

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->url() === 'https://api.stripe.com/v1/invoices'
            && $r->isForm()
            && $r->hasHeader('Idempotency-Key', 'easyeye:inv-key:1:invoice')
            && $r['customer'] === 'cus_NeZwdNtLEOXuvB'
            && $r['collection_method'] === 'send_invoice'
            && $r['auto_advance'] === 'false'
            && $r['pending_invoice_items_behavior'] === 'exclude'
            && $r['currency'] === 'brl'
            && (int) $r['due_date'] === $expectedDue
            && $r['metadata'] === [
                'invoice_id'      => $dto->invoiceId,
                'subscription_id' => $dto->subscriptionId,
                'entity_id'       => $dto->entityId,
                'easyeye_charge'  => 'invoice',
            ]);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.stripe.com/v1/invoiceitems'
            && $r->hasHeader('Idempotency-Key', 'easyeye:inv-key:1:item')
            && $r['invoice'] === 'in_1MtHbELkdIwHu7ixl4OzzPMv'
            && (string) $r['amount'] === '19990'
            && $r['currency'] === 'brl'
            && array_keys($r['metadata']) === ['invoice_id', 'subscription_id', 'entity_id', 'easyeye_charge']);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.stripe.com/v1/invoices/in_1MtHbELkdIwHu7ixl4OzzPMv/finalize'
            && $r->hasHeader('Idempotency-Key', 'easyeye:inv-key:1:finalize')
            && $r['auto_advance'] === 'false');
    });

    test('vencimento já passado usa days_until_due', function () {
        Http::fake([
            'api.stripe.com/v1/invoices/*/finalize' => Http::response(stripeInvoiceObject()),
            'api.stripe.com/v1/invoiceitems'        => Http::response(['id' => 'ii_1', 'object' => 'invoiceitem']),
            'api.stripe.com/v1/invoices'            => Http::response(stripeInvoiceObject(['status' => 'draft'])),
        ]);

        stripeGateway()->createCharge(stripeChargeDto(['dueDate' => '2020-01-01']));

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.stripe.com/v1/invoices'
            && ! isset($r['due_date'])
            && (string) $r['days_until_due'] === '1');
    });

    test('item recusado (4xx): apaga o rascunho e devolve a falha com status e mensagem da Stripe', function () {
        Http::fake(function (Request $r) {
            return match (true) {
                $r->method() === 'DELETE'                              => Http::response(['id' => 'in_1MtHbELkdIwHu7ixl4OzzPMv', 'object' => 'invoice', 'deleted' => true]),
                $r->url() === 'https://api.stripe.com/v1/invoiceitems' => Http::response(['error' => [
                    'type'    => 'invalid_request_error',
                    'code'    => 'amount_too_small',
                    'message' => 'Amount must be at least R$0.50 brl',
                    'param'   => 'amount',
                ]], 400),
                default => Http::response(stripeInvoiceObject(['status' => 'draft'])),
            };
        });

        $result = stripeGateway()->createCharge(stripeChargeDto(['amount' => 0.10]));

        expect($result->success)->toBeFalse()
            ->and($result->errorCode)->toBe('400')
            ->and($result->errorMessage)->toContain('amount_too_small')
            ->and($result->paymentUrl)->toBeNull();

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE'
            && $r->url() === 'https://api.stripe.com/v1/invoices/in_1MtHbELkdIwHu7ixl4OzzPMv');
    });

    test('5xx na criação: falha com errorCode 500 e sem apagar nada', function () {
        Http::fake(['api.stripe.com/*' => Http::response(['error' => ['type' => 'api_error', 'message' => 'Internal']], 500)]);

        $result = stripeGateway()->createCharge(stripeChargeDto());

        expect($result->success)->toBeFalse()->and($result->errorCode)->toBe('500');
        Http::assertSentCount(1);
    });

    test('fatura já paga na finalização (saldo do cliente) volta como paid', function () {
        Http::fake([
            'api.stripe.com/v1/invoices/*/finalize' => Http::response(stripeInvoiceObject(['status' => 'paid', 'amount_due' => 0, 'amount_paid' => 0])),
            'api.stripe.com/v1/invoiceitems'        => Http::response(['id' => 'ii_1', 'object' => 'invoiceitem']),
            'api.stripe.com/v1/invoices'            => Http::response(stripeInvoiceObject(['status' => 'draft'])),
        ]);

        expect(stripeGateway()->createCharge(stripeChargeDto())->status)->toBe('paid');
    });
});

// ── Webhook: assinatura ─────────────────────────────────────────────────────

describe('validateWebhookSignature', function () {
    $sign = fn (string $body, int $t, string $secret = STRIPE_TEST_WEBHOOK_SECRET) => hash_hmac('sha256', "{$t}.{$body}", $secret);

    test('aceita v1 válida (header como array, formato do HeaderBag)', function () use ($sign) {
        $event = stripeEvent('invoice.paid', stripeInvoiceObject());
        $body  = (string) json_encode($event);
        $t     = time();

        $input = new GatewayWebhookInputDTO('stripe_br', ['stripe-signature' => ["t={$t},v1={$sign($body, $t)},v0=6ffbb59b2300aae63f272406069a9788598b792a944a07aba816edb039989a39"]], $body, $event);

        expect(stripeGateway()->validateWebhookSignature($input))->toBeTrue();
    });

    test('aceita quando uma das v1 confere (rotação do segredo)', function () use ($sign) {
        $body = '{"id":"evt_1"}';
        $t    = time();

        $input = new GatewayWebhookInputDTO('stripe_br', ['Stripe-Signature' => "t={$t},v1={$sign($body, $t, 'whsec_old')},v1={$sign($body, $t)}"], $body, []);

        expect(stripeGateway()->validateWebhookSignature($input))->toBeTrue();
    });

    test('rejeita corpo alterado, só v0, timestamp fora da tolerância e falta de segredo', function () use ($sign) {
        $body = '{"id":"evt_1"}';
        $t    = time();
        $gw   = stripeGateway();

        $tampered = new GatewayWebhookInputDTO('stripe_br', ['stripe-signature' => "t={$t},v1={$sign($body, $t)}"], '{"id":"evt_2"}', []);
        $onlyV0   = new GatewayWebhookInputDTO('stripe_br', ['stripe-signature' => "t={$t},v0={$sign($body, $t)}"], $body, []);
        $old      = $t - StripeBrGateway::WEBHOOK_TOLERANCE_SECONDS - 1;
        $replayed = new GatewayWebhookInputDTO('stripe_br', ['stripe-signature' => "t={$old},v1={$sign($body, $old)}"], $body, []);
        $missing  = new GatewayWebhookInputDTO('stripe_br', [], $body, []);

        expect($gw->validateWebhookSignature($tampered))->toBeFalse()
            ->and($gw->validateWebhookSignature($onlyV0))->toBeFalse()
            ->and($gw->validateWebhookSignature($replayed))->toBeFalse()
            ->and($gw->validateWebhookSignature($missing))->toBeFalse();

        config(['billing.gateways.stripe_br.webhook_secret' => null]);
        $valid = new GatewayWebhookInputDTO('stripe_br', ['stripe-signature' => "t={$t},v1={$sign($body, $t)}"], $body, []);

        expect(stripeGateway()->validateWebhookSignature($valid))->toBeFalse();
    });
});

// ── Webhook: eventos ────────────────────────────────────────────────────────

describe('parseWebhook', function () {
    test('invoice.finalized → created com link, vencimento e referência', function () {
        $n = stripeGateway()->parseWebhook(stripeWebhookInput(stripeEvent('invoice.finalized', stripeInvoiceObject())));

        expect($n->eventType)->toBe('created')
            ->and($n->externalEventId)->toBe('evt_1NG8Du2eZvKYlo2CUI79vXWy')
            ->and($n->externalPaymentId)->toBe('in_1MtHbELkdIwHu7ixl4OzzPMv')
            ->and($n->status)->toBe('pending')
            ->and($n->amount)->toBe(199.90)
            ->and($n->currency)->toBe('BRL')
            ->and($n->dueDate)->toBe('2025-10-12')
            ->and($n->paymentUrl)->toStartWith('https://invoice.stripe.com/i/')
            ->and($n->metadata['invoice_id'])->toBe('9d5b1c2e-0000-4000-8000-000000000001');
    });

    test('invoice.paid → paid com amount_paid; invoice.payment_succeeded do mesmo pagamento → unknown', function () {
        $paid = stripeInvoiceObject(['status' => 'paid', 'amount_paid' => 19990, 'amount_remaining' => 0]);

        $n = stripeGateway()->parseWebhook(stripeWebhookInput(stripeEvent('invoice.paid', $paid)));
        expect($n->eventType)->toBe('paid')->and($n->status)->toBe('paid')->and($n->amount)->toBe(199.90);

        $dup = stripeGateway()->parseWebhook(stripeWebhookInput(stripeEvent('invoice.payment_succeeded', $paid)));
        expect($dup->eventType)->toBe('unknown');
    });

    test('invoice.payment_failed → failed; invoice.overdue → overdue; invoice.voided → payment_cancelled', function () {
        $gw = stripeGateway();

        expect($gw->parseWebhook(stripeWebhookInput(stripeEvent('invoice.payment_failed', stripeInvoiceObject())))->eventType)->toBe('failed')
            ->and($gw->parseWebhook(stripeWebhookInput(stripeEvent('invoice.overdue', stripeInvoiceObject())))->eventType)->toBe('overdue')
            ->and($gw->parseWebhook(stripeWebhookInput(stripeEvent('invoice.voided', stripeInvoiceObject(['status' => 'void']))))->eventType)->toBe('payment_cancelled')
            ->and($gw->parseWebhook(stripeWebhookInput(stripeEvent('invoice.marked_uncollectible', stripeInvoiceObject(['status' => 'uncollectible']))))->eventType)->toBe('unknown');
    });

    test('payment_intent.succeeded da fatura não conta de novo; charge.succeeded também não', function () {
        $pi = [
            'id'              => 'pi_3MtwBwLkdIwHu7ix28a3tqPa',
            'object'          => 'payment_intent',
            'amount'          => 19990,
            'amount_received' => 19990,
            'currency'        => 'brl',
            'customer'        => 'cus_NeZwdNtLEOXuvB',
            'metadata'        => [],
            'status'          => 'succeeded',
        ];

        $gw = stripeGateway();

        expect($gw->parseWebhook(stripeWebhookInput(stripeEvent('payment_intent.succeeded', $pi)))->eventType)->toBe('unknown')
            ->and($gw->parseWebhook(stripeWebhookInput(stripeEvent('charge.succeeded', [
                'id'             => 'ch_3MmlLrLkdIwHu7ix0snN0B15', 'object' => 'charge', 'amount' => 19990, 'currency' => 'brl',
                'payment_intent' => 'pi_3MtwBwLkdIwHu7ix28a3tqPa', 'refunded' => false, 'status' => 'succeeded', 'metadata' => [],
            ])))->eventType)->toBe('unknown');
    });

    test('payment_intent.succeeded de PaymentIntent avulso do EasyEye (legado) → paid', function () {
        $n = stripeGateway()->parseWebhook(stripeWebhookInput(stripeEvent('payment_intent.succeeded', [
            'id'              => 'pi_legacy',
            'object'          => 'payment_intent',
            'amount'          => 19990,
            'amount_received' => 19990,
            'currency'        => 'brl',
            'metadata'        => ['invoice_id' => '9d5b1c2e-0000-4000-8000-000000000001', 'subscription_id' => 'sub-1'],
            'status'          => 'succeeded',
        ])));

        expect($n->eventType)->toBe('paid')
            ->and($n->externalPaymentId)->toBe('pi_legacy')
            ->and($n->status)->toBe('paid')
            ->and($n->amount)->toBe(199.90);
    });

    test('charge.refunded total (Basil, sem charge.invoice): acha a fatura pelo InvoicePayment', function () {
        Http::fake(['api.stripe.com/v1/invoice_payments*' => Http::response([
            'object'   => 'list',
            'url'      => '/v1/invoice_payments',
            'has_more' => false,
            'data'     => [[
                'id'          => 'inpay_1M3USa2eZvKYlo2CBjuwbq0N',
                'object'      => 'invoice_payment',
                'amount_paid' => 19990,
                'currency'    => 'brl',
                'invoice'     => 'in_1MtHbELkdIwHu7ixl4OzzPMv',
                'is_default'  => true,
                'payment'     => ['type' => 'payment_intent', 'payment_intent' => 'pi_3MtwBwLkdIwHu7ix28a3tqPa'],
                'status'      => 'paid',
            ]],
        ])]);

        $n = stripeGateway()->parseWebhook(stripeWebhookInput(stripeEvent('charge.refunded', [
            'id'              => 'ch_3MmlLrLkdIwHu7ix0snN0B15',
            'object'          => 'charge',
            'amount'          => 19990,
            'amount_refunded' => 19990,
            'currency'        => 'brl',
            'metadata'        => [],
            'payment_intent'  => 'pi_3MtwBwLkdIwHu7ix28a3tqPa',
            'refunded'        => true,
            'status'          => 'succeeded',
        ])));

        expect($n->eventType)->toBe('refunded')
            ->and($n->externalPaymentId)->toBe('in_1MtHbELkdIwHu7ixl4OzzPMv')
            ->and($n->amount)->toBe(199.90);

        Http::assertSent(fn (Request $r) => $r->method() === 'GET'
            && str_starts_with($r->url(), 'https://api.stripe.com/v1/invoice_payments?')
            && stripeQuery($r)['payment'] === ['type' => 'payment_intent', 'payment_intent' => 'pi_3MtwBwLkdIwHu7ix28a3tqPa']);
    });

    test('charge.refunded com charge.invoice (endpoint em versão anterior à Basil) não consulta a API', function () {
        Http::fake();

        $n = stripeGateway()->parseWebhook(stripeWebhookInput(stripeEvent('charge.refunded', [
            'id'      => 'ch_1', 'object' => 'charge', 'amount' => 19990, 'amount_refunded' => 19990, 'currency' => 'brl',
            'invoice' => 'in_1MtHbELkdIwHu7ixl4OzzPMv', 'payment_intent' => 'pi_1', 'refunded' => true, 'status' => 'succeeded',
        ], ['api_version' => '2024-06-20'])));

        expect($n->eventType)->toBe('refunded')->and($n->externalPaymentId)->toBe('in_1MtHbELkdIwHu7ixl4OzzPMv');
        Http::assertNothingSent();
    });

    test('estorno parcial (refunded=false) não estorna a fatura', function () {
        Http::fake();

        $n = stripeGateway()->parseWebhook(stripeWebhookInput(stripeEvent('charge.refunded', [
            'id'             => 'ch_1', 'object' => 'charge', 'amount' => 19990, 'amount_refunded' => 5000, 'currency' => 'brl',
            'payment_intent' => 'pi_1', 'refunded' => false, 'status' => 'succeeded',
        ])));

        expect($n->eventType)->toBe('unknown');
        Http::assertNothingSent();
    });

    test('charge.dispute.created → chargeback na fatura do PaymentIntent', function () {
        Http::fake(['api.stripe.com/v1/invoice_payments*' => Http::response([
            'object' => 'list',
            'data'   => [['id' => 'inpay_1', 'object' => 'invoice_payment', 'invoice' => 'in_1MtHbELkdIwHu7ixl4OzzPMv']],
        ])]);

        $n = stripeGateway()->parseWebhook(stripeWebhookInput(stripeEvent('charge.dispute.created', [
            'id'             => 'du_1MtJUT2eZvKYlo2CNaw2HvEv',
            'object'         => 'dispute',
            'amount'         => 19990,
            'charge'         => 'ch_1AZtxr2eZvKYlo2CJDX8whov',
            'currency'       => 'brl',
            'metadata'       => [],
            'payment_intent' => 'pi_3MtwBwLkdIwHu7ix28a3tqPa',
            'reason'         => 'fraudulent',
            'status'         => 'needs_response',
        ])));

        expect($n->eventType)->toBe('chargeback')
            ->and($n->externalPaymentId)->toBe('in_1MtHbELkdIwHu7ixl4OzzPMv')
            ->and($n->status)->toBe('chargeback')
            ->and($n->amount)->toBe(199.90);
    });

    test('InvoicePayment indisponível (5xx) lança para o job do webhook tentar de novo', function () {
        Http::fake(['api.stripe.com/*' => Http::response(['error' => ['type' => 'api_error', 'message' => 'Internal']], 503)]);

        expect(fn () => stripeGateway()->parseWebhook(stripeWebhookInput(stripeEvent('charge.refunded', [
            'id' => 'ch_1', 'object' => 'charge', 'amount_refunded' => 19990, 'payment_intent' => 'pi_1', 'refunded' => true, 'currency' => 'brl',
        ]))))->toThrow(GatewayIntegrationException::class);
    });

    test('sem fatura para o PaymentIntent (cobrança avulsa legada) usa o próprio pi_', function () {
        Http::fake(['api.stripe.com/v1/invoice_payments*' => Http::response(['object' => 'list', 'data' => [], 'has_more' => false])]);

        $n = stripeGateway()->parseWebhook(stripeWebhookInput(stripeEvent('charge.refunded', [
            'id' => 'ch_1', 'object' => 'charge', 'amount_refunded' => 19990, 'payment_intent' => 'pi_legacy', 'refunded' => true, 'currency' => 'brl',
        ])));

        expect($n->externalPaymentId)->toBe('pi_legacy');
    });

    test('a chave de idempotência do webhook é o id do evento', function () {
        expect(stripeGateway()->webhookEventKey(stripeEvent('invoice.paid', stripeInvoiceObject())))
            ->toBe('evt_1NG8Du2eZvKYlo2CUI79vXWy');
    });
});
