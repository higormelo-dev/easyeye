<?php

declare(strict_types=1);

use App\Domains\AI\Models\{AiCreditPurchase, AiCreditWallet};
use App\Domains\AI\Services\AiCreditPurchaseService;
use App\Enums\AI\AiCreditPurchaseStatus;
use App\Enums\Billing\{BillingEventType, InvoiceStatus, PaymentStatus};
use App\Enums\{BillingCycle, SaasRule, SubscriptionStatus};
use App\Models\{AuditLog, Entity, Plan, PlanPrice, Subscription, User};
use App\Models\Billing\{BillingRefund, FinancialEvent, Invoice, Payment};
use App\Models\Billing\WebhookEvent;
use App\Notifications\GatewayOperationalAlertNotification;
use App\Services\Billing\Gateways\MercadoPagoGateway;
use App\Services\Billing\ProcessWebhookEventService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\{Carbon, Str};
use Illuminate\Support\Facades\{Cache, Http, Notification};

/**
 * Estorno pelo manager (Assinaturas → detalhe → faturas → Estornar):
 *  - Asaas: cartão/Pix por POST /v3/payments/{id}/refund (parcial com
 *    value — https://docs.asaas.com/reference/estornar-cobranca); boleto por
 *    POST /v3/payments/{id}/bankSlip/refund (só total, devolve requestUrl —
 *    https://docs.asaas.com/reference/estornar-boleto); concluído só com
 *    refunds[] DONE ou o webhook (https://docs.asaas.com/docs/estornos);
 *  - Mercado Pago: POST /v1/payments/{id}/refunds (refundPayment);
 *  - demais gateways: sem botão;
 *  - justificativa obrigatória, auditoria e só admin/financeiro do SaaS;
 *  - PAYMENT_PARTIALLY_REFUNDED: registra o devolvido; pacote de IA tira os
 *    créditos proporcionais (arredondado para baixo) sem saldo negativo.
 */
beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-05 10:00:00'));
    Http::preventStrayRequests();
    Notification::fake();
    Cache::flush();

    config([
        'billing.gateways.asaas.base_url'       => 'https://api.asaas.com',
        'billing.gateways.asaas.secret'         => '$aact_prod_chave_de_teste',
        'billing.gateways.asaas.webhook_secret' => 'whsec_asaas_teste',
        'billing.gateways.mercadopago.base_url' => 'https://api.mercadopago.com',
        'billing.gateways.mercadopago.secret'   => 'APP_USR-secret',
    ]);

    $this->saas  = Entity::factory()->create(['is_client' => false, 'active' => true]);
    $this->admin = User::factory()->create(['name' => 'Ana Admin', 'email_verified_at' => now()]);
    createEntityUser($this->saas, $this->admin, SaasRule::Admin->value);

    $this->clinic                = Entity::factory()->make(['is_client' => true, 'active' => true, 'name' => 'Clínica Estorno']);
    $this->clinic->skipAutoTrial = true;
    $this->clinic->save();

    $this->plan = Plan::factory()->create(['name' => 'Pro', 'billing_cycle' => 'monthly', 'price' => 299.90, 'active' => true]);
    PlanPrice::create(['plan_id' => $this->plan->id, 'billing_cycle' => 'monthly', 'price' => 299.90]);
});

afterEach(fn () => Carbon::setTestNow());

/** Fatura paga (cobrança pay_card no gateway). */
function refPaid(string $gateway = 'asaas', string $externalId = 'pay_card', float $amount = 299.90): array
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
        'reference'           => 'INV-20261001-REF',
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

function refManager(?User $user = null): mixed
{
    return test()->actingAs($user ?? test()->admin)->withSession([
        'selected_entity_id'        => test()->saas->id,
        'selected_entity_is_client' => false,
        'selected_entity_user_rule' => 'admin',
    ]);
}

function refUrl(Subscription $s, Invoice $i, Payment $p): string
{
    return route('manager.subscriptions.payments.refund', ['subscription' => $s->id, 'invoice' => $i->id, 'payment' => $p->id]);
}

const REF_REASON = 'Cliente pagou em duplicidade, estorno combinado com o financeiro';

/** GET /v3/payments/{id} (cartão) no formato da doc. */
function refAsaasPayment(string $id, array $over = []): array
{
    return [
        'object'       => 'payment',
        'id'           => $id,
        'customer'     => 'cus_000005219613',
        'subscription' => 'sub_ref',
        'value'        => 299.9,
        'billingType'  => 'CREDIT_CARD',
        'status'       => 'CONFIRMED',
        'dueDate'      => '2026-10-01',
        'refunds'      => null,
        'deleted'      => false,
        ...$over,
    ];
}

function refWebhook(array $payload): ?string
{
    $id = 'evt_' . Str::random(24);
    test()->postJson('/api/billing/webhooks/asaas', ['id' => $id, 'dateCreated' => now()->format('Y-m-d H:i:s'), ...$payload], ['asaas-access-token' => 'whsec_asaas_teste'])->assertOk();

    return WebhookEvent::query()->where('external_event_id', $id)->value('normalized_payload')['outcome'] ?? null;
}

describe('Asaas', function () {
    it('estorno total no cartão: pedido ao gateway com a justificativa, "solicitado" até o webhook confirmar', function () {
        [$subscription, $invoice, $payment] = refPaid();
        Http::fake([
            'https://api.asaas.com/v3/payments/pay_card/refund' => Http::response(refAsaasPayment('pay_card', ['status' => 'REFUND_REQUESTED', 'refunds' => [['status' => 'PENDING', 'value' => 299.9, 'dateCreated' => '2026-10-05 10:00:00']]])),
            'https://api.asaas.com/v3/payments/pay_card'        => Http::response(refAsaasPayment('pay_card')),
        ]);

        refManager()->postJson(refUrl($subscription, $invoice, $payment), ['mode' => 'full', 'reason' => REF_REASON])
            ->assertOk()
            ->assertJsonPath('message', __('manager_subscriptions.refund.requested'))
            ->assertJsonPath('data.status', 'requested');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'https://api.asaas.com/v3/payments/pay_card/refund'
            && ! array_key_exists('value', $r->data()) && $r['description'] === REF_REASON);

        $refund = BillingRefund::query()->sole();
        expect($refund->status)->toBe(BillingRefund::STATUS_REQUESTED)
            ->and($refund->partial)->toBeFalse()
            ->and((float) $refund->amount)->toBe(299.9)
            ->and($refund->requested_by)->toBe($this->admin->id)
            ->and($refund->reason)->toBe(REF_REASON)
            ->and($payment->fresh()->status)->toBe(PaymentStatus::Paid)
            ->and(AuditLog::query()->where('event', 'manager.subscription.payment.refund')->where('auditable_id', $payment->id)->exists())->toBeTrue()
            ->and(FinancialEvent::query()->where('event_type', BillingEventType::RefundRequested->value)->count())->toBe(1);

        // Confirmação do gateway (webhook): concluído e aplicado.
        refWebhook(['event' => 'PAYMENT_REFUNDED', 'payment' => refAsaasPayment('pay_card', ['status' => 'REFUNDED', 'refunds' => [['status' => 'DONE', 'value' => 299.9]]])]);

        expect($refund->fresh()->status)->toBe(BillingRefund::STATUS_DONE)
            ->and($payment->fresh()->status)->toBe(PaymentStatus::Refunded)
            ->and((float) $payment->fresh()->refunded_amount)->toBe(299.9)
            ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Refunded);
    });

    it('estorno parcial concluído na resposta (refunds[] DONE): registra o devolvido pelo caminho do webhook, sem mexer no acesso', function () {
        [$subscription, $invoice, $payment] = refPaid();
        Http::fake([
            'https://api.asaas.com/v3/payments/pay_card/refund' => Http::response(refAsaasPayment('pay_card', ['refunds' => [['status' => 'DONE', 'value' => 100.0]]])),
            'https://api.asaas.com/v3/payments/pay_card'        => Http::response(refAsaasPayment('pay_card')),
        ]);

        refManager()->postJson(refUrl($subscription, $invoice, $payment), ['mode' => 'partial', 'amount' => 100, 'reason' => REF_REASON])
            ->assertOk()
            ->assertJsonPath('message', __('manager_subscriptions.refund.done'))
            ->assertJsonPath('data.status', 'done');

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.asaas.com/v3/payments/pay_card/refund' && (float) $r['value'] === 100.0);

        expect((float) $payment->fresh()->refunded_amount)->toBe(100.0)
            ->and($payment->fresh()->status)->toBe(PaymentStatus::Paid)
            ->and((float) $invoice->fresh()->refunded_amount)->toBe(100.0)
            ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
            ->and($subscription->fresh()->status)->toBe(SubscriptionStatus::Active)
            ->and($subscription->fresh()->ends_at->toDateTimeString())->toBe('2026-11-01 23:59:59')
            ->and(FinancialEvent::query()->where('event_type', BillingEventType::PaymentPartiallyRefunded->value)->sole()->amount)->toEqual('100.00');

        // O webhook do mesmo estorno não repete o lançamento.
        $outcome = refWebhook(['event' => 'PAYMENT_PARTIALLY_REFUNDED', 'payment' => refAsaasPayment('pay_card', ['refunds' => [['status' => 'DONE', 'value' => 100.0]]])]);
        expect($outcome)->toBe('duplicate')
            ->and(FinancialEvent::query()->where('event_type', BillingEventType::PaymentPartiallyRefunded->value)->count())->toBe(1);

        // Opções: falta devolver 199,90.
        $row = collect(refManager()->getJson(route('manager.subscriptions.invoices', $subscription))->assertOk()->json('data'))->first();
        expect($row['payments'][0]['refund'])->toMatchArray(['can_refund' => true, 'partial' => true, 'remaining' => 199.9, 'refunded' => 100.0])
            ->and($row['payments'][0]['refunds'][0]['status'])->toBe('done');
    });

    it('boleto: pedido pelo endpoint do boleto, com o link para a clínica informar a conta; parcial é recusado', function () {
        [$subscription, $invoice, $payment] = refPaid();
        Http::fake([
            'https://api.asaas.com/v3/payments/pay_card/bankSlip/refund' => Http::response(['requestUrl' => 'https://www.asaas.com/refundRequest/abc123']),
            'https://api.asaas.com/v3/payments/pay_card'                 => Http::response(refAsaasPayment('pay_card', ['billingType' => 'BOLETO', 'status' => 'RECEIVED'])),
        ]);

        refManager()->postJson(refUrl($subscription, $invoice, $payment), ['mode' => 'partial', 'amount' => 50, 'reason' => REF_REASON])
            ->assertStatus(422)
            ->assertJsonPath('message', __('manager_subscriptions.refund.errors.refused', ['error' => __('manager_subscriptions.refund.errors.boleto_partial')]));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'bankSlip'));
        expect(BillingRefund::query()->sole()->status)->toBe(BillingRefund::STATUS_FAILED);

        refManager()->postJson(refUrl($subscription, $invoice, $payment), ['mode' => 'full', 'reason' => REF_REASON])
            ->assertOk()
            ->assertJsonPath('data.request_url', 'https://www.asaas.com/refundRequest/abc123')
            ->assertJsonPath('data.status', 'requested');
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'https://api.asaas.com/v3/payments/pay_card/bankSlip/refund');
    });

    it('validação: justificativa curta, valor acima do pago, estorno em andamento', function () {
        [$subscription, $invoice, $payment] = refPaid();
        Http::fake([
            'https://api.asaas.com/v3/payments/pay_card/refund' => Http::response(refAsaasPayment('pay_card', ['refunds' => [['status' => 'PENDING', 'value' => 50]]])),
            'https://api.asaas.com/v3/payments/pay_card'        => Http::response(refAsaasPayment('pay_card')),
        ]);

        refManager()->postJson(refUrl($subscription, $invoice, $payment), ['mode' => 'full', 'reason' => 'curta'])
            ->assertStatus(422)->assertJsonValidationErrors('reason');
        refManager()->postJson(refUrl($subscription, $invoice, $payment), ['mode' => 'partial', 'reason' => REF_REASON])
            ->assertStatus(422)->assertJsonValidationErrors('amount');
        refManager()->postJson(refUrl($subscription, $invoice, $payment), ['mode' => 'partial', 'amount' => 500, 'reason' => REF_REASON])
            ->assertStatus(422);
        Http::assertNothingSent();

        refManager()->postJson(refUrl($subscription, $invoice, $payment), ['mode' => 'partial', 'amount' => 50, 'reason' => REF_REASON])->assertOk();
        refManager()->postJson(refUrl($subscription, $invoice, $payment), ['mode' => 'partial', 'amount' => 50, 'reason' => REF_REASON])
            ->assertStatus(422)
            ->assertJsonPath('message', __('manager_subscriptions.refund.errors.pending'));
    });

    it('só admin e financeiro do SaaS estornam', function () {
        [$subscription, $invoice, $payment] = refPaid();
        $support                            = User::factory()->create();
        createEntityUser($this->saas, $support, SaasRule::Support->value);
        Http::fake();

        test()->actingAs($support)->withSession([
            'selected_entity_id'        => $this->saas->id,
            'selected_entity_is_client' => false,
            'selected_entity_user_rule' => 'support',
        ])->postJson(refUrl($subscription, $invoice, $payment), ['mode' => 'full', 'reason' => REF_REASON])->assertForbidden();

        Http::assertNothingSent();
        expect(BillingRefund::query()->count())->toBe(0);
    });
});

describe('outros gateways', function () {
    it('Mercado Pago: POST /v1/payments/{id}/refunds com o valor e a chave de idempotência; aprovado = concluído', function () {
        [$subscription, $invoice, $payment] = refPaid('mercadopago', '123456789');
        Http::fake([
            'https://api.mercadopago.com/v1/payments/123456789/refunds' => Http::response(['id' => 991, 'payment_id' => 123456789, 'amount' => 80.0, 'status' => 'approved']),
        ]);

        refManager()->postJson(refUrl($subscription, $invoice, $payment), ['mode' => 'partial', 'amount' => 80, 'reason' => REF_REASON])
            ->assertOk()
            ->assertJsonPath('data.status', 'done');

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.mercadopago.com/v1/payments/123456789/refunds'
            && (float) $r['amount'] === 80.0 && filled($r->header('X-Idempotency-Key')[0] ?? null));
        expect((float) $payment->fresh()->refunded_amount)->toBe(80.0)
            ->and(BillingRefund::query()->sole()->external_refund_id)->toBe('991');
    });

    it('gateway sem estorno pela API (PagBank): sem botão e o pedido é recusado sem chamar o gateway', function () {
        [$subscription, $invoice, $payment] = refPaid('pagbank', 'CHAR_1');
        Http::fake();

        $row = collect(refManager()->getJson(route('manager.subscriptions.invoices', $subscription))->assertOk()->json('data'))->first();
        expect($row['payments'][0]['refund']['can_refund'])->toBeFalse();

        refManager()->postJson(refUrl($subscription, $invoice, $payment), ['mode' => 'full', 'reason' => REF_REASON])
            ->assertStatus(422)
            ->assertJsonPath('message', __('manager_subscriptions.refund.errors.not_refundable'));
        Http::assertNothingSent();
    });
});

describe('PAYMENT_PARTIALLY_REFUNDED', function () {
    it('assinatura: registra o devolvido (sem refunds no aviso, consulta a cobrança); fatura paga e acesso iguais', function () {
        [$subscription, $invoice, $payment] = refPaid();
        Http::fake(['https://api.asaas.com/v3/payments/pay_card' => Http::response(refAsaasPayment('pay_card', ['refunds' => [['status' => 'DONE', 'value' => 60.0], ['status' => 'CANCELLED', 'value' => 10.0]]]))]);

        $outcome = refWebhook(['event' => 'PAYMENT_PARTIALLY_REFUNDED', 'payment' => refAsaasPayment('pay_card')]);

        expect($outcome)->toBe('partially_refunded')
            ->and((float) $payment->fresh()->refunded_amount)->toBe(60.0)
            ->and($payment->fresh()->status)->toBe(PaymentStatus::Paid)
            ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
            ->and($subscription->fresh()->status)->toBe(SubscriptionStatus::Active)
            ->and(FinancialEvent::query()->where('event_type', BillingEventType::PaymentRefunded->value)->count())->toBe(0)
            ->and(FinancialEvent::query()->where('event_type', BillingEventType::PaymentPartiallyRefunded->value)->sole()->amount)->toEqual('60.00');
    });

    it('Mercado Pago: aprovado com transaction_amount_refunded vira estorno parcial', function () {
        [, , $payment] = refPaid('mercadopago', '123456789');
        config(['billing.gateways.mercadopago.webhook_secret' => null]);
        Http::fake(['https://api.mercadopago.com/v1/payments/123456789' => Http::response([
            'id' => 123456789, 'status' => 'approved', 'transaction_amount' => 299.9, 'transaction_amount_refunded' => 30.0, 'external_reference' => null,
        ])]);

        $n = app(MercadoPagoGateway::class)->paymentStatusEvent('123456789');

        expect($n->eventType)->toBe('partially_refunded')
            ->and($n->metadata['refunded_total'])->toBe(30.0);

        expect(app(ProcessWebhookEventService::class)->applyNormalized($n))->toBe('partially_refunded')
            ->and((float) $payment->fresh()->refunded_amount)->toBe(30.0);
    });

    it('pacote de IA: tira os créditos proporcionais (arredondado para baixo), sem saldo negativo; falta de saldo vira alerta', function () {
        $purchases = app(AiCreditPurchaseService::class);
        $purchase  = $purchases->createPendingPurchase(entityId: (string) $this->clinic->id, packageCode: 'operational', source: 'checkout');
        $invoice   = Invoice::query()->create([
            'entity_id'           => $this->clinic->id,
            'gateway_code'        => 'asaas',
            'reference'           => 'IA-20261005-PACK',
            'external_invoice_id' => 'pay_pack',
            'due_at'              => '2026-10-08 23:59:59',
            'amount'              => 249.90,
            'currency'            => 'BRL',
            'status'              => InvoiceStatus::Pending->value,
            'billing_reason'      => Invoice::BILLING_REASON_AI_CREDIT_PACK,
            'metadata'            => ['ai_credit_purchase_id' => (string) $purchase->id, 'credits' => 100],
        ]);
        $purchase->update(['invoice_id' => $invoice->id]);
        Http::fake();

        refWebhook(['event' => 'PAYMENT_CONFIRMED', 'payment' => refAsaasPayment('pay_pack', ['subscription' => null, 'value' => 249.9, 'externalReference' => (string) $invoice->id])]);
        $wallet = fn () => (int) AiCreditWallet::query()->where('entity_id', $this->clinic->id)->value('balance');
        expect($wallet())->toBe(100);

        // 30% devolvido → 30 créditos.
        refWebhook(['event' => 'PAYMENT_PARTIALLY_REFUNDED', 'payment' => refAsaasPayment('pay_pack', ['subscription' => null, 'value' => 249.9, 'refunds' => [['status' => 'DONE', 'value' => 74.97]]])]);
        expect($wallet())->toBe(70)
            ->and(AiCreditPurchase::query()->sole()->status)->toBe(AiCreditPurchaseStatus::Credited)
            ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
            ->and((float) $invoice->fresh()->refunded_amount)->toBe(74.97);

        // Reentrega do mesmo total: nada muda.
        refWebhook(['event' => 'PAYMENT_PARTIALLY_REFUNDED', 'payment' => refAsaasPayment('pay_pack', ['subscription' => null, 'value' => 249.9, 'refunds' => [['status' => 'DONE', 'value' => 74.97]]])]);
        expect($wallet())->toBe(70);

        // A clínica usou quase tudo: 50% devolvido pede mais 20, só há 5.
        AiCreditWallet::query()->where('entity_id', $this->clinic->id)->update(['balance' => 5]);
        refWebhook(['event' => 'PAYMENT_PARTIALLY_REFUNDED', 'payment' => refAsaasPayment('pay_pack', ['subscription' => null, 'value' => 249.9, 'refunds' => [['status' => 'DONE', 'value' => 74.97], ['status' => 'DONE', 'value' => 49.98]]])]);

        expect($wallet())->toBe(0)
            ->and(data_get(AiCreditPurchase::query()->sole()->metadata, 'partial_refund'))->toMatchArray(['handled_credits' => 50, 'revoked_credits' => 35, 'shortfall' => 15]);
        Notification::assertSentTo($this->admin, GatewayOperationalAlertNotification::class, fn ($n) => $n->kind === 'refund_credits' && (int) $n->params['shortfall'] === 15);
    });
});
