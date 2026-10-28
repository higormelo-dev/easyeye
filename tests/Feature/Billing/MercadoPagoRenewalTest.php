<?php

use App\Enums\Billing\{InvoiceStatus, PaymentStatus};
use App\Enums\{BillingCycle, SubscriptionStatus};
use App\Jobs\Billing\ProcessBillingWebhookJob;
use App\Models\Billing\{Invoice, Payment, WebhookEvent};
use App\Models\{Entity, Plan, PlanPrice, Subscription};
use App\Services\Billing\{BillingSubscriptionOrchestrator, SubscriptionCycleService, WebhookIngestionService};
use App\Services\SubscriptionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\{Carbon, Str};
use Illuminate\Support\Facades\Http;

/**
 * Mercado Pago sem recorrência própria: a ativação emite só o Pix da 1ª
 * cobrança (nenhum preapproval) e cada ciclo seguinte sai da renovação local,
 * no valor e no ciclo contratados.
 *
 * O Mercado Pago aqui é um sandbox em memória: cada Pix criado fica guardado
 * com o que enviamos e a consulta (GET /v1/payments/{id}) devolve esse
 * pagamento com o status atual.
 */
beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-05 10:00:00'));
    Http::preventStrayRequests();
    config(['billing.gateways.mercadopago.webhook_secret' => 'whsec_mercadopago_teste']);

    $this->clinic                = Entity::factory()->make(['is_client' => true, 'active' => true]);
    $this->clinic->skipAutoTrial = true;
    $this->clinic->save();

    // Preço de referência mensal; a clínica contrata o trimestral.
    $this->plan = Plan::factory()->create(['name' => 'Pro', 'billing_cycle' => 'monthly', 'price' => 299.90, 'active' => true]);
    PlanPrice::create(['plan_id' => $this->plan->id, 'billing_cycle' => 'monthly', 'price' => 299.90]);
    $this->quarterly = PlanPrice::create(['plan_id' => $this->plan->id, 'billing_cycle' => 'quarterly', 'price' => 809.73]);

    $this->trial = Subscription::factory()->trial(10)->for($this->clinic)->for($this->plan)->create();

    $this->mp = mpRenewFakeMercadoPago();
});

afterEach(fn () => Carbon::setTestNow());

function mpRenewFakeMercadoPago(): stdClass
{
    $mp           = new stdClass();
    $mp->payments = [];
    $mp->nextId   = 1316372291;

    Http::fake(function (Request $request) use ($mp) {
        $url = $request->url();

        if (str_starts_with($url, 'https://api.mercadopago.com/v1/customers')) {
            return Http::response(['id' => '1234567-cbXRL1mI2X', 'email' => 'financeiro@clinicaolhar.com.br'], 201);
        }

        if ($url === 'https://api.mercadopago.com/v1/payments' && $request->method() === 'POST') {
            $id = $mp->nextId++;

            $mp->payments[$id] = [
                'id'                   => $id,
                'status'               => 'pending',
                'status_detail'        => 'pending_waiting_transfer',
                'transaction_amount'   => $request['transaction_amount'],
                'currency_id'          => 'BRL',
                'payment_method_id'    => $request['payment_method_id'],
                'external_reference'   => $request['external_reference'],
                'metadata'             => $request['metadata'],
                'date_of_expiration'   => $request['date_of_expiration'],
                'point_of_interaction' => ['transaction_data' => ['ticket_url' => "https://www.mercadopago.com.br/payments/{$id}/ticket"]],
            ];

            return Http::response($mp->payments[$id], 201);
        }

        if (preg_match('#^https://api\.mercadopago\.com/v1/payments/(\d+)$#', $url, $match) && isset($mp->payments[(int) $match[1]])) {
            return Http::response($mp->payments[(int) $match[1]]);
        }

        // Busca de cliente e o que mais vier: nada encontrado.
        return Http::response(['message' => 'not_found', 'results' => []], 404);
    });

    return $mp;
}

/** O cliente paga o Pix: o Mercado Pago aprova e avisa (payment.updated, só com o id). */
function mpRenewPay(stdClass $mp, int $paymentId, string $notificationId): WebhookEvent
{
    $mp->payments[$paymentId]['status']        = 'approved';
    $mp->payments[$paymentId]['status_detail'] = 'accredited';
    $mp->payments[$paymentId]['date_approved'] = now()->toIso8601String();

    return mpRenewNotify($paymentId, $notificationId);
}

/** O Pix venceu sem pagamento: o Mercado Pago o cancela (expirado) e avisa. */
function mpRenewExpire(stdClass $mp, int $paymentId, string $notificationId): WebhookEvent
{
    $mp->payments[$paymentId]['status']        = 'cancelled';
    $mp->payments[$paymentId]['status_detail'] = 'expired';

    return mpRenewNotify($paymentId, $notificationId);
}

/** Notificação assinada (payment.updated, só com o id), recebida e processada. */
function mpRenewNotify(int $paymentId, string $notificationId): WebhookEvent
{
    $notification = [
        'action'       => 'payment.updated',
        'api_version'  => 'v1',
        'data'         => ['id' => (string) $paymentId],
        'date_created' => now()->toIso8601String(),
        'id'           => $notificationId,
        'live_mode'    => true,
        'type'         => 'payment',
        'user_id'      => '123456789',
    ];

    $requestId = (string) Str::uuid();
    $ts        = (string) now()->timestamp;
    // Manifesto da doc: "id:<data.id>;request-id:<x-request-id>;ts:<ts>;" (com ";" no fim).
    $hash = hash_hmac('sha256', "id:{$paymentId};request-id:{$requestId};ts:{$ts};", 'whsec_mercadopago_teste');
    $body = json_encode($notification, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $event = app(WebhookIngestionService::class)
        ->ingest('mercadopago', ['x-signature' => "ts={$ts},v1={$hash}", 'x-request-id' => $requestId], $body);

    ProcessBillingWebhookJob::dispatchSync((string) $event->id);

    return $event->fresh();
}

it('ativação não cria preapproval: só o Pix da 1ª cobrança, sem recorrência no gateway', function () {
    $subscription = app(BillingSubscriptionOrchestrator::class)
        ->activateWithGateway($this->clinic, $this->plan, BillingCycle::Quarterly, 'mercadopago');

    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/preapproval'));
    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
        && $r->url() === 'https://api.mercadopago.com/v1/payments'
        && $r['payment_method_id'] === 'pix'
        && (float) $r['transaction_amount'] === 809.73
        && $r['external_reference'] === $subscription->current_invoice_id
        && str_starts_with((string) $r['date_of_expiration'], '2026-10-08T23:59:59'));

    expect($subscription->status)->toBe(SubscriptionStatus::PastDue)
        ->and($subscription->billing_state)->toBe('pending_activation')
        ->and($subscription->gateway)->toBe('mercadopago')
        ->and($subscription->gateway_subscription_id)->toBeNull()
        ->and($subscription->hasGatewayRecurrence())->toBeFalse()
        ->and($subscription->hasAccess())->toBeTrue()
        ->and($this->trial->fresh()->status)->toBe(SubscriptionStatus::Cancelled);
});

it('contratação paga renova localmente: o agendador despacha e o job cobra o Pix no valor e no ciclo contratados, vencendo em next_billing_at', function () {
    $subscription = app(BillingSubscriptionOrchestrator::class)
        ->activateWithGateway($this->clinic, $this->plan, BillingCycle::Quarterly, 'mercadopago');
    $firstInvoice = $subscription->currentInvoice;

    // O 1º Pix (809,73, vence em 08/10) é pago em 06/10: trimestre até 08/01.
    $this->travelTo(CarbonImmutable::parse('2026-10-06 09:00:00'));
    $first = mpRenewPay($this->mp, array_key_first($this->mp->payments), '107592875934');

    $subscription->refresh();

    expect($first->normalized_payload['outcome'])->toBe('activated')
        ->and($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->ends_at->toDateTimeString())->toBe('2027-01-08 23:59:59')
        ->and($subscription->next_billing_at->toDateTimeString())->toBe('2027-01-08 23:59:59')
        ->and($firstInvoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($subscription->isDueForLocalRenewal(CarbonImmutable::parse('2027-01-09')))->toBeTrue();

    // Longe do vencimento: nada a emitir.
    $this->travelTo(CarbonImmutable::parse('2026-12-20 01:00:00'));
    expect(app(SubscriptionCycleService::class)->dispatchDueRenewals())->toBe(0);

    // O trimestral ficou mais caro; a clínica segue no valor contratado.
    $this->quarterly->update(['price' => 899.70]);

    // Dentro da antecedência: o agendador despacha e o job (fila síncrona
    // nos testes) emite o Pix do 2º trimestre.
    $this->travelTo(CarbonImmutable::parse('2027-01-04 01:00:00'));
    expect(app(SubscriptionCycleService::class)->dispatchDueRenewals())->toBe(1);

    $renewal = Invoice::query()->where('subscription_id', $subscription->id)->whereKeyNot($firstInvoice->id)->sole();

    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
        && $r->url() === 'https://api.mercadopago.com/v1/payments'
        && $r['external_reference'] === $renewal->id
        && $r['payment_method_id'] === 'pix'
        && (float) $r['transaction_amount'] === 809.73
        && str_starts_with((string) $r['date_of_expiration'], '2027-01-08T23:59:59'));

    expect($renewal->billing_reason)->toBe('subscription_cycle')
        ->and($renewal->period_start->toDateString())->toBe('2027-01-08')
        ->and($renewal->period_end->toDateString())->toBe('2027-04-08')
        ->and($renewal->due_at->toDateTimeString())->toBe('2027-01-08 23:59:59')
        ->and((float) $renewal->amount)->toBe(809.73)
        ->and($renewal->status)->toBe(InvoiceStatus::Pending)
        ->and($renewal->external_invoice_id)->toBe((string) array_key_last($this->mp->payments));

    // No dia seguinte o período já tem cobrança: nada novo.
    $this->travelTo(CarbonImmutable::parse('2027-01-05 01:00:00'));
    expect(app(SubscriptionCycleService::class)->dispatchDueRenewals())->toBe(0);

    // O cliente paga o 2º Pix: estende pelo ciclo contratado (trimestre).
    $this->travelTo(CarbonImmutable::parse('2027-01-07 14:00:00'));
    $second = mpRenewPay($this->mp, array_key_last($this->mp->payments), '107592875999');

    $subscription->refresh();

    expect($second->normalized_payload['outcome'])->toBe('renewed')
        ->and($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->ends_at->toDateTimeString())->toBe('2027-04-08 23:59:59')
        ->and($subscription->next_billing_at->toDateTimeString())->toBe('2027-04-08 23:59:59')
        ->and($renewal->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and(Payment::query()->where('subscription_id', $subscription->id)->where('status', PaymentStatus::Paid->value)->count())->toBe(2);

    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/preapproval'));
});

it('nova tentativa depois do vencimento, paga: a vigência vai até o fim do período da fatura (o atraso não vira dias de acesso)', function () {
    $subscription = app(BillingSubscriptionOrchestrator::class)
        ->activateWithGateway($this->clinic, $this->plan, BillingCycle::Quarterly, 'mercadopago');

    // 1º trimestre pago: até 08/01.
    $this->travelTo(CarbonImmutable::parse('2026-10-06 09:00:00'));
    mpRenewPay($this->mp, array_key_first($this->mp->payments), '107592875934');

    // Pix do 2º trimestre (período [08/01, 08/04), vence em 08/01).
    $this->travelTo(CarbonImmutable::parse('2027-01-04 01:00:00'));
    expect(app(SubscriptionCycleService::class)->dispatchDueRenewals())->toBe(1);

    $renewal   = Invoice::query()->where('subscription_id', $subscription->id)->where('billing_reason', 'subscription_cycle')->sole();
    $expiredId = array_key_last($this->mp->payments);

    // O Pix venceu sem pagamento: o Mercado Pago o cancela e a expiração
    // diária põe a assinatura em atraso desde o fim do período pago.
    $this->travelTo(CarbonImmutable::parse('2027-01-09 08:00:00'));
    $expired = mpRenewExpire($this->mp, $expiredId, '107592876001');

    expect($expired->normalized_payload['outcome'])->toBe('payment_cancelled')
        ->and($renewal->fresh()->status)->toBe(InvoiceStatus::Cancelled)
        ->and(app(SubscriptionService::class)->markLapsedAsPastDue())->toBe(1);

    $subscription->refresh();

    expect($subscription->status)->toBe(SubscriptionStatus::PastDue)
        ->and($subscription->past_due_at->toDateTimeString())->toBe('2027-01-08 23:59:59');

    // Nova tentativa (agendador de 10/01): o mesmo período, Pix vencendo hoje.
    $this->travelTo(CarbonImmutable::parse('2027-01-10 01:00:00'));
    expect(app(SubscriptionCycleService::class)->dispatchDueRenewals())->toBe(1);

    $retryId = array_key_last($this->mp->payments);

    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
        && $r->url() === 'https://api.mercadopago.com/v1/payments'
        && $r['external_reference'] === $renewal->id
        && (float) $r['transaction_amount'] === 809.73
        && str_starts_with((string) $r['date_of_expiration'], '2027-01-10T23:59:59'));

    $renewal->refresh();

    expect($retryId)->not->toBe($expiredId)
        ->and($renewal->status)->toBe(InvoiceStatus::Pending)
        ->and($renewal->external_invoice_id)->toBe((string) $retryId)
        ->and($renewal->period_start->toDateString())->toBe('2027-01-08')
        ->and($renewal->period_end->toDateString())->toBe('2027-04-08');

    // Paga a nova tentativa em 10/01: o Mercado Pago informa o vencimento do
    // Pix (10/01), mas a vigência segue o período da fatura — até 08/04, não 10/04.
    $this->travelTo(CarbonImmutable::parse('2027-01-10 15:00:00'));
    $paid = mpRenewPay($this->mp, $retryId, '107592876002');

    $subscription->refresh();

    expect($paid->normalized_payload['due_date'])->toBe('2027-01-10')
        ->and($paid->normalized_payload['outcome'])->toBe('renewed')
        ->and($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->ends_at->toDateTimeString())->toBe('2027-04-08 23:59:59')
        ->and($subscription->next_billing_at->toDateTimeString())->toBe('2027-04-08 23:59:59')
        ->and($subscription->past_due_at)->toBeNull()
        ->and($subscription->ends_at->toDateString())->toBe($renewal->period_end->toDateString())
        ->and($renewal->fresh()->status)->toBe(InvoiceStatus::Paid);
});
