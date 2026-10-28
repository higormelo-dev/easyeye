<?php

declare(strict_types=1);

use App\Enums\Billing\{InvoiceStatus, PaymentStatus, WebhookEventStatus};
use App\Enums\{BillingCycle, SubscriptionBillingMode, SubscriptionStatus};
use App\Exceptions\Billing\GatewayUnauthorizedException;
use App\Models\Billing\{Invoice, Payment, WebhookEvent};
use App\Models\{Entity, Plan, Subscription};
use App\Services\Billing\{ProcessWebhookEventService, WebhookIngestionService};
use App\Support\Billing\PayloadSanitizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{DB, Http, Queue};

/**
 * webhook_events.payload sem dados pessoais: a ingestão grava o corpo
 * passado pelo PayloadSanitizer::cleanPersonal (a assinatura é conferida
 * antes, no corpo bruto) e o reprocessamento do payload limpo pelo
 * ProcessWebhookEventService dá exatamente o mesmo resultado que o bruto —
 * para cada gateway, com os payloads no formato das documentações (os dos
 * testes de contrato, com o cadastro do pagador). Retenção: só os
 * processados antigos saem; a idempotência segue enquanto o evento existe.
 */
beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-08 12:00:00'));
    Http::preventStrayRequests();
    Queue::fake();

    config([
        'app.key'                                 => 'base64:juDvxil36quACCVJwN9w7PXo4rdPcNfYnb1nlunUQgo=',
        'billing.gateways.mercadopago.base_url'   => 'https://api.mercadopago.com',
        'billing.gateways.mercadopago.secret'     => 'APP_USR-secret',
        'billing.gateways.pagarme.base_url'       => 'https://api.pagar.me/core/v5',
        'billing.gateways.pagarme.secret'         => 'sk_test_easyeye',
        'billing.gateways.pagarme.webhook_secret' => 'easyeye:s3nh4-forte',
        'billing.gateways.pagbank.base_url'       => 'https://api.pagseguro.com',
        'billing.gateways.pagbank.secret'         => 'tok_api_pagbank',
        'billing.gateways.stripe_br.base_url'     => 'https://api.stripe.com',
        'billing.gateways.stripe_br.secret'       => 'sk_test_123',
        'billing.gateways.infinitepay.handle'     => 'easyeye',
        'billing.gateways.asaas.base_url'         => 'https://api.asaas.com',
        'billing.gateways.asaas.secret'           => '$aact_test',
    ]);
});

afterEach(fn () => Carbon::setTestNow());

/**
 * Cliente pagante no gateway com a renovação de 08/10 em aberto, ligada à
 * cobrança $chargeId (Payment pendente). Devolve a fatura.
 */
function wppScenario(string $gateway, string $chargeId, float $amount = 299.90): Invoice
{
    $plan                  = Plan::factory()->create(['name' => 'Pro', 'billing_cycle' => 'monthly', 'price' => $amount, 'active' => true]);
    $entity                = Entity::factory()->make(['is_client' => true, 'active' => true]);
    $entity->skipAutoTrial = true;
    $entity->save();

    $subscription = Subscription::factory()->for($entity)->for($plan)->create([
        'billing_mode'            => SubscriptionBillingMode::Gateway,
        'status'                  => SubscriptionStatus::Active,
        'billing_state'           => 'pending',
        'gateway'                 => $gateway,
        'gateway_subscription_id' => null,
        'billing_cycle'           => BillingCycle::Monthly,
        'amount'                  => $amount,
        'starts_at'               => '2026-09-08 00:00:00',
        'ends_at'                 => '2026-10-08 23:59:59',
        'next_billing_at'         => '2026-10-08 23:59:59',
        'last_payment_at'         => '2026-09-08 10:00:00',
    ]);

    $invoice = Invoice::query()->create([
        'id'                  => '0199b0a4-7c11-7d2e-9f3a-5b6c7d8e9f01',
        'entity_id'           => $entity->id,
        'subscription_id'     => $subscription->id,
        'plan_id'             => $plan->id,
        'gateway_code'        => $gateway,
        'reference'           => 'INV-20261008-PRIV',
        'period_start'        => '2026-10-08',
        'period_end'          => '2026-11-08',
        'due_at'              => '2026-10-08 23:59:59',
        'amount'              => $amount,
        'currency'            => 'BRL',
        'status'              => InvoiceStatus::Pending->value,
        'external_invoice_id' => $chargeId,
    ]);

    Payment::query()->create([
        'entity_id'           => $entity->id,
        'invoice_id'          => $invoice->id,
        'subscription_id'     => $subscription->id,
        'gateway_code'        => $gateway,
        'external_payment_id' => $chargeId,
        'status'              => PaymentStatus::Pending->value,
        'amount'              => $amount,
        'currency'            => 'BRL',
        'idempotency_key'     => 'payment-renew-' . $chargeId,
    ]);

    $subscription->update(['current_invoice_id' => $invoice->id]);

    return $invoice;
}

/** Processa o payload guardado e devolve tudo que o processamento decide. */
function wppProcess(string $gateway, array $payload, Invoice $invoice): array
{
    $event = WebhookEvent::query()->create([
        'gateway_code'      => $gateway,
        'external_event_id' => 'evt-privacy',
        'payload'           => $payload,
        'headers'           => [],
        'status'            => 'received',
        'received_at'       => now(),
        'event_hash'        => hash('sha256', 'evt-privacy'),
    ]);

    app(ProcessWebhookEventService::class)->process($event);

    $event        = $event->fresh();
    $invoice      = $invoice->fresh();
    $payment      = Payment::query()->where('invoice_id', $invoice->id)->orderBy('created_at')->first();
    $subscription = Subscription::query()->find($invoice->subscription_id);

    return [
        'status'             => $event->status->value,
        'event_type'         => $event->event_type,
        'normalized_payload' => $event->normalized_payload,
        'invoice'            => [$invoice->status->value, $invoice->external_invoice_id, $invoice->payment_url, (string) $invoice->amount],
        'payment'            => [$payment?->status?->value, (string) $payment?->amount],
        'payments'           => Payment::query()->count(),
        'subscription'       => [$subscription->status->value, $subscription->ends_at?->toDateTimeString(), $subscription->billing_state],
    ];
}

/** O mesmo evento, uma vez com o payload bruto e outra com o limpo, em transações isoladas. */
function wppCompare(string $gateway, array $raw, string $chargeId, float $amount = 299.90): array
{
    $results = [];

    foreach (['raw' => $raw, 'clean' => PayloadSanitizer::cleanPersonal($raw)] as $label => $payload) {
        DB::beginTransaction();

        try {
            $invoice         = wppScenario($gateway, $chargeId, $amount);
            $results[$label] = wppProcess($gateway, $payload, $invoice);
        } finally {
            DB::rollBack();
        }
    }

    return $results;
}

dataset('gateways', [
    'asaas' => fn () => ['asaas', 'pay_080225913252', [
        'id'      => 'evt_05b708f961d739ea7eba7e4db318f621&368604920',
        'event'   => 'PAYMENT_RECEIVED',
        'payment' => [
            'object'            => 'payment',
            'id'                => 'pay_080225913252',
            'customer'          => 'cus_000005219613',
            'subscription'      => null,
            'value'             => 299.9,
            'netValue'          => 294.9,
            'billingType'       => 'CREDIT_CARD',
            'status'            => 'RECEIVED',
            'dueDate'           => '2026-10-08',
            'paymentDate'       => '2026-10-08',
            'externalReference' => '0199b0a4-7c11-7d2e-9f3a-5b6c7d8e9f01',
            'invoiceUrl'        => 'https://www.asaas.com/i/080225913252',
            'creditCard'        => ['creditCardNumber' => '8829', 'creditCardBrand' => 'MASTERCARD', 'creditCardToken' => 'a75a1d98-c52d-4a6b-a413-71e00b193c99'],
        ],
    ], ['a75a1d98-c52d-4a6b-a413-71e00b193c99']],
    'mercadopago' => fn () => ['mercadopago', '1310990029', [
        'id'           => 12345678901,
        'live_mode'    => true,
        'type'         => 'payment',
        'date_created' => '2026-10-08T12:00:00.000-04:00',
        'user_id'      => 44444,
        'api_version'  => 'v1',
        'action'       => 'payment.updated',
        'data'         => ['id' => '1310990029'],
    ], []],
    'pagarme' => fn () => ['pagarme', 'ch_d22356Jf4WuGr8no', [
        'id'         => 'hook_RyEKQO789TRpZjv5',
        'account'    => ['id' => 'acc_pagarme', 'name' => 'EasyEye'],
        'type'       => 'charge.paid',
        'created_at' => '2026-10-08T12:00:00',
        'data'       => [
            'id'          => 'ch_d22356Jf4WuGr8no',
            'code'        => '0199b0a4-7c11-7d2e-9f3a-5b6c7d8e9f01',
            'amount'      => 29990,
            'paid_amount' => 29990,
            'status'      => 'paid',
            'currency'    => 'BRL',
            'customer'    => [
                'id'       => 'cus_pgm1',
                'name'     => 'Clínica Olhar Ltda',
                'email'    => 'financeiro@olhar.com.br',
                'document' => '11222333000181',
                'phones'   => ['mobile_phone' => ['country_code' => '55', 'area_code' => '11', 'number' => '987654321']],
                'address'  => ['line_1' => 'Rua das Flores, 100', 'zip_code' => '01310100', 'city' => 'São Paulo', 'state' => 'SP'],
            ],
            'last_transaction' => [
                'id'               => 'tran_1',
                'transaction_type' => 'credit_card',
                'status'           => 'captured',
                'card'             => ['holder_name' => 'MARIA DA SILVA', 'first_six_digits' => '400000', 'last_four_digits' => '0010', 'brand' => 'Visa'],
            ],
            'order'    => ['id' => 'or_1', 'code' => '0199b0a4-7c11-7d2e-9f3a-5b6c7d8e9f01', 'status' => 'paid'],
            'metadata' => ['invoice_id' => '0199b0a4-7c11-7d2e-9f3a-5b6c7d8e9f01', 'subscription_id' => ''],
        ],
    ], ['Clínica Olhar Ltda', 'financeiro@olhar.com.br', '11222333000181', '987654321', 'Rua das Flores, 100', 'MARIA DA SILVA', '400000']],
    'pagbank' => fn () => ['pagbank', 'ORDE_A1B2C3D4E5F6', [
        'id'           => 'ORDE_A1B2C3D4E5F6',
        'reference_id' => '0199b0a4-7c11-7d2e-9f3a-5b6c7d8e9f01',
        'created_at'   => '2026-10-08T09:00:00.000-03:00',
        'customer'     => ['name' => 'Clínica Olhar Ltda', 'email' => 'financeiro@olhar.com.br', 'tax_id' => '11222333000181', 'phones' => [['country' => '55', 'area' => '11', 'number' => '987654321', 'type' => 'MOBILE']]],
        'charges'      => [[
            'id'             => 'CHAR_1',
            'reference_id'   => '0199b0a4-7c11-7d2e-9f3a-5b6c7d8e9f01',
            'status'         => 'PAID',
            'created_at'     => '2026-10-08T09:00:00.000-03:00',
            'paid_at'        => '2026-10-08T09:01:00.000-03:00',
            'amount'         => ['value' => 29990, 'currency' => 'BRL', 'summary' => ['total' => 29990, 'paid' => 29990, 'refunded' => 0]],
            'payment_method' => ['type' => 'CREDIT_CARD', 'installments' => 1, 'card' => ['brand' => 'visa', 'first_digits' => '411111', 'last_digits' => '1111', 'holder' => ['name' => 'MARIA DA SILVA', 'tax_id' => '12345678909']]],
        ]],
    ], ['Clínica Olhar Ltda', 'financeiro@olhar.com.br', '11222333000181', 'MARIA DA SILVA', '12345678909', '411111']],
    'stripe_br' => fn () => ['stripe_br', 'in_1PzX2b2eZvKYlo2C', [
        'id'      => 'evt_1PzX2c2eZvKYlo2C',
        'object'  => 'event',
        'type'    => 'invoice.paid',
        'created' => 1791460800,
        'data'    => ['object' => [
            'id'                 => 'in_1PzX2b2eZvKYlo2C',
            'object'             => 'invoice',
            'status'             => 'paid',
            'amount_due'         => 29990,
            'amount_paid'        => 29990,
            'currency'           => 'brl',
            'customer'           => 'cus_Stripe1',
            'customer_email'     => 'financeiro@olhar.com.br',
            'customer_name'      => 'Clínica Olhar Ltda',
            'customer_phone'     => '+5511987654321',
            'customer_address'   => ['line1' => 'Rua das Flores, 100', 'postal_code' => '01310100', 'city' => 'São Paulo', 'state' => 'SP', 'country' => 'BR'],
            'customer_tax_ids'   => [['type' => 'br_cnpj', 'value' => '11.222.333/0001-81']],
            'hosted_invoice_url' => 'https://invoice.stripe.com/i/acct_1/test_1',
            'metadata'           => ['invoice_id' => '0199b0a4-7c11-7d2e-9f3a-5b6c7d8e9f01'],
        ]],
    ], ['Clínica Olhar Ltda', 'financeiro@olhar.com.br', '+5511987654321', 'Rua das Flores, 100', '11.222.333/0001-81']],
    'infinitepay' => fn () => ['infinitepay', wppInfinitePayOrderNsu(), [
        'invoice_slug'    => 'abc123',
        'amount'          => 29990,
        'paid_amount'     => 29990,
        'installments'    => 1,
        'capture_method'  => 'pix',
        'transaction_nsu' => '5f1d8c2e-3a4b-4c5d-8e9f-0a1b2c3d4e5f',
        'order_nsu'       => wppInfinitePayOrderNsu(),
        'receipt_url'     => 'https://comprovante.infinitepay.io/abc123',
        'items'           => [['quantity' => 1, 'price' => 29990, 'description' => 'Fatura INV-20261008-PRIV']],
    ], []],
]);

/** order_nsu assinado como o InfinitePayGateway emite ({fatura}.{centavos}.{nonce}.{hmac}). */
function wppInfinitePayOrderNsu(): string
{
    $invoiceId = '0199b0a4-7c11-7d2e-9f3a-5b6c7d8e9f01';
    $nonce     = 'abcdef01';

    return "{$invoiceId}.29990.{$nonce}." . substr(hash_hmac('sha256', "infinitepay|{$invoiceId}|29990|{$nonce}", (string) config('app.key')), 0, 16);
}

it('payload limpo reprocessado dá o mesmo resultado que o bruto — e sem os dados pessoais', function (string $gateway, string $chargeId, array $raw, array $personal) {
    Http::fake([
        'https://api.mercadopago.com/v1/payments/1310990029' => Http::response([
            'id'                 => 1310990029,
            'status'             => 'approved',
            'transaction_amount' => 299.90,
            'external_reference' => '0199b0a4-7c11-7d2e-9f3a-5b6c7d8e9f01',
            'payer'              => ['email' => 'financeiro@olhar.com.br', 'identification' => ['type' => 'CNPJ', 'number' => '11222333000181']],
        ]),
        'https://api.checkout.infinitepay.io/payment_check' => Http::response(['success' => true, 'paid' => true, 'amount' => 29990, 'paid_amount' => 29990, 'installments' => 1, 'capture_method' => 'pix']),
    ]);

    $clean   = PayloadSanitizer::cleanPersonal($raw);
    $results = wppCompare($gateway, $raw, $chargeId);

    // O cenário não é vazio: o pagamento renovou a assinatura.
    expect($results['raw']['status'])->toBe(WebhookEventStatus::Processed->value)
        ->and($results['raw']['normalized_payload']['outcome'])->toBe('renewed')
        ->and($results['raw']['invoice'][0])->toBe(InvoiceStatus::Paid->value)
        ->and($results['raw']['subscription'][1])->toBe('2026-11-08 23:59:59')
        // Mesmo resultado com o payload limpo.
        ->and($results['clean'])->toBe($results['raw']);

    $json = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    foreach ($personal as $value) {
        expect(json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))->toContain($value)
            ->and($json)->not->toContain($value);
    }
})->with('gateways');

it('ingestão: grava o payload limpo; a assinatura (Basic Auth) foi conferida no corpo bruto; reentrega idempotente', function () {
    $raw = ['id' => 'hook_1', 'type' => 'charge.paid', 'created_at' => '2026-10-08T12:00:00', 'data' => [
        'id'       => 'ch_privacy', 'status' => 'paid', 'amount' => 29990,
        'customer' => ['id' => 'cus_1', 'name' => 'Clínica Olhar Ltda', 'email' => 'financeiro@olhar.com.br', 'document' => '11222333000181'],
    ]];
    $body    = json_encode($raw, JSON_UNESCAPED_UNICODE);
    $headers = ['authorization' => ['Basic ' . base64_encode('easyeye:s3nh4-forte')]];

    $event = app(WebhookIngestionService::class)->ingest('pagarme', $headers, $body);

    expect($event->payload['data']['id'])->toBe('ch_privacy')
        ->and($event->payload['data']['status'])->toBe('paid')
        ->and($event->payload['data']['customer'])->toBe(PayloadSanitizer::REDACTED)
        ->and(json_encode($event->fresh()->payload))->not->toContain('financeiro@olhar.com.br')
        // A chave de idempotência e o hash vêm do corpo bruto.
        ->and($event->event_hash)->toBe(hash('sha256', 'pagarme|' . $body))
        ->and(app(WebhookIngestionService::class)->ingest('pagarme', $headers, $body)->id)->toBe($event->id)
        ->and(WebhookEvent::query()->count())->toBe(1);

    // Assinatura errada: recusado antes de gravar qualquer coisa.
    expect(fn () => app(WebhookIngestionService::class)->ingest('pagarme', ['authorization' => ['Basic ' . base64_encode('easyeye:errada')]], $body))
        ->toThrow(GatewayUnauthorizedException::class);
});

it('billing:prune-webhook-events apaga só os processados antigos; billing:sanitize-webhook-events limpa os já gravados (com --dry-run)', function () {
    $make = fn (string $id, string $status, ?string $processedAt, array $payload = ['data' => ['id' => 'x']]) => WebhookEvent::query()->create([
        'gateway_code'      => 'pagarme',
        'external_event_id' => $id,
        'payload'           => $payload,
        'headers'           => [],
        'status'            => $status,
        'received_at'       => now()->subDays(200),
        'processed_at'      => $processedAt,
        'event_hash'        => hash('sha256', $id),
    ]);

    $oldProcessed = $make('old-processed', 'processed', now()->subDays(91)->toDateTimeString(), ['data' => ['id' => 'ch_1', 'customer' => ['name' => 'Clínica Olhar', 'email' => 'a@b.c']]]);
    $newProcessed = $make('new-processed', 'processed', now()->subDays(10)->toDateTimeString());
    $oldFailed    = $make('old-failed', 'failed', null, ['data' => ['id' => 'ch_2', 'customer' => ['email' => 'x@y.z']]]);
    $oldReceived  = $make('old-received', 'received', null);

    $this->artisan('billing:sanitize-webhook-events', ['--dry-run' => true])
        ->expectsOutputToContain('a limpar: 2')
        ->assertSuccessful();
    expect($oldFailed->fresh()->payload['data']['customer']['email'])->toBe('x@y.z');

    $this->artisan('billing:sanitize-webhook-events')->expectsOutputToContain('limpos: 2')->assertSuccessful();
    expect($oldFailed->fresh()->payload['data'])->toBe(['id' => 'ch_2', 'customer' => PayloadSanitizer::REDACTED]);
    $this->artisan('billing:sanitize-webhook-events')->expectsOutputToContain('limpos: 0')->assertSuccessful();

    $this->artisan('billing:prune-webhook-events', ['--dry-run' => true])->expectsOutputToContain('apagados: 1')->assertSuccessful();
    expect(WebhookEvent::query()->count())->toBe(4);

    $this->artisan('billing:prune-webhook-events')->assertSuccessful();

    expect(WebhookEvent::query()->pluck('external_event_id')->sort()->values()->all())->toBe(['new-processed', 'old-failed', 'old-received'])
        ->and($oldProcessed->fresh())->toBeNull()
        ->and($newProcessed->fresh())->not->toBeNull()
        ->and($oldReceived->fresh())->not->toBeNull();

    config(['billing.webhooks.retention_days' => 5]);
    $this->artisan('billing:prune-webhook-events')->assertSuccessful();
    expect($newProcessed->fresh())->toBeNull();
});

it('billing:sanitize-webhook-events também tira dos já gravados os headers com segredo (Basic Auth, token do Asaas, cookie), com --dry-run', function () {
    $make = fn (string $id, array $headers) => WebhookEvent::query()->create([
        'gateway_code'      => 'pagarme',
        'external_event_id' => $id,
        'payload'           => ['data' => ['id' => $id]],
        'headers'           => $headers,
        'status'            => 'processed',
        'received_at'       => now()->subDays(3),
        'processed_at'      => now()->subDays(3),
        'event_hash'        => hash('sha256', $id),
    ]);

    $pagarme = $make('old-pagarme', ['authorization' => ['Basic ' . base64_encode('easyeye:s3nh4-forte')], 'content-type' => ['application/json']]);
    $asaas   = $make('old-asaas', ['asaas-access-token' => ['tok-asaas-webhook'], 'Cookie' => ['XSRF-TOKEN=abc'], 'x-signature' => ['ts=1,v1=deadbeef']]);
    $clean   = $make('clean', ['content-type' => ['application/json']]);

    $this->artisan('billing:sanitize-webhook-events', ['--dry-run' => true])
        ->expectsOutputToContain('headers secretos a limpar: 2')
        ->assertSuccessful();
    expect($pagarme->fresh()->headers)->toHaveKey('authorization');

    $this->artisan('billing:sanitize-webhook-events')->expectsOutputToContain('headers secretos limpos: 2')->assertSuccessful();

    expect($pagarme->fresh()->headers)->toBe(['content-type' => ['application/json']])
        // a assinatura HMAC fica (não revela o segredo)
        ->and($asaas->fresh()->headers)->toBe(['x-signature' => ['ts=1,v1=deadbeef']])
        ->and($clean->fresh()->headers)->toBe(['content-type' => ['application/json']])
        ->and(json_encode(WebhookEvent::query()->pluck('headers')))->not->toContain('s3nh4')->not->toContain('tok-asaas-webhook');

    $this->artisan('billing:sanitize-webhook-events')->expectsOutputToContain('headers secretos limpos: 0')->assertSuccessful();
});

it('a ingestão e a limpeza usam a MESMA lista de headers com segredo', function () {
    $headers = ['Authorization' => ['Basic x'], 'proxy-authorization' => ['y'], 'access_token' => ['z'], 'asaas-access-token' => ['w'], 'cookie' => ['c'], 'x-request-id' => ['r']];

    expect(PayloadSanitizer::storableHeaders($headers))->toBe(['x-request-id' => ['r']]);
});
