<?php

declare(strict_types=1);

use App\Domains\AI\Models\AiCreditWallet;
use App\Domains\AI\Services\AiCreditPurchaseService;
use App\Enums\AI\AiCreditPurchaseStatus;
use App\Enums\Billing\{InvoiceStatus, PaymentStatus};
use App\Enums\{BillingCycle, SaasRule, SubscriptionStatus};
use App\Jobs\Billing\SendBillingRefundJob;
use App\Models\{AuditLog, Entity, Plan, PlanPrice, Subscription, User};
use App\Models\Billing\{BillingRefund, Invoice, Payment, WebhookEvent};
use App\Notifications\GatewayOperationalAlertNotification;
use App\Services\Billing\Gateways\MercadoPagoGateway;
use App\Services\Billing\{ProcessWebhookEventService, RefundService};
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\{ConnectionException, Request};
use Illuminate\Support\{Carbon, Str};
use Illuminate\Support\Facades\{Cache, Http, Notification, Queue};

/**
 * Correções da revisão do estorno pelo manager:
 *  - pacote de IA: estorno parcial e depois o restante não tiram créditos em
 *    dobro (Asaas e Mercado Pago), nunca saldo negativo;
 *  - timeout/5xx no pedido: "solicitado" inconclusivo, conferido no gateway
 *    (refunds[] — https://docs.asaas.com/docs/estornos) antes de outro
 *    pedido; 429: enviado depois por job com a MESMA chave;
 *  - PAYMENT_REFUND_DENIED / _IN_PROGRESS; "Conferir" no manager;
 *    billing:check-refunds libera o parado há 30 dias só conferindo;
 *  - "total" depois de um parcial vai com o valor restante
 *    (https://docs.asaas.com/reference/estornar-cobranca);
 *  - sem dois pedidos ao mesmo tempo.
 */
beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-05 10:00:00'));
    Http::preventStrayRequests();
    Notification::fake();
    Cache::flush();

    config([
        'billing.gateways.asaas.base_url'             => 'https://api.asaas.com',
        'billing.gateways.asaas.secret'               => '$aact_prod_chave_de_teste',
        'billing.gateways.asaas.webhook_secret'       => 'whsec_asaas_teste',
        'billing.gateways.mercadopago.base_url'       => 'https://api.mercadopago.com',
        'billing.gateways.mercadopago.secret'         => 'APP_USR-secret',
        'billing.gateways.mercadopago.webhook_secret' => null,
    ]);

    $this->saas  = Entity::factory()->create(['is_client' => false, 'active' => true]);
    $this->admin = User::factory()->create(['name' => 'Ana Admin', 'email_verified_at' => now()]);
    createEntityUser($this->saas, $this->admin, SaasRule::Admin->value);

    $this->clinic                = Entity::factory()->make(['is_client' => true, 'active' => true, 'name' => 'Clínica Estorno Revisão']);
    $this->clinic->skipAutoTrial = true;
    $this->clinic->save();

    $this->plan = Plan::factory()->create(['name' => 'Pro', 'billing_cycle' => 'monthly', 'price' => 299.90, 'active' => true]);
    PlanPrice::create(['plan_id' => $this->plan->id, 'billing_cycle' => 'monthly', 'price' => 299.90]);
});

afterEach(fn () => Carbon::setTestNow());

/** Fatura paga pela cobrança $externalId no gateway. */
function rvrPaid(string $gateway = 'asaas', string $externalId = 'pay_card', float $amount = 299.90): array
{
    $subscription = Subscription::factory()->gateway($gateway)->for(test()->clinic)->for(test()->plan)->create([
        'gateway_subscription_id' => $gateway === 'asaas' ? 'sub_ref' : null,
        'gateway_customer_id'     => 'cus_000005219613',
        'billing_cycle'           => BillingCycle::Monthly,
        'status'                  => SubscriptionStatus::Active,
        'billing_state'           => 'paid',
        'amount'                  => $amount,
        'last_payment_at'         => '2026-10-01 10:00:00',
        'ends_at'                 => '2026-11-01 23:59:59',
        'next_billing_at'         => '2026-11-01 23:59:59',
    ]);

    $invoice = Invoice::query()->create([
        'entity_id'           => $subscription->entity_id,
        'subscription_id'     => $subscription->id,
        'plan_id'             => $subscription->plan_id,
        'gateway_code'        => $gateway,
        'reference'           => 'INV-20261001-' . strtoupper($externalId),
        'external_invoice_id' => $externalId,
        'period_start'        => '2026-10-01',
        'period_end'          => '2026-11-01',
        'due_at'              => '2026-10-01 23:59:59',
        'paid_at'             => '2026-10-01 10:00:00',
        'amount'              => $amount,
        'currency'            => 'BRL',
        'status'              => InvoiceStatus::Paid->value,
        'billing_reason'      => 'subscription_cycle',
    ]);

    $payment = Payment::query()->create([
        'entity_id'           => $subscription->entity_id,
        'invoice_id'          => $invoice->id,
        'subscription_id'     => $subscription->id,
        'gateway_code'        => $gateway,
        'external_payment_id' => $externalId,
        'status'              => PaymentStatus::Paid->value,
        'amount'              => $amount,
        'currency'            => 'BRL',
        'paid_at'             => '2026-10-01 10:00:00',
    ]);

    return [$subscription, $invoice, $payment];
}

function rvrManager(): mixed
{
    return test()->actingAs(test()->admin)->withSession([
        'selected_entity_id'        => test()->saas->id,
        'selected_entity_is_client' => false,
        'selected_entity_user_rule' => 'admin',
    ]);
}

function rvrUrl(Subscription $s, Invoice $i, Payment $p): string
{
    return route('manager.subscriptions.payments.refund', ['subscription' => $s->id, 'invoice' => $i->id, 'payment' => $p->id]);
}

function rvrCheckUrl(Subscription $s, Invoice $i, Payment $p, BillingRefund $r): string
{
    return route('manager.subscriptions.payments.refunds.check', ['subscription' => $s->id, 'invoice' => $i->id, 'payment' => $p->id, 'refund' => $r->id]);
}

const RVR_REASON = 'Cliente pagou em duplicidade, estorno combinado com o financeiro';

/** GET /v3/payments/{id} no formato da doc. */
function rvrAsaasPayment(string $id, array $over = []): array
{
    return [
        'object'      => 'payment', 'id' => $id, 'customer' => 'cus_000005219613', 'subscription' => 'sub_ref', 'value' => 299.9,
        'billingType' => 'CREDIT_CARD', 'status' => 'CONFIRMED', 'dueDate' => '2026-10-01', 'refunds' => null, 'deleted' => false,
        ...$over,
    ];
}

function rvrWebhook(array $payload): ?string
{
    $id = 'evt_' . Str::random(24);
    test()->postJson('/api/billing/webhooks/asaas', ['id' => $id, 'dateCreated' => now()->format('Y-m-d H:i:s'), ...$payload], ['asaas-access-token' => 'whsec_asaas_teste'])->assertOk();

    return WebhookEvent::query()->where('external_event_id', $id)->value('normalized_payload')['outcome'] ?? null;
}

/** Pacote de IA (100 créditos) pago e creditado; a carteira com +200 de outro pacote. */
function rvrPack(string $gateway, string $externalId): array
{
    $purchase = app(AiCreditPurchaseService::class)->createPendingPurchase(entityId: (string) test()->clinic->id, packageCode: 'operational', source: 'checkout');
    $invoice  = Invoice::query()->create([
        'entity_id'      => test()->clinic->id, 'gateway_code' => $gateway, 'reference' => 'IA-RVR', 'external_invoice_id' => $externalId,
        'due_at'         => '2026-10-08 23:59:59', 'amount' => 249.90, 'currency' => 'BRL', 'status' => InvoiceStatus::Pending->value,
        'billing_reason' => Invoice::BILLING_REASON_AI_CREDIT_PACK, 'metadata' => ['ai_credit_purchase_id' => (string) $purchase->id, 'credits' => 100],
    ]);
    $purchase->update(['invoice_id' => $invoice->id]);

    return [$purchase, $invoice];
}

function rvrBalance(): int
{
    return (int) AiCreditWallet::query()->where('entity_id', test()->clinic->id)->value('balance');
}

describe('pacote de IA: parcial e depois o restante (achado 4)', function () {
    it('Asaas: PARTIALLY_REFUNDED (50%) e depois PAYMENT_REFUNDED (restante) tiram só os 100 créditos do pacote', function () {
        [$purchase, $invoice] = rvrPack('asaas', 'pay_pack');
        Http::fake();
        rvrWebhook(['event' => 'PAYMENT_CONFIRMED', 'payment' => rvrAsaasPayment('pay_pack', ['subscription' => null, 'value' => 249.9, 'externalReference' => (string) $invoice->id])]);
        AiCreditWallet::query()->where('entity_id', $this->clinic->id)->update(['balance' => 300]);

        rvrWebhook(['event' => 'PAYMENT_PARTIALLY_REFUNDED', 'payment' => rvrAsaasPayment('pay_pack', ['subscription' => null, 'value' => 249.9, 'refunds' => [['status' => 'DONE', 'value' => 124.95]]])]);
        expect(rvrBalance())->toBe(250);

        $outcome = rvrWebhook(['event' => 'PAYMENT_REFUNDED', 'payment' => rvrAsaasPayment('pay_pack', ['subscription' => null, 'value' => 249.9, 'status' => 'REFUNDED', 'refunds' => [['status' => 'DONE', 'value' => 124.95], ['status' => 'DONE', 'value' => 124.95]]])]);

        expect($outcome)->toBe('ai_credits_refunded')
            ->and(rvrBalance())->toBe(200)
            ->and($purchase->fresh()->status)->toBe(AiCreditPurchaseStatus::Refunded)
            ->and(data_get($purchase->fresh()->metadata, 'gateway_reversal'))->toMatchArray(['already_revoked' => 50, 'revoked' => 50, 'shortfall' => 0]);

        // Reentrega do estorno total: nada sai de novo.
        rvrWebhook(['event' => 'PAYMENT_REFUNDED', 'payment' => rvrAsaasPayment('pay_pack', ['subscription' => null, 'value' => 249.9, 'status' => 'REFUNDED'])]);
        expect(rvrBalance())->toBe(200);
    });

    it('Mercado Pago: approved com transaction_amount_refunded e depois refunded — o restante só', function () {
        [$purchase, $invoice] = rvrPack('mercadopago', '555000111');
        Http::fake(['https://api.mercadopago.com/v1/payments/555000111' => Http::sequence()
            ->push(['id' => 555000111, 'status' => 'approved', 'transaction_amount' => 249.9, 'external_reference' => (string) $invoice->id])
            ->push(['id' => 555000111, 'status' => 'approved', 'transaction_amount' => 249.9, 'transaction_amount_refunded' => 124.95, 'external_reference' => (string) $invoice->id])
            ->push(['id' => 555000111, 'status' => 'refunded', 'transaction_amount' => 249.9, 'transaction_amount_refunded' => 249.9, 'external_reference' => (string) $invoice->id])]);
        $mp       = app(MercadoPagoGateway::class);
        $webhooks = app(ProcessWebhookEventService::class);

        $webhooks->applyNormalized($mp->paymentStatusEvent('555000111'));
        AiCreditWallet::query()->where('entity_id', $this->clinic->id)->update(['balance' => 300]);

        expect($webhooks->applyNormalized($mp->paymentStatusEvent('555000111')))->toBe('partially_refunded')
            ->and(rvrBalance())->toBe(250);

        expect($webhooks->applyNormalized($mp->paymentStatusEvent('555000111')))->toBe('ai_credits_refunded')
            ->and(rvrBalance())->toBe(200)
            ->and($purchase->fresh()->status)->toBe(AiCreditPurchaseStatus::Refunded);
    });

    it('sem saldo para o restante: tira o que há (nunca negativo) e alerta o que faltou', function () {
        [$purchase, $invoice] = rvrPack('asaas', 'pay_pack');
        Http::fake();
        rvrWebhook(['event' => 'PAYMENT_CONFIRMED', 'payment' => rvrAsaasPayment('pay_pack', ['subscription' => null, 'value' => 249.9, 'externalReference' => (string) $invoice->id])]);
        rvrWebhook(['event' => 'PAYMENT_PARTIALLY_REFUNDED', 'payment' => rvrAsaasPayment('pay_pack', ['subscription' => null, 'value' => 249.9, 'refunds' => [['status' => 'DONE', 'value' => 124.95]]])]);
        // A clínica usou o resto: sobraram 10 dos 50 que ainda deveriam sair.
        AiCreditWallet::query()->where('entity_id', $this->clinic->id)->update(['balance' => 10]);

        rvrWebhook(['event' => 'PAYMENT_REFUNDED', 'payment' => rvrAsaasPayment('pay_pack', ['subscription' => null, 'value' => 249.9, 'status' => 'REFUNDED'])]);

        expect(rvrBalance())->toBe(0)
            ->and(data_get($purchase->fresh()->metadata, 'gateway_reversal'))->toMatchArray(['already_revoked' => 50, 'revoked' => 10, 'shortfall' => 40]);
        Notification::assertSentTo($this->admin, GatewayOperationalAlertNotification::class, fn ($n) => $n->kind === 'refund_credits' && (int) $n->params['shortfall'] === 40);
    });
});

describe('estorno sem resposta definitiva (achado 6)', function () {
    it('Asaas: timeout no POST → "solicitado" inconclusivo; outro pedido é bloqueado até conferir; a conferência acha o estorno e conclui', function () {
        [$subscription, $invoice, $payment] = rvrPaid();
        Http::fake([
            'https://api.asaas.com/v3/payments/pay_card/refund' => Http::failedConnection(),
            'https://api.asaas.com/v3/payments/pay_card'        => Http::sequence()
                ->push(rvrAsaasPayment('pay_card'))
                ->push(rvrAsaasPayment('pay_card', ['status' => 'REFUNDED', 'refunds' => [['status' => 'DONE', 'value' => 299.9, 'dateCreated' => '2026-10-05 10:00:30', 'description' => RVR_REASON]]])),
        ]);

        rvrManager()->postJson(rvrUrl($subscription, $invoice, $payment), ['mode' => 'full', 'reason' => RVR_REASON])
            ->assertOk()
            ->assertJsonPath('message', __('manager_subscriptions.refund.inconclusive'))
            ->assertJsonPath('data.status', 'requested')
            ->assertJsonPath('data.gateway_state', BillingRefund::STATE_INCONCLUSIVE);

        $refund = BillingRefund::query()->sole();

        // Novo pedido (com outra chave) poderia estornar duas vezes: bloqueado.
        rvrManager()->postJson(rvrUrl($subscription, $invoice, $payment), ['mode' => 'full', 'reason' => RVR_REASON])
            ->assertStatus(422)
            ->assertJsonPath('message', __('manager_subscriptions.refund.errors.pending'));
        expect(collect(Http::recorded())->filter(fn ($p) => str_ends_with($p[0]->url(), '/refund'))->count())->toBe(1);

        rvrManager()->postJson(rvrCheckUrl($subscription, $invoice, $payment, $refund))
            ->assertOk()
            ->assertJsonPath('outcome', 'done');

        expect($refund->fresh()->status)->toBe(BillingRefund::STATUS_DONE)
            ->and($payment->fresh()->status)->toBe(PaymentStatus::Refunded)
            ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Refunded)
            ->and(AuditLog::query()->where('event', 'manager.subscription.payment.refund_check')->exists())->toBeTrue();
    });

    it('Asaas: 5xx no POST e o estorno não existe lá → a conferência libera um novo pedido', function () {
        [$subscription, $invoice, $payment] = rvrPaid();
        Http::fake([
            'https://api.asaas.com/v3/payments/pay_card/refund' => Http::response(['errors' => [['code' => 'internal_error']]], 502),
            'https://api.asaas.com/v3/payments/pay_card'        => Http::response(rvrAsaasPayment('pay_card', ['refunds' => []])),
        ]);

        rvrManager()->postJson(rvrUrl($subscription, $invoice, $payment), ['mode' => 'full', 'reason' => RVR_REASON])->assertOk();
        $refund = BillingRefund::query()->sole();
        expect($refund->status)->toBe(BillingRefund::STATUS_REQUESTED)
            ->and($refund->gateway_state)->toBe(BillingRefund::STATE_INCONCLUSIVE);

        rvrManager()->postJson(rvrCheckUrl($subscription, $invoice, $payment, $refund))->assertOk()->assertJsonPath('outcome', 'cancelled');

        expect($refund->fresh()->status)->toBe(BillingRefund::STATUS_CANCELLED)
            ->and($refund->fresh()->check_note)->toBe('not_found')
            ->and(app(RefundService::class)->options($payment->fresh())['can_refund'])->toBeTrue();
    });

    it('Mercado Pago: 429 → pedido na fila; o job reenvia o MESMO pedido (mesma X-Idempotency-Key) depois da espera', function () {
        Queue::fake([SendBillingRefundJob::class]);
        [$subscription, $invoice, $payment] = rvrPaid('mercadopago', '123456789');
        Http::fake(['https://api.mercadopago.com/v1/payments/123456789/refunds' => Http::sequence()
            ->push(['message' => 'too many requests'], 429, ['Retry-After' => '30'])
            ->push(['id' => 991, 'payment_id' => 123456789, 'amount' => 80.0, 'status' => 'approved'])]);

        rvrManager()->postJson(rvrUrl($subscription, $invoice, $payment), ['mode' => 'partial', 'amount' => 80, 'reason' => RVR_REASON])
            ->assertOk()
            ->assertJsonPath('message', __('manager_subscriptions.refund.queued'));

        $refund = BillingRefund::query()->sole();
        expect($refund->status)->toBe(BillingRefund::STATUS_REQUESTED)
            ->and($refund->gateway_state)->toBe(BillingRefund::STATE_QUEUED);
        Queue::assertPushed(SendBillingRefundJob::class, fn (SendBillingRefundJob $job) => $job->refundId === (string) $refund->id);

        $this->travel(31)->seconds();
        (new SendBillingRefundJob((string) $refund->id))->handle(app(RefundService::class));

        $keys = collect(Http::recorded())->map(fn ($p) => $p[0]->header('X-Idempotency-Key')[0] ?? null)->unique()->values();
        expect($keys)->toHaveCount(1)
            ->and($keys[0])->toBe($refund->idempotency_key)
            ->and($refund->fresh()->status)->toBe(BillingRefund::STATUS_DONE)
            ->and((float) $payment->fresh()->refunded_amount)->toBe(80.0);
    });

    it('Mercado Pago: timeout → inconclusivo; a conferência (GET /v1/payments/{id}/refunds) acha o estorno aprovado', function () {
        [$subscription, $invoice, $payment] = rvrPaid('mercadopago', '123456789');
        Http::fake(fn (Request $r) => $r->method() === 'POST'
            ? throw new ConnectionException('timeout')
            : Http::response([['id' => 991, 'payment_id' => 123456789, 'amount' => 299.9, 'status' => 'approved', 'date_created' => '2026-10-05T10:00:01.000-03:00']]));

        rvrManager()->postJson(rvrUrl($subscription, $invoice, $payment), ['mode' => 'full', 'reason' => RVR_REASON])->assertOk();
        $refund = BillingRefund::query()->sole();
        expect($refund->gateway_state)->toBe(BillingRefund::STATE_INCONCLUSIVE);

        expect(app(RefundService::class)->check($refund))->toBe('done')
            ->and($refund->fresh()->status)->toBe(BillingRefund::STATUS_DONE)
            ->and($payment->fresh()->status)->toBe(PaymentStatus::Refunded);
        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && $r->url() === 'https://api.mercadopago.com/v1/payments/123456789/refunds');
    });
});

describe('pedido parado em "solicitado" (achado 7)', function () {
    it('PAYMENT_REFUND_DENIED marca o pedido como recusado e libera outro; PAYMENT_REFUND_IN_PROGRESS mantém solicitado', function () {
        [$subscription, $invoice, $payment] = rvrPaid();
        Http::fake([
            'https://api.asaas.com/v3/payments/pay_card/bankSlip/refund' => Http::response(['requestUrl' => 'https://www.asaas.com/refundRequest/abc123']),
            'https://api.asaas.com/v3/payments/pay_card'                 => Http::response(rvrAsaasPayment('pay_card', ['billingType' => 'BOLETO', 'status' => 'RECEIVED'])),
        ]);
        rvrManager()->postJson(rvrUrl($subscription, $invoice, $payment), ['mode' => 'full', 'reason' => RVR_REASON])->assertOk();
        $refund = BillingRefund::query()->sole();

        expect(rvrWebhook(['event' => 'PAYMENT_REFUND_IN_PROGRESS', 'payment' => rvrAsaasPayment('pay_card', ['billingType' => 'BOLETO', 'status' => 'REFUND_IN_PROGRESS'])]))->toBe('refund_in_progress')
            ->and($refund->fresh()->status)->toBe(BillingRefund::STATUS_REQUESTED)
            ->and($refund->fresh()->check_note)->toBe('in_progress');

        expect(rvrWebhook(['event' => 'PAYMENT_REFUND_DENIED', 'payment' => rvrAsaasPayment('pay_card', ['billingType' => 'BOLETO', 'status' => 'RECEIVED'])]))->toBe('refund_denied')
            ->and($refund->fresh()->status)->toBe(BillingRefund::STATUS_FAILED)
            ->and($refund->fresh()->check_note)->toBe('denied')
            ->and($payment->fresh()->status)->toBe(PaymentStatus::Paid)
            ->and(app(RefundService::class)->options($payment->fresh())['can_refund'])->toBeTrue();
    });

    it('"Conferir": boleto aguardando a conta do pagador segue solicitado, com a situação para o manager', function () {
        [$subscription, $invoice, $payment] = rvrPaid();
        Http::fake([
            'https://api.asaas.com/v3/payments/pay_card/bankSlip/refund' => Http::response(['requestUrl' => 'https://www.asaas.com/refundRequest/abc123']),
            'https://api.asaas.com/v3/payments/pay_card'                 => Http::sequence()
                ->push(rvrAsaasPayment('pay_card', ['billingType' => 'BOLETO', 'status' => 'RECEIVED']))
                ->push(rvrAsaasPayment('pay_card', ['billingType' => 'BOLETO', 'status' => 'REFUND_REQUESTED', 'refunds' => []])),
        ]);
        rvrManager()->postJson(rvrUrl($subscription, $invoice, $payment), ['mode' => 'full', 'reason' => RVR_REASON])->assertOk();
        $refund = BillingRefund::query()->sole();

        rvrManager()->postJson(rvrCheckUrl($subscription, $invoice, $payment, $refund))
            ->assertOk()
            ->assertJsonPath('outcome', 'pending')
            ->assertJsonPath('data.check_note', 'awaiting_payer_account');

        $row = collect(rvrManager()->getJson(route('manager.subscriptions.invoices', $subscription))->json('data.0.payments.0.refunds'))->first();
        expect($row['status'])->toBe('requested')
            ->and($row['check_note'])->toBe('awaiting_payer_account')
            ->and($row['can_check'])->toBeTrue()
            ->and($row['check_url'])->toBe(rvrCheckUrl($subscription, $invoice, $payment, $refund));
    });

    it('billing:check-refunds: parado há mais de 30 dias e inexistente no gateway é liberado; em andamento segue (agendado de hora em hora)', function () {
        [$subscription, $invoice, $payment]    = rvrPaid();
        [$subscription2, $invoice2, $payment2] = rvrPaid('asaas', 'pay_boleto');
        $old                                   = fn (Payment $p, string $key) => BillingRefund::query()->create([
            'entity_id'    => $p->entity_id, 'payment_id' => $p->id, 'invoice_id' => $p->invoice_id, 'subscription_id' => $p->subscription_id,
            'gateway_code' => 'asaas', 'external_payment_id' => $p->external_payment_id, 'amount' => 299.90, 'partial' => false,
            'status'       => BillingRefund::STATUS_REQUESTED, 'gateway_state' => BillingRefund::STATE_SENT, 'reason' => RVR_REASON,
            'requested_at' => now()->subDays(31), 'idempotency_key' => $key,
        ]);
        $lost    = $old($payment, 'refund:lost');
        $waiting = $old($payment2, 'refund:waiting');
        Http::fake([
            'https://api.asaas.com/v3/payments/pay_card'   => Http::response(rvrAsaasPayment('pay_card', ['refunds' => []])),
            'https://api.asaas.com/v3/payments/pay_boleto' => Http::response(rvrAsaasPayment('pay_boleto', ['billingType' => 'BOLETO', 'status' => 'REFUND_REQUESTED'])),
        ]);

        $this->artisan('billing:check-refunds')->assertExitCode(0);

        expect($lost->fresh()->status)->toBe(BillingRefund::STATUS_CANCELLED)
            ->and($waiting->fresh()->status)->toBe(BillingRefund::STATUS_REQUESTED)
            ->and($waiting->fresh()->check_note)->toBe('awaiting_payer_account');

        $events = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command ?? '')->implode(' ');
        expect($events)->toContain('billing:check-refunds');
    });
});

describe('valor e concorrência do pedido (achados 11 e 12)', function () {
    it('estorno "total" depois de um parcial vai com o valor restante (sem value o Asaas estornaria o integral)', function () {
        [$subscription, $invoice, $payment] = rvrPaid();
        $payment->update(['refunded_amount' => 100]);
        Http::fake([
            'https://api.asaas.com/v3/payments/pay_card/refund' => Http::response(rvrAsaasPayment('pay_card', ['refunds' => [['status' => 'DONE', 'value' => 100], ['status' => 'PENDING', 'value' => 199.9]]])),
            'https://api.asaas.com/v3/payments/pay_card'        => Http::response(rvrAsaasPayment('pay_card', ['refunds' => [['status' => 'DONE', 'value' => 100]]])),
        ]);

        rvrManager()->postJson(rvrUrl($subscription, $invoice, $payment), ['mode' => 'full', 'reason' => RVR_REASON])->assertOk();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/refund') && isset($r['value']) && (float) $r['value'] === 199.9);
        $refund = BillingRefund::query()->sole();
        expect((float) $refund->amount)->toBe(199.9)
            ->and($refund->partial)->toBeFalse();
    });

    it('dois pedidos ao mesmo tempo: o segundo é recusado sem chamar o gateway', function () {
        [$subscription, $invoice, $payment] = rvrPaid();
        Http::fake();

        // Outro pedido do mesmo pagamento em andamento (outra aba/duplo clique).
        $lock = Cache::lock('billing:refund:payment:' . $payment->id, 120);
        expect($lock->get())->toBeTrue();

        rvrManager()->postJson(rvrUrl($subscription, $invoice, $payment), ['mode' => 'full', 'reason' => RVR_REASON])
            ->assertStatus(422)
            ->assertJsonPath('message', __('manager_subscriptions.refund.errors.pending'));

        Http::assertNothingSent();
        expect(BillingRefund::query()->count())->toBe(0);
        $lock->release();
    });
});
