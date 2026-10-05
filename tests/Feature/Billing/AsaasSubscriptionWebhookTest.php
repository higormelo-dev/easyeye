<?php

use App\Enums\Billing\{BillingEventType, InvoiceStatus, PaymentStatus};
use App\Enums\{BillingCycle, SubscriptionBillingMode, SubscriptionStatus};
use App\Models\Billing\{BillingLog, FinancialEvent, Invoice, Payment, WebhookEvent};
use App\Models\{Entity, Plan, PlanPrice, Subscription};
use App\Services\Billing\BillingSubscriptionOrchestrator;
use App\Services\SubscriptionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\{Carbon, Str};
use Illuminate\Support\Facades\Http;

/**
 * Webhooks do Asaas → estado da assinatura, pela rota real
 * (/api/billing/webhooks/asaas, token no header asaas-access-token) e com o
 * payload documentado (docs.asaas.com/docs/payment-events). A contratação é
 * feita pelo orquestrador: a 1ª cobrança é a 1ª parcela da assinatura.
 */
beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-05 10:00:00'));
    config(['billing.gateways.asaas.webhook_secret' => 'whsec_asaas_teste']);
    Http::preventStrayRequests();
    awhFakeAsaas();

    $this->clinic                = Entity::factory()->make(['is_client' => true, 'active' => true]);
    $this->clinic->skipAutoTrial = true;
    $this->clinic->save();

    $this->plan = Plan::factory()->create(['name' => 'Pro', 'billing_cycle' => 'monthly', 'price' => 299.90, 'active' => true]);
    PlanPrice::create(['plan_id' => $this->plan->id, 'billing_cycle' => 'monthly', 'price' => 299.90]);

    Subscription::factory()->trial(10)->for($this->clinic)->for($this->plan)->create();

    // Contratação mensal: 1º vencimento em 08/10 (D+3).
    $this->subscription = awhActivate();
});

afterEach(fn () => Carbon::setTestNow());

function awhFakeAsaas(): void
{
    Http::fake(function (Request $request) {
        $url = $request->url();

        if (str_starts_with($url, 'https://api.asaas.com/v3/customers')) {
            return $request->method() === 'GET'
                ? Http::response(['object' => 'list', 'hasMore' => false, 'totalCount' => 0, 'data' => []])
                : Http::response(['object' => 'customer', 'id' => 'cus_000005219613']);
        }

        if ($url === 'https://api.asaas.com/v3/subscriptions' && $request->method() === 'POST') {
            return Http::response([
                'object'            => 'subscription',
                'id'                => 'sub_' . substr(md5((string) $request['externalReference']), 0, 12),
                'customer'          => 'cus_000005219613',
                'billingType'       => $request['billingType'],
                'cycle'             => $request['cycle'],
                'value'             => $request['value'],
                'nextDueDate'       => $request['nextDueDate'],
                'status'            => 'ACTIVE',
                'externalReference' => $request['externalReference'],
            ]);
        }

        if (str_starts_with($url, 'https://api.asaas.com/v3/subscriptions/') && $request->method() === 'DELETE') {
            return Http::response(['deleted' => true, 'id' => basename($url)]);
        }

        return Http::response(['errors' => [['code' => 'not_faked']]], 404);
    });
}

function awhActivate(): Subscription
{
    return app(BillingSubscriptionOrchestrator::class)
        ->activateWithGateway(test()->clinic, test()->plan, BillingCycle::Monthly, 'asaas');
}

/** Evento de cobrança no formato do Asaas (parcela da assinatura). */
function awhEvent(string $event, string $paymentId, array $payment = [], ?Subscription $subscription = null, ?string $eventId = null): array
{
    $subscription ??= test()->subscription;
    $suffix = Str::after($paymentId, 'pay_');

    return [
        'id'          => $eventId ?? 'evt_' . Str::random(32),
        'event'       => $event,
        'dateCreated' => now()->format('Y-m-d H:i:s'),
        'payment'     => array_merge([
            'object'            => 'payment',
            'id'                => $paymentId,
            'dateCreated'       => now()->toDateString(),
            'customer'          => 'cus_000005219613',
            'subscription'      => $subscription->gateway_subscription_id,
            'installment'       => null,
            'paymentLink'       => null,
            'value'             => 299.9,
            'netValue'          => 297.91,
            'originalValue'     => null,
            'interestValue'     => null,
            'description'       => 'Assinatura Pro',
            'billingType'       => 'BOLETO',
            'status'            => 'PENDING',
            'dueDate'           => '2026-10-08',
            'originalDueDate'   => '2026-10-08',
            'paymentDate'       => null,
            'clientPaymentDate' => null,
            'invoiceUrl'        => "https://www.asaas.com/i/{$suffix}",
            'bankSlipUrl'       => "https://www.asaas.com/b/pdf/{$suffix}",
            'invoiceNumber'     => '00012345',
            'externalReference' => $subscription->id,
            'deleted'           => false,
            'anticipated'       => false,
        ], $payment),
    ];
}

function awhPost(array $payload): void
{
    test()->postJson('/api/billing/webhooks/asaas', $payload, ['asaas-access-token' => 'whsec_asaas_teste'])->assertOk();
}

/** 1ª parcela (vence 08/10) paga em 07/10. */
function awhPayFirst(): void
{
    test()->travelTo(CarbonImmutable::parse('2026-10-07 14:30:00'));
    awhPost(awhEvent('PAYMENT_RECEIVED', 'pay_080225913252', ['status' => 'RECEIVED', 'paymentDate' => '2026-10-07', 'clientPaymentDate' => '2026-10-07']));
}

function awhSecondCycle(string $event, array $payment = [], ?string $eventId = null): array
{
    return awhEvent($event, 'pay_196527845301', [
        'dueDate'         => '2026-11-08',
        'originalDueDate' => '2026-11-08',
        ...$payment,
    ], eventId: $eventId);
}

it('1º pagamento da parcela da assinatura ativa a contratação e liga a 1ª fatura', function () {
    // PAYMENT_CREATED da 1ª parcela: guarda id, link e vencimento na fatura.
    awhPost(awhEvent('PAYMENT_CREATED', 'pay_080225913252'));

    $invoice = $this->subscription->currentInvoice->fresh();

    expect($invoice->external_invoice_id)->toBe('pay_080225913252')
        ->and($invoice->payment_url)->toBe('https://www.asaas.com/i/080225913252')
        ->and($invoice->due_at->toDateTimeString())->toBe('2026-10-08 23:59:59')
        ->and(Payment::where('external_payment_id', 'pay_080225913252')->sole()->status)->toBe(PaymentStatus::Pending)
        ->and($this->subscription->fresh()->status)->toBe(SubscriptionStatus::PastDue);

    awhPayFirst();

    $subscription = $this->subscription->fresh();
    $payment      = Payment::where('external_payment_id', 'pay_080225913252')->sole();

    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->billing_state)->toBe('paid')
        ->and($subscription->last_payment_at->toDateTimeString())->toBe('2026-10-07 14:30:00')
        ->and($subscription->ends_at->toDateTimeString())->toBe('2026-11-08 23:59:59')
        ->and($subscription->next_billing_at->toDateTimeString())->toBe('2026-11-08 23:59:59')
        ->and($subscription->past_due_at)->toBeNull()
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($payment->status)->toBe(PaymentStatus::Paid)
        ->and($payment->invoice_id)->toBe($invoice->id)
        ->and(FinancialEvent::where('event_type', BillingEventType::SubscriptionActivated->value)->where('subscription_id', $subscription->id)->count())->toBe(1);

    // Pago, o acesso vai até o fim do período (fim do dia do próximo vencimento).
    $this->travelTo(CarbonImmutable::parse('2026-11-08 22:00:00'));
    expect(app(SubscriptionService::class)->hasAccess($this->clinic))->toBeTrue();
});

it('pagamento do 2º ciclo estende ends_at/next_billing_at e cria a fatura do período no valor contratado', function () {
    awhPayFirst();
    $firstInvoice = $this->subscription->currentInvoice->fresh();

    // Reajuste do plano não muda o que a clínica contratou (grandfathering).
    $this->plan->update(['price' => 399.90]);
    PlanPrice::where('plan_id', $this->plan->id)->update(['price' => 399.90]);

    // PAYMENT_CREATED perdido: o RECEIVED sozinho cria a fatura do período.
    $this->travelTo(CarbonImmutable::parse('2026-11-08 09:00:00'));
    awhPost(awhSecondCycle('PAYMENT_RECEIVED', ['status' => 'RECEIVED', 'paymentDate' => '2026-11-08']));

    $subscription = $this->subscription->fresh();
    $cycleInvoice = Invoice::where('external_invoice_id', 'pay_196527845301')->sole();

    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->ends_at->toDateTimeString())->toBe('2026-12-08 23:59:59')
        ->and($subscription->next_billing_at->toDateTimeString())->toBe('2026-12-08 23:59:59')
        ->and($subscription->current_invoice_id)->toBe($cycleInvoice->id)
        ->and($cycleInvoice->billing_reason)->toBe('subscription_cycle')
        ->and($cycleInvoice->period_start->toDateString())->toBe('2026-11-08')
        ->and($cycleInvoice->period_end->toDateString())->toBe('2026-12-08')
        ->and((float) $cycleInvoice->amount)->toBe(299.90)
        ->and($cycleInvoice->status)->toBe(InvoiceStatus::Paid)
        ->and($cycleInvoice->payment_url)->toBe('https://www.asaas.com/i/196527845301')
        ->and($cycleInvoice->payments()->pluck('external_payment_id')->all())->toBe(['pay_196527845301'])
        // A fatura do 1º ciclo não recebe o pagamento do 2º.
        ->and($firstInvoice->fresh()->payments()->pluck('external_payment_id')->all())->toBe(['pay_080225913252'])
        ->and($firstInvoice->fresh()->paid_at->equalTo($firstInvoice->paid_at))->toBeTrue();
});

it('o mesmo pagamento nunca estende duas vezes (CONFIRMED + RECEIVED e reenvio do mesmo evento)', function () {
    awhPayFirst();
    $this->travelTo(CarbonImmutable::parse('2026-11-07 11:00:00'));

    $confirmed = awhSecondCycle('PAYMENT_CONFIRMED', ['status' => 'CONFIRMED', 'billingType' => 'CREDIT_CARD'], 'evt_05b708f961d739ea7eba7e4db318f621');

    awhPost($confirmed);
    awhPost($confirmed); // reenvio idêntico do gateway
    awhPost(awhSecondCycle('PAYMENT_RECEIVED', ['status' => 'RECEIVED', 'billingType' => 'CREDIT_CARD']));

    $cycleInvoice = Invoice::where('external_invoice_id', 'pay_196527845301')->sole();

    expect($this->subscription->fresh()->ends_at->toDateTimeString())->toBe('2026-12-08 23:59:59')
        ->and(Payment::where('external_payment_id', 'pay_196527845301')->count())->toBe(1)
        ->and(FinancialEvent::where('event_type', BillingEventType::InvoicePaid->value)->where('invoice_id', $cycleInvoice->id)->count())->toBe(1)
        ->and(WebhookEvent::where('external_event_id', 'evt_05b708f961d739ea7eba7e4db318f621')->count())->toBe(1);
});

it('cobrança vencida de quem já pagou: past_due desde o vencimento, só na fatura dela; pagar depois reativa', function () {
    awhPayFirst();
    $firstInvoice = $this->subscription->currentInvoice->fresh();

    $this->travelTo(CarbonImmutable::parse('2026-11-09 08:00:00'));
    awhPost(awhSecondCycle('PAYMENT_OVERDUE', ['status' => 'OVERDUE']));

    $subscription = $this->subscription->fresh();
    $cycleInvoice = Invoice::where('external_invoice_id', 'pay_196527845301')->sole();

    expect($subscription->status)->toBe(SubscriptionStatus::PastDue)
        ->and($subscription->billing_state)->toBe('past_due')
        ->and($subscription->past_due_at->toDateTimeString())->toBe('2026-11-08 23:59:59')
        ->and($subscription->last_billing_error)->toBe(__('manager_subscriptions.billing_errors.payment_overdue'))
        ->and($subscription->ends_at->toDateTimeString())->toBe('2026-11-08 23:59:59')
        ->and($cycleInvoice->status)->toBe(InvoiceStatus::Overdue)
        ->and($firstInvoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and(Payment::where('external_payment_id', 'pay_196527845301')->sole()->status)->toBe(PaymentStatus::Failed)
        ->and(FinancialEvent::where('event_type', BillingEventType::SubscriptionPastDue->value)->count())->toBe(1);

    $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00:00'));
    awhPost(awhSecondCycle('PAYMENT_RECEIVED', ['status' => 'RECEIVED', 'paymentDate' => '2026-11-10']));

    $subscription = $this->subscription->fresh();

    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->past_due_at)->toBeNull()
        ->and($subscription->ends_at->toDateTimeString())->toBe('2026-12-08 23:59:59');
});

it('contratação vencida sem pagamento segue pendente (sem régua) e perde o acesso no fim do vencimento', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-09 07:00:00'));
    awhPost(awhEvent('PAYMENT_OVERDUE', 'pay_080225913252', ['status' => 'OVERDUE']));

    $subscription = $this->subscription->fresh();

    expect($subscription->status)->toBe(SubscriptionStatus::PastDue)
        ->and($subscription->billing_state)->toBe('payment_failed')
        ->and($subscription->past_due_at)->toBeNull()
        ->and($subscription->last_payment_at)->toBeNull()
        ->and($subscription->hasAccess())->toBeFalse()
        ->and(app(SubscriptionService::class)->hasAccess($this->clinic))->toBeFalse()
        ->and($subscription->currentInvoice->status)->toBe(InvoiceStatus::Overdue);
});

it('PAYMENT_DELETED cancela só a cobrança — a assinatura segue ativa', function () {
    awhPayFirst();
    awhPost(awhSecondCycle('PAYMENT_CREATED'));
    awhPost(awhSecondCycle('PAYMENT_DELETED', ['deleted' => true]));

    $subscription = $this->subscription->fresh();

    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->billing_state)->toBe('paid')
        ->and(Invoice::where('external_invoice_id', 'pay_196527845301')->sole()->status)->toBe(InvoiceStatus::Cancelled)
        ->and(Payment::where('external_payment_id', 'pay_196527845301')->sole()->status)->toBe(PaymentStatus::Cancelled);
});

it('PAYMENT_AWAITING_CHARGEBACK_REVERSAL (disputa ganha) não é chargeback e não bloqueia', function () {
    awhPayFirst();
    awhPost(awhEvent('PAYMENT_AWAITING_CHARGEBACK_REVERSAL', 'pay_080225913252', ['status' => 'AWAITING_CHARGEBACK_REVERSAL']));

    $subscription = $this->subscription->fresh();

    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->billing_state)->toBe('paid')
        ->and(Payment::where('external_payment_id', 'pay_080225913252')->sole()->status)->toBe(PaymentStatus::Paid);
});

it('pagamento de assinatura substituída é registrado com alerta e não a reativa', function () {
    $replaced = $this->subscription;

    // O manager refaz a contratação: a 1ª tentativa é substituída.
    $current = awhActivate();

    expect($replaced->fresh()->status)->toBe(SubscriptionStatus::Cancelled);

    // O cliente paga mesmo assim o boleto da assinatura substituída.
    awhPost(awhEvent('PAYMENT_RECEIVED', 'pay_000000000777', ['status' => 'RECEIVED'], $replaced));

    $payment = Payment::where('external_payment_id', 'pay_000000000777')->sole();
    $alert   = FinancialEvent::where('payment_id', $payment->id)->sole();

    expect($replaced->fresh()->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($replaced->fresh()->last_payment_at)->toBeNull()
        ->and($payment->status)->toBe(PaymentStatus::Paid)
        ->and($alert->event_type)->toBe(BillingEventType::PaymentSucceeded)
        ->and($alert->metadata['alert'])->toBe('subscription_not_billed_by_gateway')
        ->and($current->fresh()->status)->toBe(SubscriptionStatus::PastDue)
        ->and($current->fresh()->last_payment_at)->toBeNull();
});

it('webhook não mexe em cortesia que ainda carrega o id da recorrência antiga', function () {
    $courtesy = Subscription::factory()->complimentary()->for($this->clinic)->for($this->plan)->create([
        'gateway'                 => 'asaas',
        'gateway_subscription_id' => 'sub_convertida_cortesia',
        'ends_at'                 => now()->addMonths(2),
    ]);

    awhPost(awhEvent('PAYMENT_OVERDUE', 'pay_000000000888', ['status' => 'OVERDUE', 'subscription' => 'sub_convertida_cortesia']));
    awhPost(awhEvent('PAYMENT_DELETED', 'pay_000000000888', ['subscription' => 'sub_convertida_cortesia', 'deleted' => true]));

    $courtesy->refresh();

    expect($courtesy->status)->toBe(SubscriptionStatus::Active)
        ->and($courtesy->billing_mode)->toBe(SubscriptionBillingMode::Complimentary)
        ->and($courtesy->past_due_at)->toBeNull()
        ->and($courtesy->hasAccess())->toBeTrue();
});

it('pagamento de assinatura paga e substituída, sem fatura identificável, entra na trilha (Payment + evento de alerta)', function () {
    awhPayFirst();
    $replaced = $this->subscription->fresh();
    $endsAt   = $replaced->ends_at->toDateTimeString();

    // Substituída, mas a recorrência dela seguiu ativa no Asaas (o DELETE
    // falhou e o job esgotou as tentativas): o ciclo seguinte é cobrado.
    $replaced->update(['status' => SubscriptionStatus::Cancelled, 'cancelled_at' => now(), 'cancelled_reason' => 'replaced']);

    $this->travelTo(CarbonImmutable::parse('2026-11-08 10:00:00'));
    awhPost(awhSecondCycle('PAYMENT_RECEIVED', ['status' => 'RECEIVED', 'paymentDate' => '2026-11-08']));

    $payment = Payment::where('external_payment_id', 'pay_196527845301')->sole();
    $alert   = FinancialEvent::where('payment_id', $payment->id)->sole();
    $record  = $payment->invoice;

    expect($payment->status)->toBe(PaymentStatus::Paid)
        ->and($payment->subscription_id)->toBe($replaced->id)
        ->and($payment->entity_id)->toBe($this->clinic->id)
        ->and($payment->gateway_code)->toBe('asaas')
        ->and((float) $payment->amount)->toBe(299.9)
        ->and($record->billing_reason)->toBe('unapplied_payment')
        ->and($record->status)->toBe(InvoiceStatus::Paid)
        ->and($record->entity_id)->toBe($this->clinic->id)
        ->and($record->external_invoice_id)->toBe('pay_196527845301')
        ->and($alert->event_type)->toBe(BillingEventType::PaymentSucceeded)
        ->and($alert->entity_id)->toBe($this->clinic->id)
        ->and((float) $alert->amount)->toBe(299.9)
        ->and($alert->metadata['alert'])->toBe('subscription_not_billed_by_gateway')
        ->and(WebhookEvent::latest('received_at')->first()->normalized_payload['outcome'])->toBe('alert_payment_not_applied')
        // A assinatura substituída não volta nem ganha período.
        ->and($replaced->fresh()->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($replaced->fresh()->ends_at->toDateTimeString())->toBe($endsAt);

    // CONFIRMED depois do RECEIVED (ou reenvio): nada em dobro.
    awhPost(awhSecondCycle('PAYMENT_CONFIRMED', ['status' => 'CONFIRMED', 'paymentDate' => '2026-11-08']));

    expect(Payment::where('external_payment_id', 'pay_196527845301')->count())->toBe(1)
        ->and(FinancialEvent::where('payment_id', $payment->id)->count())->toBe(1)
        ->and(Invoice::where('billing_reason', 'unapplied_payment')->count())->toBe(1);

    // Estorno no gateway: o pagamento registrado é o que é estornado.
    awhPost(awhSecondCycle('PAYMENT_REFUNDED', ['status' => 'REFUNDED']));

    expect($payment->fresh()->status)->toBe(PaymentStatus::Refunded);
});

it('pagamento recebido para cortesia que carrega a recorrência antiga é registrado com alerta, sem mudar a cortesia', function () {
    $courtesy = Subscription::factory()->complimentary()->for($this->clinic)->for($this->plan)->create([
        'gateway'                 => 'asaas',
        'gateway_subscription_id' => 'sub_convertida_cortesia',
        'ends_at'                 => now()->addMonths(2),
    ]);

    awhPost(awhEvent('PAYMENT_RECEIVED', 'pay_000000000999', [
        'status'            => 'RECEIVED',
        'subscription'      => 'sub_convertida_cortesia',
        'externalReference' => null,
    ]));

    $payment = Payment::where('external_payment_id', 'pay_000000000999')->sole();

    expect($payment->subscription_id)->toBe($courtesy->id)
        ->and($payment->status)->toBe(PaymentStatus::Paid)
        ->and(FinancialEvent::where('payment_id', $payment->id)->sole()->metadata['alert'])->toBe('subscription_not_billed_by_gateway')
        ->and($courtesy->fresh()->billing_mode)->toBe(SubscriptionBillingMode::Complimentary)
        ->and($courtesy->fresh()->last_payment_at)->toBeNull();
});

it('pagamento sem assinatura identificável fica no log crítico com valor, gateway e id da cobrança', function () {
    awhPost(awhEvent('PAYMENT_RECEIVED', 'pay_000000000555', [
        'status'            => 'RECEIVED',
        'subscription'      => 'sub_desconhecida',
        'externalReference' => null,
    ]));

    $log = BillingLog::query()->where('level', 'critical')->sole();

    expect(Payment::where('external_payment_id', 'pay_000000000555')->exists())->toBeFalse()
        ->and($log->gateway_code)->toBe('asaas')
        ->and($log->context['external_payment_id'])->toBe('pay_000000000555')
        ->and($log->context['external_subscription_id'])->toBe('sub_desconhecida')
        ->and((float) $log->context['amount'])->toBe(299.9)
        ->and($log->context['currency'])->toBe('BRL');
});

describe('linha do código anterior aguardando conciliação, com a recorrência viva no Asaas', function () {
    /** Expirada pelo job antigo, marcada para conciliação, com o Asaas ainda cobrando. */
    function awhLegacyExpired(): Subscription
    {
        return Subscription::factory()->for(test()->clinic)->for(test()->plan)->create([
            'billing_mode'                 => SubscriptionBillingMode::Gateway,
            'status'                       => SubscriptionStatus::Expired,
            'billing_state'                => 'paid',
            'gateway'                      => 'asaas',
            'gateway_subscription_id'      => 'sub_legado_viva',
            'starts_at'                    => '2026-05-10 09:00:00',
            'ends_at'                      => '2026-06-10 09:00:00',
            'next_billing_at'              => null,
            'last_payment_at'              => '2026-09-10 11:00:00',
            'needs_billing_reconciliation' => true,
            'created_at'                   => now()->subMonths(5),
        ]);
    }

    it('nova contratação pelo gateway tira a marcação e para a recorrência dela', function () {
        $legacy = awhLegacyExpired();

        awhActivate();

        expect($legacy->fresh()->needs_billing_reconciliation)->toBeFalse();
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/v3/subscriptions/sub_legado_viva'));
    });

    it('1º pagamento da contratação nova tira a marcação e para a recorrência dela', function () {
        $legacy = awhLegacyExpired();

        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/v3/subscriptions/sub_legado_viva'));

        awhPayFirst();

        expect($this->subscription->fresh()->status)->toBe(SubscriptionStatus::Active)
            ->and($legacy->fresh()->needs_billing_reconciliation)->toBeFalse()
            ->and($legacy->fresh()->status)->toBe(SubscriptionStatus::Expired);
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/v3/subscriptions/sub_legado_viva'));
    });
});

it('link de pagamento do webhook fora de http(s) (ou longo demais) não vai para a fatura', function () {
    awhPost(awhEvent('PAYMENT_CREATED', 'pay_080225913252', [
        'invoiceUrl'  => 'javascript:alert(document.cookie)',
        'bankSlipUrl' => 'https://www.asaas.com/b/' . str_repeat('x', 2100),
    ]));

    $invoice = $this->subscription->currentInvoice->fresh();

    expect($invoice->external_invoice_id)->toBe('pay_080225913252')
        ->and($invoice->payment_url)->toBeNull();

    // Um link válido depois (PAYMENT_UPDATED) é aceito.
    awhPost(awhEvent('PAYMENT_UPDATED', 'pay_080225913252', ['invoiceUrl' => 'https://www.asaas.com/i/080225913252']));

    expect($invoice->fresh()->payment_url)->toBe('https://www.asaas.com/i/080225913252');
});

it('a contratação cria a recorrência com billingType UNDEFINED, o 1º vencimento e a nossa referência — sem cobrança avulsa', function () {
    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
        && $r->url() === 'https://api.asaas.com/v3/subscriptions'
        && $r['billingType'] === 'UNDEFINED'
        && $r['cycle'] === 'MONTHLY'
        && $r['nextDueDate'] === '2026-10-08'
        && $r['externalReference'] === $this->subscription->id
        && $r->hasHeader('access_token'));
    Http::assertNotSent(fn (Request $r) => str_starts_with($r->url(), 'https://api.asaas.com/v3/payments'));
});

it('PAYMENT_AUTHORIZED (cartão autorizado, aguardando captura) não ativa a contratação', function () {
    awhPost(awhEvent('PAYMENT_AUTHORIZED', 'pay_080225913252', ['billingType' => 'CREDIT_CARD']));

    $subscription = $this->subscription->fresh();

    expect($subscription->status)->toBe(SubscriptionStatus::PastDue)
        ->and($subscription->last_payment_at)->toBeNull()
        ->and($subscription->currentInvoice->status)->not->toBe(InvoiceStatus::Paid)
        ->and(WebhookEvent::latest('received_at')->first()->normalized_payload['outcome'])->toBe('ignored');
});

it('PAYMENT_PARTIALLY_REFUNDED não estorna a fatura nem mexe na assinatura', function () {
    awhPayFirst();
    awhPost(awhEvent('PAYMENT_PARTIALLY_REFUNDED', 'pay_080225913252', ['status' => 'RECEIVED', 'value' => 299.9]));

    $subscription = $this->subscription->fresh();

    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->currentInvoice->status)->toBe(InvoiceStatus::Paid)
        ->and(Payment::where('external_payment_id', 'pay_080225913252')->sole()->status)->toBe(PaymentStatus::Paid)
        ->and(FinancialEvent::where('event_type', BillingEventType::PaymentRefunded->value)->count())->toBe(0);
});

it('cobrança apagada e restaurada (PAYMENT_RESTORED) ainda pode ser paga e renova o ciclo', function () {
    awhPayFirst();
    awhPost(awhSecondCycle('PAYMENT_CREATED'));
    awhPost(awhSecondCycle('PAYMENT_DELETED', ['deleted' => true]));
    awhPost(awhSecondCycle('PAYMENT_RESTORED', ['deleted' => false]));

    $this->travelTo(CarbonImmutable::parse('2026-11-08 09:00:00'));
    awhPost(awhSecondCycle('PAYMENT_RECEIVED', ['status' => 'RECEIVED', 'paymentDate' => '2026-11-08']));

    expect($this->subscription->fresh()->ends_at->toDateTimeString())->toBe('2026-12-08 23:59:59')
        ->and(Invoice::where('external_invoice_id', 'pay_196527845301')->sole()->status)->toBe(InvoiceStatus::Paid)
        ->and(Payment::where('external_payment_id', 'pay_196527845301')->sole()->status)->toBe(PaymentStatus::Paid);
});

it('PAYMENT_RESTORED volta pagamento e fatura cancelados para pendente (antes do novo pagamento)', function () {
    awhPayFirst();
    awhPost(awhSecondCycle('PAYMENT_CREATED'));
    awhPost(awhSecondCycle('PAYMENT_DELETED', ['deleted' => true]));

    expect(Invoice::where('external_invoice_id', 'pay_196527845301')->sole()->status)->toBe(InvoiceStatus::Cancelled);

    awhPost(awhSecondCycle('PAYMENT_RESTORED', ['deleted' => false, 'status' => 'PENDING']));

    expect(Invoice::where('external_invoice_id', 'pay_196527845301')->sole()->status)->toBe(InvoiceStatus::Pending)
        ->and(Payment::where('external_payment_id', 'pay_196527845301')->sole()->status)->toBe(PaymentStatus::Pending)
        ->and($this->subscription->fresh()->status)->toBe(SubscriptionStatus::Active);
});

it('PAYMENT_UPDATED de cobrança cancelada (sem restauração) não reabre a fatura', function () {
    awhPayFirst();
    awhPost(awhSecondCycle('PAYMENT_CREATED'));
    awhPost(awhSecondCycle('PAYMENT_DELETED', ['deleted' => true]));
    awhPost(awhSecondCycle('PAYMENT_UPDATED', ['deleted' => true]));

    expect(Invoice::where('external_invoice_id', 'pay_196527845301')->sole()->status)->toBe(InvoiceStatus::Cancelled)
        ->and(Payment::where('external_payment_id', 'pay_196527845301')->sole()->status)->toBe(PaymentStatus::Cancelled);
});

it('evento de outra recorrência (duplicada/órfã, mesma referência) não vence nem cancela a fatura da assinatura vigente', function () {
    awhPayFirst();

    // A recorrência vigente emite a cobrança do 2º ciclo (fatura de 08/11).
    awhPost(awhSecondCycle('PAYMENT_CREATED'));
    $cycleInvoice = Invoice::where('external_invoice_id', 'pay_196527845301')->sole();

    // A órfã (outro sub_…, mesma externalReference) tem cobrança no mesmo
    // período: vencida e depois apagada.
    $orphan = fn (string $event, array $payment = []) => awhEvent($event, 'pay_orfa_000000001', [
        'subscription'    => 'sub_orfa_duplicada',
        'dueDate'         => '2026-11-08',
        'originalDueDate' => '2026-11-08',
        ...$payment,
    ]);

    $this->travelTo(CarbonImmutable::parse('2026-11-09 08:00:00'));
    awhPost($orphan('PAYMENT_OVERDUE', ['status' => 'OVERDUE']));
    awhPost($orphan('PAYMENT_DELETED', ['deleted' => true]));

    $subscription = $this->subscription->fresh();

    expect($cycleInvoice->fresh()->status)->toBe(InvoiceStatus::Pending)
        ->and($cycleInvoice->fresh()->external_invoice_id)->toBe('pay_196527845301')
        ->and($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->past_due_at)->toBeNull()
        ->and(Invoice::where('external_invoice_id', 'pay_orfa_000000001')->exists())->toBeFalse()
        ->and(WebhookEvent::query()->get()->pluck('normalized_payload.outcome')->filter()->values()->all())
        ->toContain('ignored_unknown_charge', 'ignored');
});
