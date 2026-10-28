<?php

declare(strict_types=1);

use App\Enums\Billing\{InvoiceStatus, PaymentStatus};
use App\Enums\{BillingCycle, SubscriptionStatus};
use App\Events\Billing\InvoiceFullyReversed;
use App\Models\Billing\{Invoice, Payment, WebhookEvent};
use App\Models\{Entity, Plan, PlanPrice, Subscription};
use App\Services\Billing\ProcessWebhookEventService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{Cache, Event, Http, Queue};

/**
 * Os pontos de disparo do InvoiceFullyReversed no billing (webhook de assinatura): só estorno TOTAL e chargeback da
 * cobrança que quitou a fatura. Estorno parcial e pagamento em duplicidade NUNCA anunciam. O cancelamento da nota em si
 * (listener + serviço) está em NfseCancelTest.
 */
beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-05 10:00:00'));
    Http::preventStrayRequests();
    Queue::fake();
    Cache::flush();
    Event::fake([InvoiceFullyReversed::class]);

    config([
        'billing.enforce_subscription_access'     => true,
        'billing.default_gateway'                 => 'mercadopago',
        'billing.gateways.mercadopago.base_url'   => 'https://api.mercadopago.com',
        'billing.gateways.mercadopago.secret'     => 'APP_USR-secret',
        'billing.gateways.mercadopago.public_key' => 'APP_USR-public',
    ]);

    $this->plan = Plan::factory()->create(['name' => 'Pro', 'billing_cycle' => 'monthly', 'price' => 299.90, 'active' => true]);
    PlanPrice::create(['plan_id' => $this->plan->id, 'billing_cycle' => 'monthly', 'price' => 299.90]);

    $this->clinic                = Entity::factory()->make(['is_client' => true, 'active' => true]);
    $this->clinic->skipAutoTrial = true;
    $this->clinic->save();
});

afterEach(fn () => Carbon::setTestNow());

/** Assinatura em atraso com a fatura de 01/10 e o Pix 111 pendente. */
function nrfOverdue(): Invoice
{
    $sub = Subscription::factory()->gateway('mercadopago')->for(test()->clinic)->for(test()->plan)->create([
        'gateway_subscription_id' => null, 'gateway_customer_id' => '1234567-cus', 'billing_cycle' => BillingCycle::Monthly,
        'status'                  => SubscriptionStatus::PastDue, 'billing_state' => 'past_due', 'amount' => 299.90,
        'last_payment_at'         => '2026-09-01 10:00:00', 'starts_at' => '2026-08-01 10:00:00', 'ends_at' => '2026-10-01 23:59:59',
        'next_billing_at'         => '2026-10-01 23:59:59', 'past_due_at' => '2026-10-01 23:59:59',
    ]);

    $invoice = Invoice::query()->create([
        'entity_id'           => $sub->entity_id, 'subscription_id' => $sub->id, 'plan_id' => $sub->plan_id, 'gateway_code' => 'mercadopago',
        'reference'           => 'INV-20261001-NFSE', 'period_start' => '2026-10-01', 'period_end' => '2026-11-01',
        'due_at'              => '2026-10-01 23:59:59', 'amount' => 299.90, 'currency' => 'BRL', 'status' => InvoiceStatus::Overdue->value,
        'external_invoice_id' => '111',
    ]);

    Payment::query()->create([
        'entity_id'           => $sub->entity_id, 'invoice_id' => $invoice->id, 'subscription_id' => $sub->id, 'gateway_code' => 'mercadopago',
        'external_payment_id' => '111', 'status' => PaymentStatus::Pending->value, 'amount' => 299.90, 'currency' => 'BRL',
        'idempotency_key'     => 'payment-nfse-' . $invoice->id,
    ]);

    $sub->update(['current_invoice_id' => $invoice->id]);

    return $invoice->fresh();
}

function nrfHook(string $paymentId, string $status, string $invoiceId, string $eventId, array $extra = []): WebhookEvent
{
    Http::swap(new HttpFactory());
    Http::preventStrayRequests();
    Http::fake(["https://api.mercadopago.com/v1/payments/{$paymentId}" => Http::response(['id' => (int) $paymentId, 'status' => $status, 'transaction_amount' => 299.90, 'external_reference' => $invoiceId] + $extra)]);

    $event = WebhookEvent::query()->create([
        'gateway_code' => 'mercadopago', 'external_event_id' => $eventId, 'headers' => [], 'status' => 'received', 'received_at' => now(),
        'payload'      => ['type' => 'payment', 'action' => 'payment.updated', 'data' => ['id' => $paymentId]], 'event_hash' => hash('sha256', $eventId),
    ]);

    app(ProcessWebhookEventService::class)->process($event);

    return $event->fresh();
}

/** Fatura quitada pelo Pix 111. */
function nrfPaid(): Invoice
{
    $invoice = nrfOverdue();

    expect(nrfHook('111', 'approved', $invoice->id, 'evt-111-approved')->normalized_payload['outcome'])->toBe('renewed');

    return $invoice->fresh();
}

it('estorno TOTAL anuncia a devolução da fatura (motivo refund), uma vez, depois do commit', function () {
    $invoice = nrfPaid();

    Event::assertNotDispatched(InvoiceFullyReversed::class);

    expect(nrfHook('111', 'refunded', $invoice->id, 'evt-111-refunded')->normalized_payload['outcome'])->toBe('refunded')
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Refunded);

    Event::assertDispatchedTimes(InvoiceFullyReversed::class, 1);
    Event::assertDispatched(InvoiceFullyReversed::class, fn (InvoiceFullyReversed $e) => $e->invoiceId === $invoice->id && $e->reason === 'refund' && $e->correlationId !== null);
});

it('chargeback anuncia a devolução (motivo chargeback)', function () {
    $invoice = nrfPaid();

    nrfHook('111', 'charged_back', $invoice->id, 'evt-111-chargeback');

    Event::assertDispatchedTimes(InvoiceFullyReversed::class, 1);
    Event::assertDispatched(InvoiceFullyReversed::class, fn (InvoiceFullyReversed $e) => $e->invoiceId === $invoice->id && $e->reason === 'chargeback');
});

it('estorno PARCIAL não anuncia nada (a nota segue valendo)', function () {
    $invoice = nrfPaid();

    nrfHook('111', 'approved', $invoice->id, 'evt-111-partial', ['transaction_amount_refunded' => 100.00]);

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
    Event::assertNotDispatched(InvoiceFullyReversed::class);
});

it('estorno e chargeback do pagamento em DUPLICIDADE não anunciam (a fatura segue paga pela outra cobrança)', function () {
    $invoice = nrfPaid();

    nrfHook('333', 'approved', $invoice->id, 'evt-333-approved');
    nrfHook('333', 'charged_back', $invoice->id, 'evt-333-chargeback');
    nrfHook('333', 'refunded', $invoice->id, 'evt-333-refunded');

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
    Event::assertNotDispatched(InvoiceFullyReversed::class);
});

it('a entrega repetida do aviso de pagamento não anuncia devolução', function () {
    $invoice = nrfPaid();

    nrfHook('111', 'approved', $invoice->id, 'evt-111-approved-again');

    Event::assertNotDispatched(InvoiceFullyReversed::class);
});
