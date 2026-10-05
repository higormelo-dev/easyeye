<?php

use App\Enums\Billing\{BillingEventType, InvoiceStatus, PaymentAttemptStatus, PaymentStatus};
use App\Enums\{BillingCycle, SubscriptionBillingMode, SubscriptionStatus};
use App\Jobs\Billing\{ProcessBillingWebhookJob, RenewSubscriptionJob, RetryFailedPaymentJob};
use App\Models\Billing\{BillingLog, BillingRetrySchedule, FinancialEvent, Invoice, Payment, PaymentAttempt};
use App\Models\{Entity, Plan, Subscription};
use App\Services\Billing\{SubscriptionCycleService, WebhookIngestionService};
use App\Services\SubscriptionService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\{Carbon, Str};
use Illuminate\Support\Facades\{Http, Queue};

/**
 * Renovação local (gateways sem recorrência própria, ex.: InfinitePay e
 * Pagar.me; o Mercado Pago de ponta a ponta fica em MercadoPagoRenewalTest):
 * o agendador emite a cobrança do próximo ciclo 5 dias antes do vencimento,
 * no valor e ciclo contratados, uma por período. Gateways com recorrência
 * nativa (Asaas) não passam pelo job.
 *
 * InfinitePay (Checkout Integrado): a cobrança é um link (POST /links →
 * {url}), sem recusa nem pagamento na resposta, e o webhook só sai na
 * aprovação (confirmado no payment_check). Recusa e "pago na hora" ficam no
 * Pagar.me, que devolve o status da cobrança no pedido e notifica a falha.
 */
beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-05 01:00:00'));
    Http::preventStrayRequests();

    config([
        'billing.gateways.infinitepay.handle'     => 'easyeye',
        'billing.gateways.pagarme.secret'         => 'sk_test_easyeye',
        'billing.gateways.pagarme.webhook_secret' => 'easyeye:s3nh4-forte',
    ]);

    // Preço de referência do plano mudou; a clínica contratou o anual antes.
    $this->plan = Plan::factory()->create(['name' => 'Pro', 'billing_cycle' => 'monthly', 'price' => 349.90, 'active' => true]);

    $this->subscription = renewSubscription([
        'billing_cycle'   => BillingCycle::Yearly,
        'amount'          => 2878.99,
        'starts_at'       => '2025-10-08 00:00:00',
        'ends_at'         => '2026-10-08 23:59:59',
        'next_billing_at' => '2026-10-08 23:59:59',
        'last_payment_at' => '2025-10-07 15:00:00',
    ]);
});

afterEach(fn () => Carbon::setTestNow());

function renewClinic(): Entity
{
    $entity                = Entity::factory()->make(['is_client' => true, 'active' => true]);
    $entity->skipAutoTrial = true;
    $entity->save();

    return $entity;
}

/** Cobrança automática no InfinitePay (sem recorrência no gateway), já paga antes. */
function renewSubscription(array $attributes = []): Subscription
{
    return Subscription::factory()->for(renewClinic())->for(test()->plan)->create([
        'billing_mode'            => SubscriptionBillingMode::Gateway,
        'status'                  => SubscriptionStatus::Active,
        'billing_state'           => 'paid',
        'gateway'                 => 'infinitepay',
        'pinned_gateway'          => 'infinitepay',
        'gateway_subscription_id' => null,
        'billing_cycle'           => BillingCycle::Monthly,
        'amount'                  => 349.90,
        'starts_at'               => now()->subMonth(),
        'ends_at'                 => now()->addDays(3)->endOfDay(),
        'next_billing_at'         => now()->addDays(3)->endOfDay(),
        'last_payment_at'         => now()->subMonth(),
        ...$attributes,
    ]);
}

const RENEW_IP_LINKS = 'https://api.checkout.infinitepay.io/links';
const RENEW_IP_CHECK = 'https://api.checkout.infinitepay.io/payment_check';

/**
 * InfinitePay: cada POST /links responde o próximo da fila (string = link
 * criado; int = status de erro). payment_check confirma o pagamento.
 */
function renewFakeInfinitePay(string|int ...$responses): void
{
    $queue = $responses;

    Http::fake(function (Request $request) use (&$queue) {
        if ($request->url() === RENEW_IP_CHECK) {
            return Http::response(['success' => true, 'paid' => true, 'amount' => 287899, 'paid_amount' => 287899, 'installments' => 1, 'capture_method' => 'pix']);
        }

        if ($request->url() !== RENEW_IP_LINKS) {
            return Http::response(['error' => 'not_faked'], 404);
        }

        $next = array_shift($queue) ?? 500;

        return is_string($next) ? Http::response(['url' => $next]) : Http::response(['message' => 'error'], $next);
    });
}

/** Webhook do Checkout Integrado (só na aprovação). */
function renewInfinitePayWebhook(string $orderNsu, int $cents): void
{
    $body = json_encode([
        'invoice_slug'    => 'renov2026',
        'amount'          => $cents,
        'paid_amount'     => $cents,
        'installments'    => 1,
        'capture_method'  => 'pix',
        'transaction_nsu' => '5f1d8c2e-3a4b-4c5d-8e9f-0a1b2c3d4e5f',
        'order_nsu'       => $orderNsu,
        'receipt_url'     => 'https://comprovante.infinitepay.io/renov2026',
    ]);
    $event = app(WebhookIngestionService::class)->ingest('infinitepay', [], $body);
    ProcessBillingWebhookJob::dispatchSync((string) $event->id);
}

/** Pedido do Pagar.me (POST /orders) com a cobrança no status dado. */
function renewPagarmeOrder(string $chargeId, string $status, int $cents = 287899): array
{
    return [
        'id'      => 'or_' . substr($chargeId, 3),
        'amount'  => $cents,
        'status'  => $status === 'paid' ? 'paid' : 'pending',
        'charges' => [[
            'id'               => $chargeId,
            'amount'           => $cents,
            'status'           => $status,
            'currency'         => 'BRL',
            'payment_method'   => 'pix',
            'last_transaction' => [
                'transaction_type' => 'pix',
                'status'           => $status === 'paid' ? 'paid' : 'waiting_payment',
                'qr_code_url'      => 'https://api.pagar.me/core/v5/transactions/tran_1/qrcode?payment_method=pix',
            ],
        ]],
    ];
}

/** Assinatura de renovação local no Pagar.me (cliente já cadastrado lá). */
function renewPagarmeSubscription(array $attributes = []): Subscription
{
    return renewSubscription([
        'gateway'             => 'pagarme',
        'pinned_gateway'      => 'pagarme',
        'gateway_customer_id' => 'cus_oy23JRQCM1cvzlmD',
        ...$attributes,
    ]);
}

it('o agendador seleciona só renovação local devida, já paga antes e sem a cobrança do período emitida', function () {
    Queue::fake();

    $retry = renewSubscription(['status' => SubscriptionStatus::PastDue, 'billing_state' => 'error', 'next_billing_at' => now()->subDay(), 'ends_at' => now()->subDay()]);

    // Fora: recorrência nativa, nunca pagou, fora da antecedência, cortesia e período já emitido.
    renewSubscription(['gateway' => 'asaas', 'gateway_subscription_id' => 'sub_nativa_asaas']);
    renewSubscription(['status' => SubscriptionStatus::PastDue, 'billing_state' => 'pending_activation', 'last_payment_at' => null]);
    renewSubscription(['next_billing_at' => now()->addDays(15), 'ends_at' => now()->addDays(15)]);
    renewSubscription(['billing_mode' => SubscriptionBillingMode::Complimentary, 'gateway' => null]);
    $emitted = renewSubscription(['next_billing_at' => '2026-10-09 23:59:59']);
    Invoice::query()->create([
        'entity_id'       => $emitted->entity_id,
        'subscription_id' => $emitted->id,
        'plan_id'         => $emitted->plan_id,
        'reference'       => 'INV-EMITIDA-1',
        'period_start'    => '2026-10-09',
        'period_end'      => '2026-11-09',
        'amount'          => 349.90,
        'status'          => InvoiceStatus::Pending->value,
    ]);

    expect(app(SubscriptionCycleService::class)->dispatchDueRenewals())->toBe(2);

    $dispatched = collect(Queue::pushed(RenewSubscriptionJob::class))->map(fn (RenewSubscriptionJob $job) => $job->subscriptionId)->sort()->values()->all();

    expect($dispatched)->toBe(collect([$this->subscription->id, $retry->id])->sort()->values()->all());
});

it('cobra o valor e o ciclo contratados pelo período [next_billing_at, +ciclo) e é idempotente', function () {
    renewFakeInfinitePay('https://checkout.infinitepay.com.br/easyeye?lenc=RENOV2026');

    RenewSubscriptionJob::dispatchSync((string) $this->subscription->id);
    RenewSubscriptionJob::dispatchSync((string) $this->subscription->id); // reexecução: nada novo

    $invoice      = Invoice::sole();
    $subscription = $this->subscription->fresh();
    $orderNsu     = (string) $invoice->external_invoice_id;

    Http::assertSentCount(1);
    // Link no valor contratado (centavos), com o nosso order_nsu (fatura.valor.nonce.assinatura).
    Http::assertSent(fn (Request $r) => $r->url() === RENEW_IP_LINKS
        && $r['handle'] === 'easyeye'
        && $r['items'][0]['price'] === 287899
        && $r['order_nsu'] === $orderNsu
        && str_starts_with($r['order_nsu'], $invoice->id . '.287899.'));

    expect($invoice->billing_reason)->toBe('subscription_cycle')
        ->and($invoice->period_start->toDateString())->toBe('2026-10-08')
        ->and($invoice->period_end->toDateString())->toBe('2027-10-08')
        ->and($invoice->due_at->toDateTimeString())->toBe('2026-10-08 23:59:59')
        ->and((float) $invoice->amount)->toBe(2878.99)
        ->and($invoice->status)->toBe(InvoiceStatus::Pending)
        ->and($invoice->payment_url)->toBe('https://checkout.infinitepay.com.br/easyeye?lenc=RENOV2026')
        ->and(Payment::sole()->status)->toBe(PaymentStatus::Pending)
        ->and(Payment::sole()->external_payment_id)->toBe($orderNsu)
        ->and(PaymentAttempt::sole()->status)->toBe(PaymentAttemptStatus::Succeeded)
        // Só o pagamento estende o período.
        ->and($subscription->ends_at->toDateTimeString())->toBe('2026-10-08 23:59:59')
        ->and($subscription->next_billing_at->toDateTimeString())->toBe('2026-10-08 23:59:59')
        ->and($subscription->current_invoice_id)->toBe($invoice->id)
        ->and(app(SubscriptionCycleService::class)->dispatchDueRenewals())->toBe(0);

    // O cliente paga: o webhook (confirmado no payment_check) estende pelo ciclo contratado (anual).
    renewInfinitePayWebhook($orderNsu, 287899);

    $subscription->refresh();

    Http::assertSent(fn (Request $r) => $r->url() === RENEW_IP_CHECK && $r['order_nsu'] === $orderNsu);

    expect($subscription->ends_at->toDateTimeString())->toBe('2027-10-08 23:59:59')
        ->and($subscription->next_billing_at->toDateTimeString())->toBe('2027-10-08 23:59:59')
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
});

it('cobrança da renovação recusada antes do vencimento não bloqueia; vencida sem pagamento entra em atraso (Pagar.me)', function () {
    $subscription = renewPagarmeSubscription([
        'billing_cycle'   => BillingCycle::Yearly,
        'amount'          => 2878.99,
        'ends_at'         => '2026-10-08 23:59:59',
        'next_billing_at' => '2026-10-08 23:59:59',
        'last_payment_at' => '2025-10-07 15:00:00',
    ]);
    $this->subscription->update(['next_billing_at' => now()->addYear(), 'ends_at' => now()->addYear()]);

    Http::fake(['https://api.pagar.me/core/v5/orders' => Http::response(renewPagarmeOrder('ch_RECUSADA2026', 'pending'))]);
    RenewSubscriptionJob::dispatchSync((string) $subscription->id);

    // charge.payment_failed (autenticação básica do webhook).
    $body  = json_encode(['id' => 'hook_recusada', 'type' => 'charge.payment_failed', 'data' => ['id' => 'ch_RECUSADA2026', 'status' => 'failed', 'amount' => 287899, 'currency' => 'BRL']]);
    $event = app(WebhookIngestionService::class)->ingest('pagarme', ['authorization' => 'Basic ' . base64_encode('easyeye:s3nh4-forte')], $body);
    ProcessBillingWebhookJob::dispatchSync((string) $event->id);

    $subscription->refresh();

    expect($event->fresh()->normalized_payload['outcome'])->toBe('payment_failed_before_due')
        ->and($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->billing_state)->toBe('payment_failed')
        ->and($subscription->past_due_at)->toBeNull()
        ->and($subscription->hasAccess())->toBeTrue()
        ->and(Invoice::where('subscription_id', $subscription->id)->sole()->status)->toBe(InvoiceStatus::Failed);

    // Venceu (08/10) sem pagamento: a expiração diária põe em atraso desde o fim do período.
    $this->travelTo(CarbonImmutable::parse('2026-10-09 00:10:00'));
    app(SubscriptionService::class)->markLapsedAsPastDue();

    $subscription->refresh();

    expect($subscription->status)->toBe(SubscriptionStatus::PastDue)
        ->and($subscription->past_due_at->toDateTimeString())->toBe('2026-10-08 23:59:59');
});

it('pago na hora (cobrança já paga na resposta do Pagar.me) estende como o webhook', function () {
    $subscription = renewPagarmeSubscription([
        'billing_cycle'   => BillingCycle::Yearly,
        'amount'          => 2878.99,
        'ends_at'         => '2026-10-08 23:59:59',
        'next_billing_at' => '2026-10-08 23:59:59',
        'last_payment_at' => '2025-10-07 15:00:00',
    ]);
    $this->subscription->update(['next_billing_at' => now()->addYear(), 'ends_at' => now()->addYear()]);

    Http::fake(['https://api.pagar.me/core/v5/orders' => Http::response(renewPagarmeOrder('ch_PAGO2026', 'paid'))]);

    RenewSubscriptionJob::dispatchSync((string) $subscription->id);

    $subscription->refresh();

    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->ends_at->toDateTimeString())->toBe('2027-10-08 23:59:59')
        ->and($subscription->next_billing_at->toDateTimeString())->toBe('2027-10-08 23:59:59')
        ->and($subscription->renewed_at)->not->toBeNull()
        ->and(Invoice::where('subscription_id', $subscription->id)->sole()->status)->toBe(InvoiceStatus::Paid)
        ->and(Payment::sole()->status)->toBe(PaymentStatus::Paid)
        ->and(FinancialEvent::where('event_type', BillingEventType::InvoicePaid->value)->count())->toBe(1);
});

it('falha sem resposta definitiva (5xx): o agendador tenta de novo com a MESMA chave — o gateway devolve o mesmo pedido', function () {
    renewFakeInfinitePay(500, 'https://checkout.infinitepay.com.br/easyeye?lenc=SEGUNDA');

    RenewSubscriptionJob::dispatchSync((string) $this->subscription->id);

    $subscription = $this->subscription->fresh();

    expect(Invoice::sole()->status)->toBe(InvoiceStatus::Failed)
        ->and($subscription->billing_state)->toBe('error')
        ->and($subscription->last_billing_error)->not->toBeEmpty()
        ->and($subscription->status)->toBe(SubscriptionStatus::Active);

    // Fatura em falha não conta como emitida: o agendador seleciona de novo
    // (fila síncrona nos testes — o job já roda aqui).
    expect(app(SubscriptionCycleService::class)->dispatchDueRenewals())->toBe(1);

    $sent = collect(Http::recorded())->map(fn ($pair) => $pair[0]);
    $keys = $sent->map(fn (Request $r) => $r->header('X-Idempotency-Key')[0] ?? null)->all();

    expect($keys)->toBe(["renew:{$this->subscription->id}:2026-10-08:1", "renew:{$this->subscription->id}:2026-10-08:1"])
        // Mesmo order_nsu nas duas tentativas: um pedido só na InfinitePay.
        ->and($sent[0]['order_nsu'])->toBe($sent[1]['order_nsu'])
        ->and(PaymentAttempt::query()->orderBy('attempt_number')->pluck('idempotency_key')->all())
        ->toBe(["renew:{$this->subscription->id}:2026-10-08:1", "renew:{$this->subscription->id}:2026-10-08:2"])
        ->and(Invoice::sole()->status)->toBe(InvoiceStatus::Pending)
        ->and($this->subscription->fresh()->billing_state)->toBe('pending')
        ->and($this->subscription->fresh()->last_billing_error)->toBeNull();
});

it('falha definitiva (4xx): a nova tentativa usa chave nova', function () {
    renewFakeInfinitePay(422, 'https://checkout.infinitepay.com.br/easyeye?lenc=TERCEIRA');

    RenewSubscriptionJob::dispatchSync((string) $this->subscription->id);
    expect(app(SubscriptionCycleService::class)->dispatchDueRenewals())->toBe(1);

    $sent = collect(Http::recorded())->map(fn ($pair) => $pair[0]);

    expect($sent->map(fn (Request $r) => $r->header('X-Idempotency-Key')[0] ?? null)->all())
        ->toBe(["renew:{$this->subscription->id}:2026-10-08:1", "renew:{$this->subscription->id}:2026-10-08:2"])
        ->and($sent[0]['order_nsu'])->not->toBe($sent[1]['order_nsu'])
        ->and(Invoice::sole()->status)->toBe(InvoiceStatus::Pending);
});

it('nova tentativa depois do vencimento: o período segue o mesmo e a cobrança vence hoje', function () {
    config(['billing.gateways.pagarme.payment_method' => 'boleto']);
    Http::fake(['https://api.pagar.me/core/v5/orders' => Http::response(renewPagarmeOrder('ch_ATRASADA', 'pending', 34990))]);

    $late = renewPagarmeSubscription([
        'status'          => SubscriptionStatus::PastDue,
        'billing_state'   => 'past_due',
        'ends_at'         => '2026-10-03 23:59:59',
        'next_billing_at' => '2026-10-03 23:59:59',
        'past_due_at'     => '2026-10-03 23:59:59',
    ]);

    RenewSubscriptionJob::dispatchSync((string) $late->id);

    $invoice = Invoice::where('subscription_id', $late->id)->sole();

    // Boleto vencendo hoje (05/10), no valor do ciclo.
    Http::assertSent(fn (Request $r) => $r['payments'][0]['boleto']['due_at'] === '2026-10-05T23:59:59'
        && $r['items'][0]['amount'] === 34990
        && $r['customer_id'] === 'cus_oy23JRQCM1cvzlmD');

    expect($invoice->period_start->toDateString())->toBe('2026-10-03')
        ->and($invoice->period_end->toDateString())->toBe('2026-11-03')
        ->and($invoice->due_at->toDateTimeString())->toBe('2026-10-03 23:59:59');
});

it('assinatura paga antes do registro de next_billing_at renova a partir do fim do período', function () {
    renewFakeInfinitePay('https://checkout.infinitepay.com.br/easyeye?lenc=LEGADO');

    // A coluna era descartada em silêncio: linhas antigas têm só ends_at.
    $legacy = renewSubscription(['next_billing_at' => null, 'ends_at' => '2026-10-09 23:59:59']);
    $this->subscription->update(['next_billing_at' => now()->addMonth(), 'ends_at' => now()->addMonth()]);

    expect(app(SubscriptionCycleService::class)->dispatchDueRenewals())->toBe(1);

    $invoice = Invoice::where('subscription_id', $legacy->id)->sole();

    expect($invoice->period_start->toDateString())->toBe('2026-10-09')
        ->and($invoice->period_end->toDateString())->toBe('2026-11-09')
        ->and($invoice->status)->toBe(InvoiceStatus::Pending);
});

it('assinatura com recorrência nativa no gateway (Asaas) não passa pelo job', function () {
    $native = renewSubscription(['gateway' => 'asaas', 'gateway_subscription_id' => 'sub_nativa_asaas']);

    RenewSubscriptionJob::dispatchSync((string) $native->id);

    Http::assertNothingSent();
    expect(Invoice::count())->toBe(0);
});

it('assinatura encerrada ou substituída durante a chamada ao gateway: a cobrança fica registrada, mas não reabre a fatura nem ressuscita a assinatura', function (string $outcome) {
    $subscription = renewPagarmeSubscription([
        'billing_cycle'   => BillingCycle::Yearly,
        'amount'          => 2878.99,
        'ends_at'         => '2026-10-08 23:59:59',
        'next_billing_at' => '2026-10-08 23:59:59',
        'last_payment_at' => '2025-10-07 15:00:00',
    ]);

    // Enquanto o Pagar.me processa (cobrança paga na resposta), a régua
    // encerra a assinatura ou outra contratação a substitui.
    Http::fake(function (Request $request) use ($subscription, $outcome) {
        $subscription->fresh()->update($outcome === 'expired'
            ? ['status' => SubscriptionStatus::Expired, 'billing_state' => 'expired', 'cancelled_at' => now()]
            : ['status' => SubscriptionStatus::Cancelled, 'billing_state' => 'cancelled', 'cancelled_at' => now(), 'cancelled_reason' => 'replaced']);
        Invoice::query()->where('subscription_id', $subscription->id)->update(['status' => InvoiceStatus::Cancelled->value]);

        return Http::response(renewPagarmeOrder('ch_CARTAO_TARDE', 'paid'));
    });

    RenewSubscriptionJob::dispatchSync((string) $subscription->id);

    $subscription->refresh();
    $payment = Payment::sole();

    expect($subscription->status)->toBe($outcome === 'expired' ? SubscriptionStatus::Expired : SubscriptionStatus::Cancelled)
        ->and($subscription->ends_at->toDateTimeString())->toBe('2026-10-08 23:59:59')
        ->and($subscription->last_payment_at->toDateTimeString())->toBe('2025-10-07 15:00:00')
        ->and(Invoice::where('subscription_id', $subscription->id)->sole()->status)->toBe(InvoiceStatus::Cancelled)
        // O dinheiro entrou: fica registrado para o time estornar.
        ->and($payment->status)->toBe(PaymentStatus::Paid)
        ->and($payment->external_payment_id)->toBe('ch_CARTAO_TARDE')
        ->and(BillingLog::query()->where('level', 'critical')->where('subscription_id', $subscription->id)->exists())->toBeTrue()
        ->and(FinancialEvent::query()->where('event_type', BillingEventType::InvoicePaid->value)->exists())->toBeFalse();
})->with(['expirada pela régua' => 'expired', 'substituída' => 'replaced']);

it('assinatura sem cliente no gateway (linha antiga): cadastra o cliente, grava o id e cobra com ele — nunca com o id local da empresa', function () {
    $subscription = renewPagarmeSubscription([
        'gateway_customer_id' => null,
        'billing_cycle'       => BillingCycle::Yearly,
        'amount'              => 2878.99,
        'ends_at'             => '2026-10-08 23:59:59',
        'next_billing_at'     => '2026-10-08 23:59:59',
    ]);
    $this->subscription->update(['next_billing_at' => now()->addYear(), 'ends_at' => now()->addYear()]);
    $subscription->entity->update(['zipcode' => '01310-100', 'address' => 'Av. Paulista', 'number' => '1000', 'district' => 'Bela Vista', 'city' => 'São Paulo', 'state' => 'SP', 'complement' => null]);

    Http::fake([
        'https://api.pagar.me/core/v5/customers' => Http::response(['id' => 'cus_NOVO2026', 'name' => 'Clínica']),
        'https://api.pagar.me/core/v5/orders'    => Http::response(renewPagarmeOrder('ch_COMCLIENTE', 'pending')),
    ]);

    RenewSubscriptionJob::dispatchSync((string) $subscription->id);

    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.pagar.me/core/v5/customers'
        && $r['code'] === $subscription->entity_id
        && $r['address'] === ['line_1' => '1000, AV. PAULISTA, BELA VISTA', 'zip_code' => '01310100', 'city' => 'SÃO PAULO', 'state' => 'SP', 'country' => 'BR']);
    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.pagar.me/core/v5/orders' && $r['customer_id'] === 'cus_NOVO2026');

    expect($subscription->fresh()->gateway_customer_id)->toBe('cus_NOVO2026')
        ->and(Invoice::where('subscription_id', $subscription->id)->sole()->status)->toBe(InvoiceStatus::Pending);
});

it('billing:retry saiu do agendador e o job não cobra assinatura encerrada, substituída ou com o período já pago', function () {
    $scheduled = collect(app(Schedule::class)->events())->map(fn ($event) => $event->description ?? (string) $event->command);

    expect($scheduled)->not->toContain('billing:retry')
        ->and($scheduled)->toContain('subscriptions:renew');

    Http::fake();

    $failedInvoice = fn (Subscription $subscription, string $periodStart) => Invoice::query()->create([
        'entity_id'       => $subscription->entity_id,
        'subscription_id' => $subscription->id,
        'plan_id'         => $subscription->plan_id,
        'reference'       => 'INV-RETRY-' . Str::random(6),
        'period_start'    => $periodStart,
        'period_end'      => CarbonImmutable::parse($periodStart)->addYear()->toDateString(),
        'amount'          => 2878.99,
        'currency'        => 'BRL',
        'status'          => InvoiceStatus::Failed->value,
    ]);

    $schedule = fn (Subscription $subscription, Invoice $invoice) => BillingRetrySchedule::query()->create([
        'entity_id'       => $subscription->entity_id,
        'subscription_id' => $subscription->id,
        'invoice_id'      => $invoice->id,
        'gateway_code'    => 'infinitepay',
        'attempt_number'  => 1,
        'status'          => 'pending',
        'scheduled_for'   => now()->subHour(),
        'correlation_id'  => (string) Str::uuid(),
    ]);

    // Encerrada pela régua; e a ativa com o período da fatura já pago.
    $expired = renewSubscription(['status' => SubscriptionStatus::Expired]);
    $paid    = renewSubscription(['ends_at' => '2027-10-08 23:59:59', 'next_billing_at' => '2027-10-08 23:59:59']);

    $retries = [
        $schedule($expired, $failedInvoice($expired, '2026-10-08')),
        $schedule($paid, $failedInvoice($paid, '2026-10-08')),
    ];

    foreach ($retries as $retry) {
        RetryFailedPaymentJob::dispatchSync((string) $retry->id);

        expect($retry->fresh()->status)->toBe('skipped')
            ->and($retry->fresh()->result_message)->toBe(__('manager_subscriptions.billing_errors.retry_not_applicable'));
    }

    Http::assertNothingSent();
});

it('billing:retry (job à mão): cliente cadastrado no gateway quando falta, e o link da nova cobrança vai para a fatura', function () {
    $subscription = renewPagarmeSubscription(['gateway_customer_id' => null]);
    $this->subscription->update(['next_billing_at' => now()->addYear(), 'ends_at' => now()->addYear()]);

    $invoice = Invoice::query()->create([
        'entity_id'       => $subscription->entity_id,
        'subscription_id' => $subscription->id,
        'plan_id'         => $subscription->plan_id,
        'gateway_code'    => 'pagarme',
        'reference'       => 'INV-RETRY-LINK',
        'period_start'    => '2026-10-08',
        'period_end'      => '2026-11-08',
        'amount'          => 349.90,
        'currency'        => 'BRL',
        'status'          => InvoiceStatus::Failed->value,
        'payment_url'     => 'https://api.pagar.me/core/v5/transactions/tran_antiga/qrcode?payment_method=pix',
    ]);

    $retry = BillingRetrySchedule::query()->create([
        'entity_id'       => $subscription->entity_id,
        'subscription_id' => $subscription->id,
        'invoice_id'      => $invoice->id,
        'gateway_code'    => 'pagarme',
        'attempt_number'  => 1,
        'status'          => 'pending',
        'scheduled_for'   => now()->subHour(),
        'correlation_id'  => (string) Str::uuid(),
    ]);

    Http::fake([
        'https://api.pagar.me/core/v5/customers' => Http::response(['id' => 'cus_RETRY2026']),
        'https://api.pagar.me/core/v5/orders'    => Http::response(renewPagarmeOrder('ch_RETRY2026', 'pending', 34990)),
    ]);

    RetryFailedPaymentJob::dispatchSync((string) $retry->id);

    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.pagar.me/core/v5/orders' && $r['customer_id'] === 'cus_RETRY2026');

    expect($subscription->fresh()->gateway_customer_id)->toBe('cus_RETRY2026')
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Pending)
        ->and($invoice->fresh()->external_invoice_id)->toBe('ch_RETRY2026')
        ->and($invoice->fresh()->payment_url)->toBe('https://api.pagar.me/core/v5/transactions/tran_1/qrcode?payment_method=pix');
});
