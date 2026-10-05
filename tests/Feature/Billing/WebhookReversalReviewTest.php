<?php

declare(strict_types=1);

use App\Enums\Billing\{BillingEventType, InvoiceStatus, PaymentStatus};
use App\Enums\{BillingCycle, ClientRule, SubscriptionStatus};
use App\Models\Billing\{BillingLog, FinancialEvent, Invoice, Payment, WebhookEvent};
use App\Models\{Entity, Plan, PlanPrice, Subscription, User};
use App\Services\Billing\ProcessWebhookEventService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\{Factory as HttpFactory, Request};
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{Cache, Http, Queue};

/**
 * Revisão (rodada 4) — estorno/chargeback na ASSINATURA:
 *  - estorno da cobrança que quitou a fatura nunca cai no atalho de
 *    "cobrança substituída" (detached_charges);
 *  - estorno/chargeback do pagamento em DUPLICIDADE não volta a fatura nem
 *    põe a assinatura em atraso;
 *  - reentrega do estorno (depois da retenção de webhook_events) não duplica
 *    evento financeiro/log;
 *  - corrida cartão aprovado × webhook da MESMA cobrança não gera alerta
 *    crítico de "estornar".
 */
beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-05 10:00:00'));
    Http::preventStrayRequests();
    Queue::fake();
    Cache::flush();

    config([
        'billing.enforce_subscription_access'     => true,
        'billing.default_gateway'                 => 'mercadopago',
        'billing.gateways.mercadopago.base_url'   => 'https://api.mercadopago.com',
        'billing.gateways.mercadopago.secret'     => 'APP_USR-secret',
        'billing.gateways.mercadopago.public_key' => 'APP_USR-public',
        'billing.checkout.lock_wait_seconds'      => 0,
    ]);

    $this->plan = Plan::factory()->create(['name' => 'Pro', 'billing_cycle' => 'monthly', 'price' => 299.90, 'active' => true]);
    PlanPrice::create(['plan_id' => $this->plan->id, 'billing_cycle' => 'monthly', 'price' => 299.90]);

    $this->clinic                = Entity::factory()->make(['is_client' => true, 'active' => true, 'name' => 'Clínica Olhar Ltda', 'email' => 'financeiro@olhar.test', 'national_registration' => '11222333000181']);
    $this->clinic->skipAutoTrial = true;
    $this->clinic->save();

    $this->user   = User::factory()->create();
    $this->member = createEntityUser($this->clinic, $this->user, ClientRule::Admin->value, isOwner: true);
});

afterEach(fn () => Carbon::setTestNow());

function wrvAs(): mixed
{
    return test()->actingAs(test()->user)->withSession(panelSession(test()->member));
}

/** Em atraso no Mercado Pago com a fatura de 01/10 em Pix (cobrança 111, Payment pendente). */
function wrvOverdue(): array
{
    $sub = Subscription::factory()->gateway('mercadopago')->for(test()->clinic)->for(test()->plan)->create([
        'gateway_subscription_id' => null,
        'gateway_customer_id'     => '1234567-cus',
        'billing_cycle'           => BillingCycle::Monthly,
        'status'                  => SubscriptionStatus::PastDue,
        'billing_state'           => 'past_due',
        'amount'                  => 299.90,
        'last_payment_at'         => '2026-09-01 10:00:00',
        'starts_at'               => '2026-08-01 10:00:00',
        'ends_at'                 => '2026-10-01 23:59:59',
        'next_billing_at'         => '2026-10-01 23:59:59',
        'past_due_at'             => '2026-10-01 23:59:59',
    ]);

    $invoice = Invoice::query()->create([
        'entity_id'           => $sub->entity_id,
        'subscription_id'     => $sub->id,
        'plan_id'             => $sub->plan_id,
        'gateway_code'        => 'mercadopago',
        'reference'           => 'INV-20261001-R4',
        'period_start'        => '2026-10-01',
        'period_end'          => '2026-11-01',
        'due_at'              => '2026-10-01 23:59:59',
        'amount'              => 299.90,
        'currency'            => 'BRL',
        'status'              => InvoiceStatus::Overdue->value,
        'external_invoice_id' => '111',
        'payment_url'         => 'https://www.mercadopago.com.br/payments/111/ticket?caller_id=1',
        'raw_gateway_payload' => [
            'id'                   => 111,
            'status'               => 'pending',
            'payment_method_id'    => 'pix',
            'date_of_expiration'   => '2026-10-31T23:59:59.000-03:00',
            'point_of_interaction' => ['transaction_data' => ['qr_code' => '00020101pix-111', 'qr_code_base64' => 'iVBORw0KGgo=']],
        ],
    ]);

    Payment::query()->create([
        'entity_id'           => $sub->entity_id,
        'invoice_id'          => $invoice->id,
        'subscription_id'     => $sub->id,
        'gateway_code'        => 'mercadopago',
        'external_payment_id' => '111',
        'status'              => PaymentStatus::Pending->value,
        'amount'              => 299.90,
        'currency'            => 'BRL',
        'idempotency_key'     => 'payment-r4-' . $invoice->id,
    ]);

    $sub->update(['current_invoice_id' => $invoice->id]);

    return [$sub->fresh(), $invoice->fresh()];
}

/** Processa um webhook do Mercado Pago; a consulta do pagamento devolve $payment. */
function wrvHook(string $paymentId, array $payment, string $eventId): WebhookEvent
{
    // Fakes novos a cada evento (o primeiro stub que casa a URL venceria).
    Http::swap(new HttpFactory());
    Http::preventStrayRequests();
    Http::fake(["https://api.mercadopago.com/v1/payments/{$paymentId}" => Http::response($payment)]);

    $event = WebhookEvent::query()->create([
        'gateway_code'      => 'mercadopago',
        'external_event_id' => $eventId,
        'payload'           => ['type' => 'payment', 'action' => 'payment.updated', 'data' => ['id' => $paymentId]],
        'headers'           => [],
        'status'            => 'received',
        'received_at'       => now(),
        'event_hash'        => hash('sha256', $eventId),
    ]);

    app(ProcessWebhookEventService::class)->process($event);

    return $event->fresh();
}

function wrvPayment(int $id, string $status, string $invoiceId): array
{
    return ['id' => $id, 'status' => $status, 'transaction_amount' => 299.90, 'external_reference' => $invoiceId];
}

it('estorno da cobrança desligada que QUITOU a fatura segue o estorno normal (não é "cobrança substituída")', function () {
    [$subscription, $invoice] = wrvOverdue();

    // Cliente abre o boleto (222): o Pix 111 fica desligado (detached), mas mantido.
    Http::fake(['https://api.mercadopago.com/v1/payments' => Http::response([
        'id'                  => 222, 'status' => 'pending', 'payment_method_id' => 'bolbradesco', 'transaction_amount' => 299.90,
        'transaction_details' => ['external_resource_url' => 'https://www.mercadopago.com.br/payments/222/ticket', 'digitable_line' => '2379338128600'],
    ], 201)]);
    wrvAs()->getJson(route('panel.my-subscription.instructions', ['invoice' => $invoice->id, 'method' => 'pix']))->assertOk();
    wrvAs()->postJson(route('panel.my-subscription.charge', $invoice->id), ['method' => 'boleto'])->assertOk();

    expect($invoice->fresh()->metadata['detached_charges'])->toBe(['111'])
        ->and($invoice->fresh()->external_invoice_id)->toBe('222');

    // A assinatura sai do gateway (manager encerrou) e o cliente paga o Pix:
    // o dinheiro entra na fatura, sem mudar a assinatura.
    $subscription->update(['status' => SubscriptionStatus::Cancelled, 'cancelled_at' => now()]);

    $paid = wrvHook('111', wrvPayment(111, 'approved', $invoice->id), 'evt-111-approved');
    expect($paid->normalized_payload['outcome'])->toBe('alert_payment_not_applied')
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->fresh()->external_invoice_id)->toBe('222');

    // Estorno do Pix que pagou: antes caía em ignored_replaced_charge e o Payment seguia pago.
    $refund = wrvHook('111', wrvPayment(111, 'refunded', $invoice->id), 'evt-111-refunded');

    expect($refund->normalized_payload['outcome'])->toBe('refunded')
        ->and(Payment::query()->where('external_payment_id', '111')->value('status'))->toBe(PaymentStatus::Refunded)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Refunded)
        ->and(FinancialEvent::query()->where('invoice_id', $invoice->id)->where('event_type', BillingEventType::PaymentRefunded->value)->count())->toBe(1);
});

it('chargeback do pagamento em DUPLICIDADE: só esse pagamento — fatura segue paga e a assinatura não entra em atraso', function () {
    [$subscription, $invoice] = wrvOverdue();

    $renewed = wrvHook('111', wrvPayment(111, 'approved', $invoice->id), 'evt-111-approved');
    expect($renewed->normalized_payload['outcome'])->toBe('renewed')
        ->and($subscription->fresh()->status)->toBe(SubscriptionStatus::Active);

    // Outra cobrança com a mesma referência (ex.: Pix emitido numa tentativa
    // que não voltou do gateway) também foi paga: alerta de duplicidade.
    $dup = wrvHook('333', wrvPayment(333, 'approved', $invoice->id), 'evt-333-approved');
    expect($dup->normalized_payload['outcome'])->toBe('alert_invoice_already_paid');

    $chargeback = wrvHook('333', wrvPayment(333, 'charged_back', $invoice->id), 'evt-333-chargeback');

    expect($chargeback->normalized_payload['outcome'])->toBe('duplicate_payment_chargeback')
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($subscription->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->fresh()->billing_state)->not->toBe('chargeback')
        ->and(Payment::query()->where('external_payment_id', '111')->value('status'))->toBe(PaymentStatus::Paid)
        ->and(Payment::query()->where('external_payment_id', '333')->value('status'))->toBe(PaymentStatus::Chargeback)
        ->and(FinancialEvent::query()->where('event_type', BillingEventType::ChargebackReceived->value)->first()?->metadata['duplicate_payment'] ?? null)->toBeTrue();

    // Estorno do pagamento que valeu continua revertendo normalmente.
    expect(wrvHook('111', wrvPayment(111, 'refunded', $invoice->id), 'evt-111-refunded')->normalized_payload['outcome'])->toBe('refunded')
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Refunded);
});

it('reentrega do estorno/chargeback (evento já apagado pela retenção) não duplica evento financeiro, log nem atraso', function () {
    [$subscription, $invoice] = wrvOverdue();
    wrvHook('111', wrvPayment(111, 'approved', $invoice->id), 'evt-111-approved');

    wrvHook('111', wrvPayment(111, 'refunded', $invoice->id), 'evt-111-refunded');
    $logs = BillingLog::query()->count();

    // billing:prune-webhook-events apagou o evento; o gateway reentrega.
    WebhookEvent::query()->delete();
    $again = wrvHook('111', wrvPayment(111, 'refunded', $invoice->id), 'evt-111-refunded');

    expect($again->normalized_payload['outcome'])->toBe('duplicate')
        ->and(FinancialEvent::query()->where('event_type', BillingEventType::PaymentRefunded->value)->count())->toBe(1)
        // só o "Webhook processado." da reentrega
        ->and(BillingLog::query()->count())->toBe($logs + 1);
});

it('reentrega do chargeback não marca atraso de novo', function () {
    [$subscription, $invoice] = wrvOverdue();
    wrvHook('111', wrvPayment(111, 'approved', $invoice->id), 'evt-111-approved');

    expect(wrvHook('111', wrvPayment(111, 'charged_back', $invoice->id), 'evt-111-cb')->normalized_payload['outcome'])->toBe('chargeback');

    // Clínica regularizou por fora e o manager reativou; o gateway reentrega o chargeback.
    $subscription->update(['status' => SubscriptionStatus::Active, 'billing_state' => 'paid', 'past_due_at' => null]);
    WebhookEvent::query()->delete();

    expect(wrvHook('111', wrvPayment(111, 'charged_back', $invoice->id), 'evt-111-cb')->normalized_payload['outcome'])->toBe('duplicate')
        ->and($subscription->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and(FinancialEvent::query()->where('event_type', BillingEventType::ChargebackReceived->value)->count())->toBe(1);
});

it('cartão aprovado × webhook da MESMA cobrança antes da resposta: sem alerta crítico de estorno; o cartão vira o da renovação', function () {
    [$subscription, $invoice] = wrvOverdue();

    Http::fake([
        'https://api.mercadopago.com/v1/payments/111'                => Http::response(['id' => 111, 'status' => 'cancelled']),
        'https://api.mercadopago.com/v1/payments/333'                => Http::response(wrvPayment(333, 'approved', $invoice->id)),
        'https://api.mercadopago.com/v1/customers/1234567-cus/cards' => Http::response(['id' => '9876543210', 'last_four_digits' => '6351', 'payment_method' => ['id' => 'master']], 201),
        'https://api.mercadopago.com/v1/payments'                    => function (Request $request) use ($invoice) {
            // O webhook do pagamento 333 chega e é processado antes de o gateway responder.
            $event = WebhookEvent::query()->create([
                'gateway_code'      => 'mercadopago',
                'external_event_id' => 'evt-333-race',
                'payload'           => ['type' => 'payment', 'action' => 'payment.updated', 'data' => ['id' => '333']],
                'headers'           => [],
                'status'            => 'received',
                'received_at'       => now(),
                'event_hash'        => hash('sha256', 'evt-333-race'),
            ]);
            app(ProcessWebhookEventService::class)->process($event);
            expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);

            return Http::response([
                'id' => 333, 'status' => 'approved', 'status_detail' => 'accredited', 'transaction_amount' => 299.90, 'payment_method_id' => 'master',
            ], 201);
        },
    ]);

    wrvAs()->postJson(route('panel.my-subscription.card', $invoice->id), [
        'card_token'        => 'ff8080814c11e237014c1ff593b57b4d',
        'payment_method_id' => 'master',
        'issuer_id'         => '24',
    ])->assertOk()->assertJsonPath('data.status', 'paid');

    expect(BillingLog::query()->where('level', 'critical')->count())->toBe(0)
        ->and(BillingLog::query()->where('message', 'like', '%estornar%')->count())->toBe(0)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($subscription->fresh()->gateway_card_id)->toBe('9876543210')
        ->and($subscription->fresh()->card_last4)->toBe('6351');
});
