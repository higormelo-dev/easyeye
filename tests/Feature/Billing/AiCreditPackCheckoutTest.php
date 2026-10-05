<?php

declare(strict_types=1);

use App\Domains\AI\Models\AiCreditPurchase;
use App\Domains\AI\Services\{AiCreditPurchaseService, AiCreditWalletService};
use App\Enums\AI\AiCreditPurchaseStatus;
use App\Enums\Billing\{InvoiceStatus, PaymentStatus};
use App\Enums\{BillingCycle, ClientRule, FeatureKey, SubscriptionBillingMode, SubscriptionStatus};
use App\Events\Billing\InvoicePaid;
use App\Models\Billing\{BillingLog, FinancialEvent};
use App\Models\Billing\{Invoice, Payment, WebhookEvent};
use App\Models\{Entity, Plan, PlanFeature, PlanPrice, Subscription, User};
use App\Services\Billing\ProcessWebhookEventService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\{Factory, Request};
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{Cache, Event, Http, Queue};

/**
 * Pacote de créditos de IA pago no checkout (Pix, boleto, cartão à vista):
 * pedido + fatura sem assinatura (ai_credit_pack); confirmação (cartão
 * aprovado ou webhook) credita a carteira uma vez só; estorno/chargeback
 * revertem; só contato de cobrança com acesso total compra.
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
    ]);

    $this->plan = Plan::factory()->create(['name' => 'Pro', 'billing_cycle' => 'monthly', 'price' => 299.90, 'active' => true]);
    PlanPrice::create(['plan_id' => $this->plan->id, 'billing_cycle' => 'monthly', 'price' => 299.90]);
    PlanFeature::factory()->enabled(FeatureKey::HasAiChatAssistant)->for($this->plan)->create();

    $this->clinic                = Entity::factory()->make(['is_client' => true, 'active' => true, 'name' => 'Clínica Olhar Ltda', 'email' => 'financeiro@olhar.test', 'national_registration' => '11222333000181']);
    $this->clinic->skipAutoTrial = true;
    $this->clinic->save();

    $this->user   = User::factory()->create();
    $this->member = createEntityUser($this->clinic, $this->user, ClientRule::Admin->value, isOwner: true);
});

afterEach(fn () => Carbon::setTestNow());

function packAs(?User $user = null, $member = null): mixed
{
    return test()->actingAs($user ?? test()->user)->withSession(panelSession($member ?? test()->member));
}

/** Cliente pagante no Mercado Pago, em dia (acesso total). */
function packPaidSubscription(array $attributes = []): Subscription
{
    return Subscription::factory()->gateway('mercadopago')->for(test()->clinic)->for(test()->plan)->create([
        'gateway_subscription_id' => null,
        'gateway_customer_id'     => '1234567-cus',
        'billing_cycle'           => BillingCycle::Monthly,
        'status'                  => SubscriptionStatus::Active,
        'billing_state'           => 'paid',
        'amount'                  => 299.90,
        'last_payment_at'         => '2026-10-01 10:00:00',
        'starts_at'               => '2026-09-01 10:00:00',
        'ends_at'                 => '2026-11-01 23:59:59',
        'next_billing_at'         => '2026-11-01 23:59:59',
        ...$attributes,
    ]);
}

function packPixResponse(int $id = 901, float $amount = 249.90): array
{
    return [
        'id'                   => $id,
        'status'               => 'pending',
        'payment_method_id'    => 'pix',
        'transaction_amount'   => $amount,
        'date_of_expiration'   => '2026-10-08T23:59:59.000-03:00',
        'point_of_interaction' => ['transaction_data' => ['qr_code' => "00020101pix-{$id}", 'qr_code_base64' => 'iVBOR=', 'ticket_url' => "https://www.mercadopago.com.br/payments/{$id}/ticket?caller_id=1"]],
    ];
}

function packWebhook(string $paymentId, string $eventId): WebhookEvent
{
    return WebhookEvent::query()->create([
        'gateway_code'      => 'mercadopago',
        'external_event_id' => $eventId,
        'payload'           => ['type' => 'payment', 'action' => 'payment.updated', 'data' => ['id' => $paymentId]],
        'headers'           => [],
        'status'            => 'received',
        'received_at'       => now(),
        'event_hash'        => hash('sha256', $eventId),
    ]);
}

function packBalance(): int
{
    return (int) app(AiCreditWalletService::class)->balance((string) test()->clinic->id)['balance'];
}

describe('compra no Pix e confirmação pelo webhook', function () {
    it('abre pedido + fatura sem assinatura, devolve o QR; o webhook credita uma vez só e avisa em tempo real', function () {
        Event::fake([InvoicePaid::class]);
        $subscription = packPaidSubscription();

        Http::fake(['https://api.mercadopago.com/v1/payments' => Http::response(packPixResponse(), 201)]);

        $response = packAs()->postJson(route('panel.my-subscription.ai-credits.purchase'), ['package_code' => 'operational', 'method' => 'pix'])
            ->assertOk()
            ->assertJsonPath('data.mode', 'transparent')
            ->assertJsonPath('data.instructions.pix.copy_paste', '00020101pix-901')
            ->assertJsonPath('data.invoice.kind', 'ai_credit_pack')
            ->assertJsonPath('data.invoice.ai_credit_pack.credits', 100)
            ->assertJsonPath('data.purchase.status', 'pending_payment');

        $invoice  = Invoice::query()->findOrFail($response->json('data.invoice.id'));
        $purchase = AiCreditPurchase::query()->where('invoice_id', $invoice->id)->firstOrFail();

        expect($invoice->billing_reason)->toBe(Invoice::BILLING_REASON_AI_CREDIT_PACK)
            ->and($invoice->subscription_id)->toBeNull()
            ->and((float) $invoice->amount)->toBe(249.90)
            ->and($invoice->external_invoice_id)->toBe('901')
            ->and($purchase->metadata['source'])->toBe('checkout')
            ->and($purchase->subscription_id)->toBe($subscription->id)
            ->and(packBalance())->toBe(0);

        // Mesmo cliente do gateway da assinatura; referência = a fatura do pacote.
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.mercadopago.com/v1/payments'
            && $r['payment_method_id'] === 'pix'
            && $r['external_reference'] === $invoice->id
            && (float) $r['transaction_amount'] === 249.90);

        Http::fake(['https://api.mercadopago.com/v1/payments/901' => Http::response(['id' => 901, 'status' => 'approved', 'transaction_amount' => 249.90, 'external_reference' => $invoice->id])]);

        $event = packWebhook('901', 'evt-901-approved');
        app(ProcessWebhookEventService::class)->process($event);

        expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
            ->and($purchase->fresh()->status)->toBe(AiCreditPurchaseStatus::Credited)
            ->and(packBalance())->toBe(100)
            ->and($event->fresh()->normalized_payload['outcome'])->toBe('ai_credits_granted')
            ->and($event->fresh()->entity_id)->toBe($this->clinic->id)
            ->and(Payment::query()->where('external_payment_id', '901')->value('status'))->toBe(PaymentStatus::Paid)
            // a assinatura não muda
            ->and($subscription->fresh()->ends_at->toDateString())->toBe('2026-11-01')
            ->and($subscription->fresh()->current_invoice_id)->toBe($subscription->current_invoice_id);

        Event::assertDispatched(InvoicePaid::class, fn (InvoicePaid $e) => $e->invoiceId === $invoice->id && $e->subscriptionId === null);

        // Reentrega (outro evento do mesmo pagamento): nada credita de novo.
        $again = packWebhook('901', 'evt-901-approved-2');
        app(ProcessWebhookEventService::class)->process($again);

        expect(packBalance())->toBe(100)
            ->and($again->fresh()->normalized_payload['outcome'])->toBe('duplicate');
    });

    it('reabrir o checkout do mesmo pacote reaproveita o pedido e a fatura em aberto (sem nova cobrança)', function () {
        packPaidSubscription();
        Http::fake(['https://api.mercadopago.com/v1/payments' => Http::response(packPixResponse(), 201)]);

        $first  = packAs()->postJson(route('panel.my-subscription.ai-credits.purchase'), ['package_code' => 'operational', 'method' => 'pix'])->assertOk();
        $second = packAs()->postJson(route('panel.my-subscription.ai-credits.purchase'), ['package_code' => 'operational', 'method' => 'pix'])->assertOk();

        expect($second->json('data.invoice.id'))->toBe($first->json('data.invoice.id'))
            ->and(AiCreditPurchase::query()->where('entity_id', $this->clinic->id)->count())->toBe(1);

        Http::assertSentCount(1);
    });

    it('Minha assinatura: o pedido do pacote aparece no histórico e à parte (open_ai_packs, não como dívida da assinatura), com as formas de pagamento do gateway', function () {
        packPaidSubscription();
        Http::fake(['https://api.mercadopago.com/v1/payments' => Http::response(packPixResponse(), 201)]);

        $invoiceId = packAs()->postJson(route('panel.my-subscription.ai-credits.purchase'), ['package_code' => 'starter', 'method' => 'pix'])->json('data.invoice.id');

        packAs()->getJson(route('panel.my-subscription.summary'))
            ->assertOk()
            ->assertJsonCount(0, 'data.open_invoices')
            ->assertJsonPath('data.open_ai_packs.0.id', $invoiceId)
            ->assertJsonPath('data.open_ai_packs.0.kind', 'ai_credit_pack')
            ->assertJsonPath('data.open_ai_packs.0.can_discard', true)
            ->assertJsonPath('data.open_ai_packs.0.payment.gateway', 'mercadopago')
            ->assertJsonPath('data.open_ai_packs.0.payment.card.max_installments', 1)
            ->assertJsonPath('data.invoices.0.ai_credit_pack.credits', 25);

        // Pagar pela fatura aberta (GET só lê: as instruções guardadas).
        packAs()->getJson(route('panel.my-subscription.instructions', ['invoice' => $invoiceId, 'method' => 'pix']))
            ->assertOk()
            ->assertJsonPath('data.instructions.pix.copy_paste', '00020101pix-901');

        Http::assertSentCount(1);
    });
});

describe('cartão à vista', function () {
    it('aprovado: credita na hora, sem guardar o cartão nem mexer no cartão da assinatura', function () {
        Event::fake([InvoicePaid::class]);
        $subscription = packPaidSubscription(['gateway_card_id' => 'card-old', 'card_last4' => '1111', 'payment_method' => 'credit_card']);

        Http::fake(['https://api.mercadopago.com/v1/payments' => Http::response(['id' => 902, 'status' => 'approved', 'status_detail' => 'accredited', 'transaction_amount' => 69.90, 'payment_method_id' => 'visa'], 201)]);

        packAs()->postJson(route('panel.my-subscription.ai-credits.purchase'), [
            'package_code'      => 'starter',
            'method'            => 'credit_card',
            'card_token'        => 'ff8080814c11e237014c1ff593b57b4d',
            'payment_method_id' => 'visa',
        ])->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.invoice.status', 'paid');

        expect(packBalance())->toBe(25)
            ->and(AiCreditPurchase::query()->where('entity_id', $this->clinic->id)->value('status'))->toBe(AiCreditPurchaseStatus::Credited)
            ->and($subscription->fresh()->gateway_card_id)->toBe('card-old');

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.mercadopago.com/v1/payments' && $r['installments'] === 1 && ! isset($r['point_of_interaction']));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/cards'));
        Event::assertDispatched(InvoicePaid::class);
    });

    it('parcelado: 422 installments_invalid sem chamar o gateway', function () {
        packPaidSubscription();

        packAs()->postJson(route('panel.my-subscription.ai-credits.purchase'), [
            'package_code' => 'scale',
            'method'       => 'credit_card',
            'card_token'   => 'tok',
            'installments' => 3,
        ])->assertStatus(422)->assertJsonPath('code', 'installments_invalid');

        Http::assertNothingSent();
        expect(AiCreditPurchase::query()->count())->toBe(0);
    });
});

describe('quem pode comprar', function () {
    it('acesso limitado (atraso na régua): 403 e opções com o motivo; nada é criado', function () {
        packPaidSubscription([
            'status'      => SubscriptionStatus::PastDue,
            'ends_at'     => '2026-09-30 23:59:59',
            'past_due_at' => '2026-09-30 23:59:59',
        ]);

        packAs()->getJson(route('panel.my-subscription.ai-credits.options'))
            ->assertOk()
            ->assertJsonPath('data.allowed', false)
            ->assertJsonPath('data.reason', 'ai_pack_requires_full_access');

        packAs()->postJson(route('panel.my-subscription.ai-credits.purchase'), ['package_code' => 'starter', 'method' => 'pix'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'ai_pack_requires_full_access');

        Http::assertNothingSent();
        expect(AiCreditPurchase::query()->count())->toBe(0);
    });

    it('cortesia (sem franquia) compra pelo gateway padrão do SaaS; financeiro compra; secretária não', function () {
        Subscription::factory()->for($this->clinic)->for($this->plan)->create([
            'status'       => SubscriptionStatus::Active,
            'billing_mode' => SubscriptionBillingMode::Complimentary,
            'gateway'      => null,
            'starts_at'    => now()->subMonth(),
            'ends_at'      => now()->addMonth(),
        ]);

        $financial = User::factory()->create();
        $finMember = createEntityUser($this->clinic, $financial, ClientRule::Financial->value);

        Http::fake([
            'https://api.mercadopago.com/v1/customers/search*' => Http::response(['results' => [['id' => '777-cus']]]),
            'https://api.mercadopago.com/v1/payments'          => Http::response(packPixResponse(903, 69.90), 201),
        ]);

        packAs($financial, $finMember)->getJson(route('panel.my-subscription.ai-credits.options', ['package_code' => 'starter']))
            ->assertOk()
            ->assertJsonPath('data.allowed', true)
            ->assertJsonPath('data.payment.gateway', 'mercadopago')
            ->assertJsonPath('data.payment.card.installments.0.amount', 69.9);

        packAs($financial, $finMember)->postJson(route('panel.my-subscription.ai-credits.purchase'), ['package_code' => 'starter', 'method' => 'pix'])
            ->assertOk()
            ->assertJsonPath('data.instructions.pix.copy_paste', '00020101pix-903');

        $secretary = User::factory()->create();
        $secMember = createEntityUser($this->clinic, $secretary, ClientRule::Secretary->value);

        packAs($secretary, $secMember)->postJson(route('panel.my-subscription.ai-credits.purchase'), ['package_code' => 'starter', 'method' => 'pix'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');
    });
});

describe('estorno, chargeback e pedido cancelado pelo manager', function () {
    /** Pacote pago pelo webhook; a próxima consulta do pagamento 901 devolve $nextStatus. */
    function packPaidByWebhook(string $nextStatus): array
    {
        packPaidSubscription();
        Http::fake(['https://api.mercadopago.com/v1/payments' => Http::response(packPixResponse(), 201)]);
        $invoiceId = packAs()->postJson(route('panel.my-subscription.ai-credits.purchase'), ['package_code' => 'operational', 'method' => 'pix'])->json('data.invoice.id');

        Http::fake(['https://api.mercadopago.com/v1/payments/901' => Http::sequence()
            ->push(['id' => 901, 'status' => 'approved', 'transaction_amount' => 249.90, 'external_reference' => $invoiceId])
            ->push(['id' => 901, 'status' => $nextStatus, 'transaction_amount' => 249.90, 'external_reference' => $invoiceId])]);
        app(ProcessWebhookEventService::class)->process(packWebhook('901', 'evt-pay'));

        return [Invoice::query()->findOrFail($invoiceId), AiCreditPurchase::query()->where('invoice_id', $invoiceId)->firstOrFail()];
    }

    it('estorno no gateway: créditos revertidos (regra do estorno do manager)', function () {
        [$invoice, $purchase] = packPaidByWebhook('refunded');
        expect(packBalance())->toBe(100);

        $event = packWebhook('901', 'evt-refund');
        app(ProcessWebhookEventService::class)->process($event);

        expect($purchase->fresh()->status)->toBe(AiCreditPurchaseStatus::Refunded)
            ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Refunded)
            ->and(packBalance())->toBe(0)
            ->and($event->fresh()->normalized_payload['outcome'])->toBe('ai_credits_refunded');
    });

    it('chargeback: créditos revertidos mesmo já consumidos (regra do manager: o saldo comprado vai a zero)', function () {
        [, $purchase] = packPaidByWebhook('charged_back');
        app(AiCreditWalletService::class)->reserve((string) $this->clinic->id, 30);
        app(AiCreditWalletService::class)->consumeReservation((string) $this->clinic->id, 30);

        $event = packWebhook('901', 'evt-chargeback');
        app(ProcessWebhookEventService::class)->process($event);

        expect($purchase->fresh()->status)->toBe(AiCreditPurchaseStatus::Refunded)
            ->and(packBalance())->toBe(0)
            ->and($event->fresh()->normalized_payload['outcome'])->toBe('ai_credits_chargeback');
    });

    it('pedido cancelado pelo manager e depois pago: o dinheiro entrou — reabre e credita', function () {
        packPaidSubscription();
        Http::fake(['https://api.mercadopago.com/v1/payments' => Http::response(packPixResponse(), 201)]);
        $invoiceId = packAs()->postJson(route('panel.my-subscription.ai-credits.purchase'), ['package_code' => 'operational', 'method' => 'pix'])->json('data.invoice.id');
        $purchase  = AiCreditPurchase::query()->where('invoice_id', $invoiceId)->firstOrFail();

        app(AiCreditPurchaseService::class)->cancelPurchase($purchase, 'cliente desistiu');

        // Cancelado: a fatura não aceita mais pagamento pelo checkout…
        packAs()->postJson(route('panel.my-subscription.charge', ['invoice' => $invoiceId]), ['method' => 'boleto'])
            ->assertStatus(409)->assertJsonPath('code', 'invoice_not_payable');

        // …mas o Pix já emitido foi pago.
        Http::fake(['https://api.mercadopago.com/v1/payments/901' => Http::response(['id' => 901, 'status' => 'approved', 'transaction_amount' => 249.90, 'external_reference' => $invoiceId])]);
        app(ProcessWebhookEventService::class)->process(packWebhook('901', 'evt-late'));

        expect($purchase->fresh()->status)->toBe(AiCreditPurchaseStatus::Credited)
            ->and($purchase->fresh()->metadata['reopened_by_payment']['previous_status'])->toBe('cancelled')
            ->and(packBalance())->toBe(100);
    });
});

// ── Revisão (rodada 4): estorno da cobrança certa, duplicidade e reentrega ─────

/** Webhook do Mercado Pago com fakes novos (o primeiro stub que casa a URL venceria). */
function packHook(string $paymentId, string $status, string $invoiceId, string $eventId): WebhookEvent
{
    Http::swap(new Factory());
    Http::preventStrayRequests();
    Http::fake([
        "https://api.mercadopago.com/v1/payments/{$paymentId}" => Http::response(['id' => (int) $paymentId, 'status' => $status, 'transaction_amount' => 249.90, 'external_reference' => $invoiceId]),
        // cancelamento das outras cobranças depois do pagamento
        'https://api.mercadopago.com/v1/payments/*' => Http::response(['status' => 'cancelled']),
    ]);

    $event = packWebhook($paymentId, $eventId);
    app(ProcessWebhookEventService::class)->process($event);

    return $event->fresh();
}

/** Pix 901 e depois boleto 903 para o mesmo pacote (o Pix fica desligado, mas vale). */
function packPixThenBoleto(): Invoice
{
    packPaidSubscription();
    Http::fake(['https://api.mercadopago.com/v1/payments' => Http::sequence()
        ->push(packPixResponse(901), 201)
        ->push(['id'              => 903, 'status' => 'pending', 'payment_method_id' => 'bolbradesco', 'transaction_amount' => 249.90, 'date_of_expiration' => '2026-10-08T23:59:59.000-03:00',
            'transaction_details' => ['external_resource_url' => 'https://www.mercadopago.com.br/payments/903/ticket', 'digitable_line' => '2379338128600820000000000000000019789000002999']], 201),
    ]);

    $id = packAs()->postJson(route('panel.my-subscription.ai-credits.purchase'), ['package_code' => 'operational', 'method' => 'pix'])->assertOk()->json('data.invoice.id');
    packAs()->postJson(route('panel.my-subscription.charge', ['invoice' => $id]), ['method' => 'boleto'])->assertOk();

    $invoice = Invoice::query()->findOrFail($id);
    expect($invoice->external_invoice_id)->toBe('903')
        ->and($invoice->metadata['detached_charges'])->toBe(['901']);

    return $invoice;
}

describe('revisão: estorno e chargeback da cobrança certa', function () {
    it('pagou no Pix desligado: estorno e chargeback DESSE Pix revertem os créditos (não é "cobrança substituída")', function () {
        $invoice = packPixThenBoleto();

        expect(packHook('901', 'approved', $invoice->id, 'evt-901-paid')->normalized_payload['outcome'])->toBe('ai_credits_granted')
            ->and(packBalance())->toBe(100)
            ->and($invoice->fresh()->metadata['paid_by_charge'])->toBe('901');

        $refund = packHook('901', 'refunded', $invoice->id, 'evt-901-refund');

        expect($refund->normalized_payload['outcome'])->toBe('ai_credits_refunded')
            ->and(packBalance())->toBe(0)
            ->and(AiCreditPurchase::query()->where('invoice_id', $invoice->id)->value('status'))->toBe(AiCreditPurchaseStatus::Refunded)
            ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Refunded)
            ->and(Payment::query()->where('external_payment_id', '901')->value('status'))->toBe(PaymentStatus::Refunded);
    });

    it('chargeback do Pix desligado que pagou: créditos revertidos', function () {
        $invoice = packPixThenBoleto();
        packHook('901', 'approved', $invoice->id, 'evt-901-paid');

        expect(packHook('901', 'charged_back', $invoice->id, 'evt-901-cb')->normalized_payload['outcome'])->toBe('ai_credits_chargeback')
            ->and(packBalance())->toBe(0);
    });

    it('estorno do pagamento DUPLICADO (boleto pago depois do Pix): créditos, pedido e fatura ficam; só o Payment do boleto muda', function () {
        $invoice = packPixThenBoleto();
        packHook('901', 'approved', $invoice->id, 'evt-901-paid');

        expect(packHook('903', 'approved', $invoice->id, 'evt-903-paid')->normalized_payload['outcome'])->toBe('alert_invoice_already_paid');

        $refund = packHook('903', 'refunded', $invoice->id, 'evt-903-refund');

        expect($refund->normalized_payload['outcome'])->toBe('ai_pack_duplicate_refunded')
            ->and(packBalance())->toBe(100)
            ->and(AiCreditPurchase::query()->where('invoice_id', $invoice->id)->value('status'))->toBe(AiCreditPurchaseStatus::Credited)
            ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
            ->and(Payment::query()->where('external_payment_id', '901')->value('status'))->toBe(PaymentStatus::Paid)
            ->and(Payment::query()->where('external_payment_id', '903')->value('status'))->toBe(PaymentStatus::Refunded)
            ->and(FinancialEvent::query()->where('invoice_id', $invoice->id)->where('event_type', 'payment_refunded')->first()?->metadata['duplicate_payment'] ?? null)->toBeTrue();

        // O estorno do Pix (o que valeu) ainda reverte.
        expect(packHook('901', 'refunded', $invoice->id, 'evt-901-refund')->normalized_payload['outcome'])->toBe('ai_credits_refunded')
            ->and(packBalance())->toBe(0);
    });

    it('reentrega do estorno depois da retenção de webhook_events: sem novo evento financeiro nem log', function () {
        [$invoice] = packPaidByWebhook('refunded');
        $refund    = packWebhook('901', 'evt-refund');
        app(ProcessWebhookEventService::class)->process($refund);
        expect($refund->fresh()->normalized_payload['outcome'])->toBe('ai_credits_refunded');

        $events = FinancialEvent::query()->where('invoice_id', $invoice->id)->count();
        $logs   = BillingLog::query()->where('invoice_id', $invoice->id)->count();

        WebhookEvent::query()->delete();
        $again = packHook('901', 'refunded', $invoice->id, 'evt-refund');

        expect($again->normalized_payload['outcome'])->toBe('duplicate')
            ->and(FinancialEvent::query()->where('invoice_id', $invoice->id)->count())->toBe($events)
            // só o "Webhook processado."
            ->and(BillingLog::query()->where('invoice_id', $invoice->id)->count())->toBe($logs + 1);
    });

    it('cartão aprovado × webhook da MESMA cobrança antes da resposta: sem alerta crítico de estorno', function () {
        packPaidSubscription();

        Http::fake([
            'https://api.mercadopago.com/v1/payments/902' => function (Request $request) {
                return Http::response(['id' => 902, 'status' => 'approved', 'transaction_amount' => 69.90, 'external_reference' => Invoice::query()->value('id')]);
            },
            'https://api.mercadopago.com/v1/payments' => function (Request $request) {
                // O webhook do 902 é processado antes de o gateway responder.
                $event = packWebhook('902', 'evt-902-race');
                app(ProcessWebhookEventService::class)->process($event);
                expect($event->fresh()->normalized_payload['outcome'])->toBe('ai_credits_granted');

                return Http::response(['id' => 902, 'status' => 'approved', 'status_detail' => 'accredited', 'transaction_amount' => 69.90, 'payment_method_id' => 'visa'], 201);
            },
        ]);

        packAs()->postJson(route('panel.my-subscription.ai-credits.purchase'), [
            'package_code'      => 'starter',
            'method'            => 'credit_card',
            'card_token'        => 'ff8080814c11e237014c1ff593b57b4d',
            'payment_method_id' => 'visa',
        ])->assertOk()->assertJsonPath('data.status', 'paid');

        expect(packBalance())->toBe(25)
            ->and(BillingLog::query()->where('level', 'critical')->count())->toBe(0)
            ->and(BillingLog::query()->where('message', 'like', '%estornar%')->count())->toBe(0);
    });
});

describe('revisão: pedido de pacote abandonado (descartar e expirar)', function () {
    /** Pedido de pacote em Pix (cobrança 901), não pago. */
    function packOpenOrder(string $package = 'operational', int $chargeId = 901): Invoice
    {
        Http::swap(new Factory());
        Http::preventStrayRequests();
        Http::fake(['https://api.mercadopago.com/v1/payments' => Http::response(packPixResponse($chargeId), 201)]);
        $id = packAs()->postJson(route('panel.my-subscription.ai-credits.purchase'), ['package_code' => $package, 'method' => 'pix'])->assertOk()->json('data.invoice.id');

        return Invoice::query()->findOrFail($id);
    }

    it('a clínica descarta o pedido não pago: pedido e fatura cancelados, Pix cancelado no gateway, some de "em aberto"', function () {
        packPaidSubscription();
        $invoice = packOpenOrder();

        Http::swap(new Factory());
        Http::preventStrayRequests();
        Http::fake(['https://api.mercadopago.com/v1/payments/901' => Http::response(['id' => 901, 'status' => 'cancelled'])]);

        packAs()->deleteJson(route('panel.my-subscription.ai-credits.discard', ['invoice' => $invoice->id]))
            ->assertOk()
            ->assertJsonPath('data.invoice.status', 'cancelled')
            ->assertJsonPath('data.invoice.can_pay', false)
            ->assertJsonPath('message', __('checkout.page.ai_pack_discarded'));

        expect($invoice->fresh()->status)->toBe(InvoiceStatus::Cancelled)
            ->and($invoice->fresh()->metadata['discarded']['reason'])->toBe('clinic')
            ->and($invoice->fresh()->metadata['discarded']['by'])->toBe($this->user->id)
            ->and(AiCreditPurchase::query()->where('invoice_id', $invoice->id)->value('status'))->toBe(AiCreditPurchaseStatus::Cancelled)
            ->and(Payment::query()->where('external_payment_id', '901')->value('status'))->toBe(PaymentStatus::Cancelled);

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/v1/payments/901') && $r['status'] === 'cancelled');

        packAs()->getJson(route('panel.my-subscription.summary'))
            ->assertOk()
            ->assertJsonCount(0, 'data.open_ai_packs')
            ->assertJsonCount(0, 'data.open_invoices');

        // Descartar de novo: 409.
        packAs()->deleteJson(route('panel.my-subscription.ai-credits.discard', ['invoice' => $invoice->id]))
            ->assertStatus(409)->assertJsonPath('code', 'ai_pack_not_discardable');
    });

    it('descartado, mas o Pix foi pago mesmo assim (cancelamento falhou no gateway): credita — o cliente pagou; vencimento/cancelamento do Pix não reabre', function () {
        packPaidSubscription();
        $invoice = packOpenOrder();

        Http::swap(new Factory());
        Http::preventStrayRequests();
        Http::fake(['https://api.mercadopago.com/v1/payments/901' => Http::response(['message' => 'cannot cancel'], 400)]);

        packAs()->deleteJson(route('panel.my-subscription.ai-credits.discard', ['invoice' => $invoice->id]))->assertOk();

        expect(BillingLog::query()->where('invoice_id', $invoice->id)->where('level', 'warning')->where('message', 'like', '%não pôde ser cancelada%')->exists())->toBeTrue();

        // Evento de cancelamento/expiração do Pix: ignorado (não reabre como "falhou/vencida").
        expect(packHook('901', 'cancelled', $invoice->id, 'evt-901-cancelled')->normalized_payload['outcome'])->toBe('ignored_discarded_order')
            ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Cancelled);

        // Pagamento tardio: credita.
        expect(packHook('901', 'approved', $invoice->id, 'evt-901-late')->normalized_payload['outcome'])->toBe('ai_credits_granted')
            ->and(packBalance())->toBe(100)
            ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
            ->and(AiCreditPurchase::query()->where('invoice_id', $invoice->id)->value('status'))->toBe(AiCreditPurchaseStatus::Credited);
    });

    it('descartar: só a própria clínica (outra clínica = 404), só contato de cobrança e só o não pago', function () {
        packPaidSubscription();
        $invoice = packOpenOrder();

        $other                = Entity::factory()->make(['is_client' => true, 'active' => true]);
        $other->skipAutoTrial = true;
        $other->save();
        $otherUser   = User::factory()->create();
        $otherMember = createEntityUser($other, $otherUser, ClientRule::Admin->value, isOwner: true);

        packAs($otherUser, $otherMember)->deleteJson(route('panel.my-subscription.ai-credits.discard', ['invoice' => $invoice->id]))->assertNotFound();

        $secretary = User::factory()->create();
        $secMember = createEntityUser($this->clinic, $secretary, ClientRule::Secretary->value);
        packAs($secretary, $secMember)->deleteJson(route('panel.my-subscription.ai-credits.discard', ['invoice' => $invoice->id]))->assertForbidden();

        expect($invoice->fresh()->status)->toBe(InvoiceStatus::Pending);

        // Pago: não descarta.
        packHook('901', 'approved', $invoice->id, 'evt-901-paid');
        packAs()->deleteJson(route('panel.my-subscription.ai-credits.discard', ['invoice' => $invoice->id]))
            ->assertStatus(409)->assertJsonPath('code', 'ai_pack_not_discardable');
        expect(packBalance())->toBe(100);
    });

    it('ai:expire-credit-pack-orders descarta só os pendentes há mais de N dias sem nova cobrança (com --dry-run); pedido manual sem fatura fica', function () {
        packPaidSubscription();

        // Antigo e abandonado (9 dias).
        Carbon::setTestNow(CarbonImmutable::parse('2026-09-26 10:00:00'));
        $old = packOpenOrder('starter');

        // Antigo, mas o cliente emitiu um boleto ontem: ainda vale.
        $active = packOpenOrder('scale', 904);
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-04 10:00:00'));
        Http::swap(new Factory());
        Http::preventStrayRequests();
        Http::fake(['https://api.mercadopago.com/v1/payments' => Http::response(['id' => 905, 'status' => 'pending', 'payment_method_id' => 'bolbradesco', 'transaction_amount' => 599.90,
            'transaction_details'                                                     => ['external_resource_url' => 'https://www.mercadopago.com.br/payments/905/ticket', 'digitable_line' => '23793381286']], 201)]);
        packAs()->postJson(route('panel.my-subscription.charge', ['invoice' => $active->id]), ['method' => 'boleto'])->assertOk();

        // Recente (3 dias).
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-02 10:00:00'));
        $recent = packOpenOrder('operational', 906);

        // Pedido manual antigo (sem fatura): é do manager.
        Carbon::setTestNow(CarbonImmutable::parse('2026-09-01 10:00:00'));
        $manual = app(AiCreditPurchaseService::class)->createPendingPurchase((string) $this->clinic->id, 'starter');

        Carbon::setTestNow(CarbonImmutable::parse('2026-10-05 10:00:00'));
        Http::swap(new Factory());
        Http::preventStrayRequests();
        Http::fake(['https://api.mercadopago.com/v1/payments/*' => Http::response(['status' => 'cancelled'])]);

        $this->artisan('ai:expire-credit-pack-orders', ['--dry-run' => true])
            ->expectsOutputToContain('seriam descartados: 1')
            ->assertSuccessful();
        expect($old->fresh()->status)->toBe(InvoiceStatus::Pending);
        Http::assertNothingSent();

        $this->artisan('ai:expire-credit-pack-orders')->expectsOutputToContain('descartados: 1 de 1')->assertSuccessful();

        expect($old->fresh()->status)->toBe(InvoiceStatus::Cancelled)
            ->and($old->fresh()->metadata['discarded']['reason'])->toBe('expired')
            ->and(AiCreditPurchase::query()->where('invoice_id', $old->id)->value('status'))->toBe(AiCreditPurchaseStatus::Cancelled)
            ->and($active->fresh()->status)->toBe(InvoiceStatus::Pending)
            ->and($recent->fresh()->status)->toBe(InvoiceStatus::Pending)
            ->and($manual->fresh()->status)->toBe(AiCreditPurchaseStatus::PendingPayment);

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/v1/payments/901'));

        // Prazo menor (AI_CREDIT_PACK_PENDING_EXPIRY_DAYS=2): o recente vence; o com boleto de ontem, não.
        config(['ai.credit_purchases.pending_expiry_days' => 2]);
        $this->artisan('ai:expire-credit-pack-orders')->expectsOutputToContain('descartados: 1 de 1')->assertSuccessful();
        expect($recent->fresh()->status)->toBe(InvoiceStatus::Cancelled)
            ->and($active->fresh()->status)->toBe(InvoiceStatus::Pending);
    });
});
