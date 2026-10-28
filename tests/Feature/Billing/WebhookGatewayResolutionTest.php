<?php

use App\Enums\Billing\{InvoiceStatus, PaymentStatus};
use App\Enums\{BillingCycle, SubscriptionStatus};
use App\Exceptions\Billing\GatewayIntegrationException;
use App\Jobs\Billing\ProcessBillingWebhookJob;
use App\Models\Billing\{Payment, WebhookEvent};
use App\Models\{Entity, Plan, PlanPrice, Subscription};
use App\Services\Billing\{BillingSubscriptionOrchestrator, WebhookIngestionService};
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * InfinitePay, PagBank e Mercado Pago não mandam o id da nossa assinatura no
 * webhook: a cobrança é achada pelo Payment gravado na ativação (id externo),
 * e no Mercado Pago o status vem da consulta ao pagamento (fetchPayment).
 * Webhooks entram pela ingestão real, com a assinatura de cada gateway.
 */
beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-05 10:00:00'));
    Http::preventStrayRequests();

    config([
        // InfinitePay (Checkout Integrado): sem segredo — a loja é a InfiniteTag.
        'billing.gateways.infinitepay.handle'         => 'easyeye',
        'billing.gateways.pagbank.webhook_secret'     => 'tok_pagbank_teste',
        'billing.gateways.mercadopago.webhook_secret' => 'whsec_mercadopago_teste',
    ]);

    $this->clinic                = Entity::factory()->make(['is_client' => true, 'active' => true]);
    $this->clinic->skipAutoTrial = true;
    $this->clinic->save();

    $this->plan = Plan::factory()->create(['name' => 'Pro', 'billing_cycle' => 'monthly', 'price' => 299.90, 'active' => true]);
    PlanPrice::create(['plan_id' => $this->plan->id, 'billing_cycle' => 'monthly', 'price' => 299.90]);

    Subscription::factory()->trial(10)->for($this->clinic)->for($this->plan)->create();

    // Resposta da consulta ao pagamento no Mercado Pago (ver gwrFakeMercadoPago).
    $this->mpPayment = 404;
});

afterEach(fn () => Carbon::setTestNow());

function gwrActivate(string $gateway): Subscription
{
    return app(BillingSubscriptionOrchestrator::class)
        ->activateWithGateway(test()->clinic, test()->plan, BillingCycle::Monthly, $gateway);
}

/** Ingestão real (validação de assinatura) + processamento. */
function gwrIngest(string $gateway, array $payload, callable $sign): WebhookEvent
{
    $body  = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $event = app(WebhookIngestionService::class)->ingest($gateway, $sign($body, $payload), $body);

    ProcessBillingWebhookJob::dispatchSync((string) $event->id);

    return $event->fresh();
}

/**
 * PagBank: x-authenticity-token = sha256("{token}-{corpo}").
 * https://developer.pagbank.com.br/reference/confirmar-autenticidade-da-notificacao.
 */
function gwrPagBankSignature(string $body): array
{
    return ['x-authenticity-token' => hash('sha256', 'tok_pagbank_teste-' . $body)];
}

function gwrMercadoPagoSignature(array $payload, string $requestId = 'bb56a2f1-6aae-46ac-982e-9dcd3581d08e'): array
{
    $ts = '1759669200';
    // Manifesto da doc oficial, com o ";" final.
    $hash = hash_hmac('sha256', "id:{$payload['data']['id']};request-id:{$requestId};ts:{$ts};", 'whsec_mercadopago_teste');

    return ['x-signature' => "ts={$ts},v1={$hash}", 'x-request-id' => $requestId];
}

/**
 * InfinitePay (Checkout Integrado): a cobrança é um link (POST /links → {url})
 * e o id externo é o nosso order_nsu; o webhook (só na aprovação, sem
 * assinatura) é confirmado no payment_check antes de aplicar.
 * https://www.infinitepay.io/checkout-documentacao.
 */
function gwrFakeInfinitePay(bool $paid = true): void
{
    Http::fake([
        'https://api.checkout.infinitepay.io/links'         => Http::response(['url' => 'https://checkout.infinitepay.com.br/easyeye?lenc=G7xQ2kP9']),
        'https://api.checkout.infinitepay.io/payment_check' => Http::response([
            'success'        => true,
            'paid'           => $paid,
            'amount'         => 29990,
            'paid_amount'    => 29990,
            'installments'   => 1,
            'capture_method' => 'pix',
        ]),
    ]);
}

function gwrInfinitePayWebhook(string $orderNsu, string $transactionNsu = '5f1d8c2e-3a4b-4c5d-8e9f-0a1b2c3d4e5f'): array
{
    return [
        'invoice_slug'    => 'abc123',
        'amount'          => 29990,
        'paid_amount'     => 29990,
        'installments'    => 1,
        'capture_method'  => 'pix',
        'transaction_nsu' => $transactionNsu,
        'order_nsu'       => $orderNsu,
        'receipt_url'     => 'https://comprovante.infinitepay.io/abc123',
        'items'           => [['quantity' => 1, 'price' => 29990, 'description' => 'Fatura']],
    ];
}

it('InfinitePay: webhook do link pago (confirmado no payment_check) ativa a contratação pelo Payment', function () {
    gwrFakeInfinitePay();

    $subscription = gwrActivate('infinitepay');
    $orderNsu     = (string) Payment::sole()->external_payment_id;

    expect($subscription->status)->toBe(SubscriptionStatus::PastDue)
        ->and($orderNsu)->toStartWith($subscription->current_invoice_id . '.29990.')
        ->and($subscription->currentInvoice->payment_url)->toBe('https://checkout.infinitepay.com.br/easyeye?lenc=G7xQ2kP9');

    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.checkout.infinitepay.io/links'
        && $r['handle'] === 'easyeye'
        && $r['items'][0]['price'] === 29990
        && $r['order_nsu'] === $orderNsu);

    $event = gwrIngest('infinitepay', gwrInfinitePayWebhook($orderNsu), fn () => []);

    $subscription->refresh();

    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.checkout.infinitepay.io/payment_check'
        && $r['order_nsu'] === $orderNsu
        && $r['slug'] === 'abc123');

    expect($event->normalized_payload['outcome'])->toBe('activated')
        ->and($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->ends_at->toDateTimeString())->toBe('2026-11-08 23:59:59')
        ->and($subscription->currentInvoice->status)->toBe(InvoiceStatus::Paid)
        ->and(Payment::sole()->status)->toBe(PaymentStatus::Paid);
});

it('PagBank: notificação do pedido (sem "type") com a cobrança PAID ativa pelo Payment', function () {
    Http::fake(['https://api.pagseguro.com/orders' => Http::response([
        'id'           => 'ORDE_F87334AC-BB8B-42E2-AA85-8579F70AA328',
        'reference_id' => 'ref',
        'charges'      => [['id' => 'CHAR_67D0D02B-4F1C-41C1-B47C-D52E0E2B6CA9', 'status' => 'WAITING', 'amount' => ['value' => 29990, 'currency' => 'BRL']]],
    ])]);

    $subscription = gwrActivate('pagbank');
    $invoice      = $subscription->currentInvoice;

    gwrIngest('pagbank', [
        'id'           => 'ORDE_F87334AC-BB8B-42E2-AA85-8579F70AA328',
        'reference_id' => $invoice->id,
        'created_at'   => '2026-10-05T10:00:01.000-03:00',
        'charges'      => [[
            'id'           => 'CHAR_67D0D02B-4F1C-41C1-B47C-D52E0E2B6CA9',
            'reference_id' => $invoice->id,
            'status'       => 'PAID',
            'paid_at'      => '2026-10-06T08:12:00.000-03:00',
            'amount'       => ['value' => 29990, 'currency' => 'BRL', 'summary' => ['total' => 29990, 'paid' => 29990, 'refunded' => 0]],
        ]],
    ], gwrPagBankSignature(...));

    $subscription->refresh();

    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->ends_at->toDateTimeString())->toBe('2026-11-08 23:59:59')
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
});

/**
 * Mercado Pago: cliente e Pix da ativação (sem preapproval — a renovação é
 * local) + consulta do pagamento, que devolve o que o teste puser em
 * $this->mpPayment (array = pagamento; int = status HTTP de erro).
 */
function gwrFakeMercadoPago(): void
{
    Http::fake(function (Request $request) {
        $url     = $request->url();
        $payment = test()->mpPayment;

        return match (true) {
            str_starts_with($url, 'https://api.mercadopago.com/v1/customers') => Http::response(['id' => '1234567-cbXRL1mI2X']),
            $url === 'https://api.mercadopago.com/v1/payments'                => Http::response([
                'id'                   => 1316372291,
                'status'               => 'pending',
                'transaction_amount'   => 299.90,
                'point_of_interaction' => ['transaction_data' => ['ticket_url' => 'https://www.mercadopago.com.br/payments/1316372291/ticket']],
            ], 201),
            $url === 'https://api.mercadopago.com/v1/payments/1316372291' => is_array($payment)
                ? Http::response($payment)
                : Http::response(['message' => 'error'], $payment),
            default => Http::response(['results' => []]),
        };
    });
}

function gwrMercadoPagoPayment(string $status, Subscription $subscription): array
{
    return [
        'id'                   => 1316372291,
        'status'               => $status,
        'status_detail'        => $status === 'approved' ? 'accredited' : $status,
        'transaction_amount'   => 299.90,
        'currency_id'          => 'BRL',
        'payment_method_id'    => 'pix',
        'external_reference'   => $subscription->current_invoice_id,
        'metadata'             => ['invoice_id' => $subscription->current_invoice_id, 'subscription_id' => $subscription->id],
        'date_of_expiration'   => '2026-10-08T23:59:59.000-03:00',
        'point_of_interaction' => ['transaction_data' => ['ticket_url' => 'https://www.mercadopago.com.br/payments/1316372291/ticket']],
    ];
}

function gwrMercadoPagoNotification(string $notificationId): array
{
    return [
        'action'       => 'payment.updated',
        'api_version'  => 'v1',
        'data'         => ['id' => '1316372291'],
        'date_created' => '2026-10-06T09:00:00Z',
        'id'           => $notificationId,
        'live_mode'    => true,
        'type'         => 'payment',
        'user_id'      => '123456789',
    ];
}

it('Mercado Pago: payment.updated busca o pagamento (fetchPayment) e approved ativa a contratação', function () {
    gwrFakeMercadoPago();
    $subscription    = gwrActivate('mercadopago');
    $this->mpPayment = gwrMercadoPagoPayment('approved', $subscription);

    $notification = gwrMercadoPagoNotification('107592875934');
    gwrIngest('mercadopago', $notification, fn () => gwrMercadoPagoSignature($notification));

    $subscription->refresh();

    Http::assertSent(fn (Request $r) => $r->method() === 'GET' && $r->url() === 'https://api.mercadopago.com/v1/payments/1316372291');

    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->billing_state)->toBe('paid')
        ->and($subscription->ends_at->toDateTimeString())->toBe('2026-11-08 23:59:59')
        ->and($subscription->currentInvoice->payment_url)->toBe('https://www.mercadopago.com.br/payments/1316372291/ticket')
        ->and(Payment::sole()->status)->toBe(PaymentStatus::Paid);
});

it('Mercado Pago: pagamento cancelado (Pix expirado) cancela só a cobrança, não a assinatura', function () {
    gwrFakeMercadoPago();
    $subscription    = gwrActivate('mercadopago');
    $this->mpPayment = gwrMercadoPagoPayment('cancelled', $subscription);

    $notification = gwrMercadoPagoNotification('107592875935');
    gwrIngest('mercadopago', $notification, fn () => gwrMercadoPagoSignature($notification));

    $subscription->refresh();

    expect($subscription->status)->toBe(SubscriptionStatus::PastDue)
        ->and($subscription->billing_state)->toBe('pending_activation')
        ->and($subscription->currentInvoice->status)->toBe(InvoiceStatus::Cancelled)
        ->and(Payment::sole()->status)->toBe(PaymentStatus::Cancelled);
});

it('Mercado Pago: charged_back depois de pago vira chargeback e atraso', function () {
    gwrFakeMercadoPago();
    $subscription = gwrActivate('mercadopago');

    $this->mpPayment = gwrMercadoPagoPayment('approved', $subscription);
    $approved        = gwrMercadoPagoNotification('107592875936');
    gwrIngest('mercadopago', $approved, fn () => gwrMercadoPagoSignature($approved));

    $this->mpPayment = gwrMercadoPagoPayment('charged_back', $subscription);
    $chargeback      = gwrMercadoPagoNotification('107592875937');
    gwrIngest('mercadopago', $chargeback, fn () => gwrMercadoPagoSignature($chargeback));

    $subscription->refresh();

    expect($subscription->status)->toBe(SubscriptionStatus::PastDue)
        ->and($subscription->billing_state)->toBe('chargeback')
        ->and($subscription->past_due_at)->not->toBeNull()
        ->and(Payment::sole()->status)->toBe(PaymentStatus::Chargeback);
});

it('Mercado Pago: falha na consulta ao pagamento deixa o webhook para nova tentativa', function () {
    gwrFakeMercadoPago();
    $subscription    = gwrActivate('mercadopago');
    $this->mpPayment = 500;

    $notification = gwrMercadoPagoNotification('107592875938');
    $body         = json_encode($notification);
    $event        = app(WebhookIngestionService::class)->ingest('mercadopago', gwrMercadoPagoSignature($notification), $body);

    expect(fn () => ProcessBillingWebhookJob::dispatchSync((string) $event->id))->toThrow(GatewayIntegrationException::class);

    expect($event->fresh()->status->value)->toBe('failed')
        ->and($subscription->fresh()->status)->toBe(SubscriptionStatus::PastDue);
});

/**
 * PagBank e InfinitePay não mandam id de evento: o id do pedido/cobrança se
 * repete a cada notificação. A deduplicação distingue notificações
 * diferentes do mesmo recurso (status/tipo) e continua barrando o reenvio
 * idêntico.
 */
function gwrPagBankOrder(string $invoiceId, string $status): array
{
    return [
        'id'           => 'ORDE_F87334AC-BB8B-42E2-AA85-8579F70AA328',
        'reference_id' => $invoiceId,
        'created_at'   => '2026-10-05T10:00:01.000-03:00',
        'charges'      => [[
            'id'           => 'CHAR_67D0D02B-4F1C-41C1-B47C-D52E0E2B6CA9',
            'reference_id' => $invoiceId,
            'status'       => $status,
            'amount'       => ['value' => 29990, 'currency' => 'BRL', 'summary' => ['total' => 29990, 'paid' => $status === 'PAID' ? 29990 : 0, 'refunded' => 0]],
        ]],
    ];
}

it('PagBank: WAITING e depois PAID do mesmo pedido — a 2ª notificação não é descartada e ativa a contratação; o reenvio idêntico é deduplicado', function () {
    Http::fake(['https://api.pagseguro.com/orders' => Http::response([
        'id'      => 'ORDE_F87334AC-BB8B-42E2-AA85-8579F70AA328',
        'charges' => [['id' => 'CHAR_67D0D02B-4F1C-41C1-B47C-D52E0E2B6CA9', 'status' => 'WAITING', 'amount' => ['value' => 29990, 'currency' => 'BRL']]],
    ])]);

    $subscription = gwrActivate('pagbank');
    $invoice      = $subscription->currentInvoice;
    $sign         = gwrPagBankSignature(...);

    $waiting = gwrIngest('pagbank', gwrPagBankOrder($invoice->id, 'WAITING'), $sign);

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::PastDue);

    $paid = gwrIngest('pagbank', gwrPagBankOrder($invoice->id, 'PAID'), $sign);

    expect($paid->id)->not->toBe($waiting->id)
        ->and($paid->status->value)->toBe('processed')
        ->and($subscription->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and(Payment::sole()->status)->toBe(PaymentStatus::Paid);

    // Reenvio da mesma notificação (PAID): o mesmo evento, nada reprocessado.
    $again = gwrIngest('pagbank', gwrPagBankOrder($invoice->id, 'PAID'), $sign);

    expect($again->id)->toBe($paid->id)
        ->and(WebhookEvent::query()->where('gateway_code', 'pagbank')->count())->toBe(2);
});

/*
 * Chargeback: a InfinitePay não notifica (o webhook só sai na aprovação) —
 * a cobertura de chargeback fica no Mercado Pago (charged_back, acima).
 */
it('InfinitePay: o reenvio do mesmo webhook é deduplicado; outra transação do mesmo pedido é outro evento, sem estender de novo', function () {
    gwrFakeInfinitePay();

    $subscription = gwrActivate('infinitepay');
    $orderNsu     = (string) Payment::sole()->external_payment_id;

    $paid = gwrIngest('infinitepay', gwrInfinitePayWebhook($orderNsu), fn () => []);

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($paid->normalized_payload['outcome'])->toBe('activated');

    // Reenvio idêntico: o mesmo evento.
    expect(gwrIngest('infinitepay', gwrInfinitePayWebhook($orderNsu), fn () => [])->id)->toBe($paid->id);

    // Outra transação do mesmo pedido: evento novo, mas o pagamento já está pago.
    $other = gwrIngest('infinitepay', gwrInfinitePayWebhook($orderNsu, '9a8b7c6d-0000-4000-8000-000000000001'), fn () => []);

    expect($other->id)->not->toBe($paid->id)
        ->and($other->normalized_payload['outcome'])->toBe('duplicate')
        ->and($subscription->fresh()->ends_at->toDateTimeString())->toBe('2026-11-08 23:59:59')
        ->and(WebhookEvent::query()->where('gateway_code', 'infinitepay')->count())->toBe(2);
});
