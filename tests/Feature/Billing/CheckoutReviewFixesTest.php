<?php

declare(strict_types=1);

use App\DTOs\Billing\{CardChargeDTO, CustomerDTO};
use App\Enums\Billing\{DunningStep, InvoiceStatus, PaymentStatus};
use App\Enums\{BillingCycle, ClientRule, SaasRule, SubscriptionStatus};
use App\Http\Middleware\CheckoutContentSecurityPolicy;
use App\Jobs\Billing\RenewSubscriptionJob;
use App\Models\Billing\{BillingLog, Gateway, Invoice, Payment, PaymentAttempt, SubscriptionChange, WebhookEvent};
use App\Models\{Entity, Plan, PlanPrice, Subscription, User};
use App\Notifications\SubscriptionDunningNotification;
use App\Services\Billing\{DunningService, GatewayRegistry, ProcessWebhookEventService, SubscriptionCycleService};
use App\Services\SubscriptionService;
use App\Support\Billing\PayloadSanitizer;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Registered;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Http\Client\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{Cache, DB, Event, Http, Notification, Queue};
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Correções da revisão do checkout transparente (uma seção por achado; cada
 * teste falha sem a correção):
 *  1 troca de plano de quem já paga (upgrade proporcional / downgrade agendado);
 *  2 D5 uma vez; 3 antifraude (cadastro, Turnstile, recusas de cartão);
 *  4 LGPD nos payloads; 5 cartão sem chave pública; 6 renovação à vista;
 *  7 trava/idempotência; 8 GET só lê; 9 chave pública por gateway;
 *  10 juros do Mercado Pago; 11 cobrança desligada paga; 12 régua × cartão;
 *  13 PagBank sem troca de cartão; 14 3DS; 15 referência do Automatic
 *  Payments; 16 CSP; 17 recusa traduzida; 18 403 em HTML.
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
        'billing.gateways.pagarme.base_url'       => 'https://api.pagar.me/core/v5',
        'billing.gateways.pagarme.secret'         => 'sk_test_easyeye',
        'billing.gateways.pagarme.public_key'     => 'pk_test_easyeye',
        'billing.gateways.stripe_br.base_url'     => 'https://api.stripe.com',
        'billing.gateways.stripe_br.secret'       => 'sk_test_easyeye',
        'billing.gateways.stripe_br.public_key'   => 'pk_test_easyeye',
        'billing.gateways.pagbank.base_url'       => 'https://sandbox.api.pagseguro.com',
        'billing.gateways.pagbank.secret'         => 'tok_api_pagbank',
        'billing.gateways.pagbank.public_key'     => 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAr+ZqgD892U9/HXsa7XqBZUayPquAfh9xx4iwUbTSUAvTlmiXFQNTp0Bvt/5vK2FhMj39qSv1zi2OuBjvW38q1E374nzx6NNBL5JosV0+SDINTlCG0cmigHuBOyWzYmjgca+mtQu4WczCaApNaSuVqgb8u7Bd9GCOL4YJotvV5+81frlSwQXralhwRzGhj/A57CGPgGKiuPT+AOGmykIGEZsSD9RKkyoKIoc0OS8CPIzdBOtTQCIwrLn2FxI83Clcg55W8gkFSOS6rWNbG5qFZWMll6yl02HtunalHmUlRUL66YeGXdMDC2PuRcmZbGO5a/2tbVppW6mfSWG3NPRpgwIDAQAB',
        'billing.checkout.lock_wait_seconds'      => 0,
    ]);

    $this->plan = Plan::factory()->create(['name' => 'Pro', 'billing_cycle' => 'monthly', 'price' => 299.90, 'active' => true]);
    PlanPrice::create(['plan_id' => $this->plan->id, 'billing_cycle' => 'monthly', 'price' => 299.90]);
    PlanPrice::create(['plan_id' => $this->plan->id, 'billing_cycle' => 'yearly', 'price' => 2878.80]);
    $this->plan = $this->plan->fresh('prices');

    $this->plus = Plan::factory()->create(['name' => 'Plus', 'billing_cycle' => 'monthly', 'price' => 499.90, 'active' => true]);
    PlanPrice::create(['plan_id' => $this->plus->id, 'billing_cycle' => 'monthly', 'price' => 499.90]);
    PlanPrice::create(['plan_id' => $this->plus->id, 'billing_cycle' => 'yearly', 'price' => 4798.80]);
    $this->plus = $this->plus->fresh('prices');

    $this->clinic                = Entity::factory()->make(['is_client' => true, 'active' => true, 'name' => 'Clínica Olhar Ltda', 'email' => 'financeiro@olhar.test', 'national_registration' => '11222333000181', 'cellphone' => '11987654321']);
    $this->clinic->skipAutoTrial = true;
    $this->clinic->save();

    $this->user   = User::factory()->create();
    $this->member = createEntityUser($this->clinic, $this->user, ClientRule::Admin->value, isOwner: true);
});

afterEach(fn () => Carbon::setTestNow());

function crfAs(?User $user = null, $member = null): mixed
{
    return test()->actingAs($user ?? test()->user)->withSession(panelSession($member ?? test()->member));
}

/** Plano pago e vigente no Mercado Pago: período 20/09 → 20/10. */
function crfPaid(?Plan $plan = null, array $attributes = []): Subscription
{
    $plan ??= test()->plan;

    return Subscription::factory()->gateway('mercadopago')->for(test()->clinic)->for($plan)->create([
        'gateway_subscription_id' => null,
        'gateway_customer_id'     => '1234567-cus',
        'billing_cycle'           => BillingCycle::Monthly,
        'status'                  => SubscriptionStatus::Active,
        'billing_state'           => 'paid',
        'amount'                  => (float) $plan->priceFor(BillingCycle::Monthly),
        'last_payment_at'         => '2026-09-20 10:00:00',
        'starts_at'               => '2026-09-20 10:00:00',
        'ends_at'                 => '2026-10-20 23:59:59',
        'next_billing_at'         => '2026-10-20 23:59:59',
        ...$attributes,
    ]);
}

/** Cliente em atraso no Mercado Pago com a fatura de 01/10 em Pix (cobrança 111). */
function crfOverdue(array $subscription = [], array $invoice = []): array
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
        ...$subscription,
    ]);

    $inv = Invoice::query()->create([
        'entity_id'           => $sub->entity_id,
        'subscription_id'     => $sub->id,
        'plan_id'             => $sub->plan_id,
        'gateway_code'        => $sub->gateway,
        'reference'           => 'INV-20261001-REV',
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
        ...$invoice,
    ]);

    Payment::query()->create([
        'entity_id'           => $sub->entity_id,
        'invoice_id'          => $inv->id,
        'subscription_id'     => $sub->id,
        'gateway_code'        => $sub->gateway,
        'external_payment_id' => (string) $inv->external_invoice_id,
        'status'              => PaymentStatus::Pending->value,
        'amount'              => 299.90,
        'currency'            => 'BRL',
        'idempotency_key'     => 'payment-rev-' . $inv->id,
    ]);

    $sub->update(['current_invoice_id' => $inv->id]);

    return [$sub->fresh(), $inv->fresh()];
}

function crfMpWebhook(string $paymentId, array $payment): void
{
    Http::fake(["https://api.mercadopago.com/v1/payments/{$paymentId}" => Http::response($payment)]);

    $event = WebhookEvent::query()->create([
        'gateway_code'      => 'mercadopago',
        'external_event_id' => "evt-{$paymentId}-" . ($payment['status'] ?? 'x'),
        'payload'           => ['type' => 'payment', 'action' => 'payment.updated', 'data' => ['id' => $paymentId]],
        'headers'           => [],
        'status'            => 'received',
        'received_at'       => now(),
        'event_hash'        => hash('sha256', "evt-{$paymentId}-" . json_encode($payment)),
    ]);

    app(ProcessWebhookEventService::class)->process($event);
}

function crfPixPayment(int $id, float $amount, array $extra = []): array
{
    return [
        'id'                   => $id,
        'status'               => 'pending',
        'payment_method_id'    => 'pix',
        'transaction_amount'   => $amount,
        'date_of_expiration'   => '2026-10-31T23:59:59.000-03:00',
        'point_of_interaction' => ['transaction_data' => ['qr_code' => "00020101pix-{$id}", 'qr_code_base64' => 'iVBOR=']],
        ...$extra,
    ];
}

// ── 1. Troca de plano de quem já paga ─────────────────────────────────────────

describe('1. upgrade/downgrade de quem tem plano pago e vigente', function () {
    it('upgrade: mostra o proporcional, cobra só a diferença e NÃO cancela a assinatura paga; o plano muda quando pago', function () {
        $paid = crfPaid();

        // 15 dos 30 dias restantes: (499,90 − 299,90) × 0,5 = 100,00.
        crfAs()->getJson(route('panel.my-subscription.options', ['plan_id' => $this->plus->id, 'billing_cycle' => 'monthly']))
            ->assertOk()
            ->assertJsonPath('data.change.type', 'upgrade')
            ->assertJsonPath('data.change.amount_now', 100)
            ->assertJsonPath('data.change.remaining_days', 15)
            ->assertJsonPath('data.amount', 100);

        Http::fake(['https://api.mercadopago.com/v1/payments' => Http::response(crfPixPayment(777, 100.00), 201)]);

        $response = crfAs()->postJson(route('panel.my-subscription.contract'), ['plan_id' => $this->plus->id, 'billing_cycle' => 'monthly', 'method' => 'pix'])
            ->assertOk()
            ->assertJsonPath('data.change.type', 'upgrade')
            ->assertJsonPath('data.instructions.pix.copy_paste', '00020101pix-777')
            ->assertJsonPath('data.invoice.kind', 'plan_change');

        $paid->refresh();
        $invoice = Invoice::query()->findOrFail($response->json('data.invoice.id'));

        // A paga segue intacta enquanto o upgrade não é pago.
        expect($paid->status)->toBe(SubscriptionStatus::Active)
            ->and($paid->cancelled_reason)->toBeNull()
            ->and($paid->plan_id)->toBe($this->plan->id)
            ->and(Subscription::query()->forEntity((string) $this->clinic->id)->count())->toBe(1)
            ->and((float) $invoice->amount)->toBe(100.0)
            ->and($invoice->billing_reason)->toBe('plan_change')
            ->and(app(SubscriptionService::class)->hasAccess($this->clinic->fresh()))->toBeTrue();

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.mercadopago.com/v1/payments' && (float) $r['transaction_amount'] === 100.0);

        crfMpWebhook('777', ['id' => 777, 'status' => 'approved', 'transaction_amount' => 100.00, 'external_reference' => $invoice->id]);

        $paid->refresh();

        expect($paid->plan_id)->toBe($this->plus->id)
            ->and((float) $paid->amount)->toBe(499.9)
            ->and($paid->status)->toBe(SubscriptionStatus::Active)
            ->and($paid->ends_at->toDateString())->toBe('2026-10-20')
            ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
            ->and(SubscriptionChange::query()->where('subscription_id', $paid->id)->where('change_type', 'upgrade')->exists())->toBeTrue();
    });

    it('upgrade não pago (Pix vencido): a assinatura paga segue como está, sem atraso', function () {
        $paid = crfPaid();

        Http::fake(['https://api.mercadopago.com/v1/payments' => Http::response(crfPixPayment(778, 100.00), 201)]);

        $invoiceId = crfAs()->postJson(route('panel.my-subscription.contract'), ['plan_id' => $this->plus->id, 'billing_cycle' => 'monthly', 'method' => 'pix'])
            ->assertOk()->json('data.invoice.id');

        Carbon::setTestNow(CarbonImmutable::parse('2026-10-12 10:00:00'));
        crfMpWebhook('778', ['id' => 778, 'status' => 'cancelled', 'transaction_amount' => 100.00, 'external_reference' => $invoiceId, 'date_of_expiration' => '2026-10-08T23:59:59.000-03:00']);

        $paid->refresh();

        expect($paid->status)->toBe(SubscriptionStatus::Active)
            ->and($paid->plan_id)->toBe($this->plan->id)
            ->and($paid->past_due_at)->toBeNull()
            ->and(app(SubscriptionService::class)->hasAccess($this->clinic->fresh()))->toBeTrue();
    });

    it('downgrade: nada é cobrado agora, a mudança fica para o fim do período pago e vale na data', function () {
        $paid = crfPaid($this->plus);

        crfAs()->getJson(route('panel.my-subscription.options', ['plan_id' => $this->plan->id, 'billing_cycle' => 'monthly']))
            ->assertOk()
            ->assertJsonPath('data.change.type', 'scheduled')
            ->assertJsonPath('data.change.reason', 'downgrade')
            ->assertJsonPath('data.change.next_charge_at', '2026-10-20');

        Http::fake();

        crfAs()->postJson(route('panel.my-subscription.contract'), ['plan_id' => $this->plan->id, 'billing_cycle' => 'monthly'])
            ->assertOk()
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.subscription.scheduled_change.plan.id', $this->plan->id);

        Http::assertNothingSent();

        $paid->refresh();

        expect($paid->status)->toBe(SubscriptionStatus::Active)
            ->and($paid->plan_id)->toBe($this->plus->id)
            ->and($paid->scheduledChange()['effective_at'])->toStartWith('2026-10-20')
            ->and(SubscriptionChange::query()->where('subscription_id', $paid->id)->where('change_type', 'downgrade_scheduled')->exists())->toBeTrue();

        // A cobrança do próximo vencimento já sai no novo valor e plano.
        $next = app(SubscriptionCycleService::class)->periodInvoice($paid, CarbonImmutable::parse('2026-10-20'), (string) str()->uuid());

        expect((float) $next->amount)->toBe(299.9)
            ->and($next->plan_id)->toBe($this->plan->id);

        // Na data, o plano troca.
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-21 00:30:00'));
        expect(app(SubscriptionCycleService::class)->applyDueScheduledChanges())->toBe(1);

        $paid->refresh();

        expect($paid->plan_id)->toBe($this->plan->id)
            ->and((float) $paid->amount)->toBe(299.9)
            ->and($paid->scheduledChange())->toBeNull()
            ->and(SubscriptionChange::query()->where('subscription_id', $paid->id)->where('change_type', 'downgrade')->exists())->toBeTrue();
    });

    it('ciclo mais curto (anual → mensal) é agendado; mensal → anual do mesmo plano (mensal equivalente menor) também', function () {
        crfPaid($this->plan, ['billing_cycle' => BillingCycle::Yearly, 'amount' => 2878.80, 'ends_at' => '2027-09-20 23:59:59', 'next_billing_at' => '2027-09-20 23:59:59']);

        crfAs()->getJson(route('panel.my-subscription.options', ['plan_id' => $this->plan->id, 'billing_cycle' => 'monthly']))
            ->assertOk()
            ->assertJsonPath('data.change.type', 'scheduled')
            ->assertJsonPath('data.change.next_charge_at', '2027-09-20');
    });
});

// ── 2. D5 uma vez ─────────────────────────────────────────────────────────────

describe('2. D5 (acesso até o 1º vencimento sem pagar) vale uma vez', function () {
    beforeEach(function () {
        Http::fake([
            'https://api.mercadopago.com/v1/customers/search*' => Http::response(['results' => [['id' => '1234567-cus']]]),
            'https://api.mercadopago.com/v1/payments/*'        => Http::response(['id' => 1, 'status' => 'cancelled']),
            'https://api.mercadopago.com/v1/payments'          => Http::sequence()
                ->push(crfPixPayment(801, 299.90), 201)
                ->push(crfPixPayment(802, 2878.80), 201)
                ->push(crfPixPayment(803, 499.90), 201),
        ]);
    });

    it('trocar de plano/ciclo sem pagar não estende o acesso: a nova só herda o prazo da anterior', function () {
        crfAs()->postJson(route('panel.my-subscription.contract'), ['plan_id' => $this->plan->id, 'billing_cycle' => 'monthly', 'method' => 'pix'])->assertOk();

        // 1ª contratação: acesso até o fim do dia 08/10.
        expect(app(SubscriptionService::class)->hasAccess($this->clinic->fresh()))->toBeTrue();

        Carbon::setTestNow(CarbonImmutable::parse('2026-10-07 10:00:00'));
        $second = crfAs()->postJson(route('panel.my-subscription.contract'), ['plan_id' => $this->plan->id, 'billing_cycle' => 'yearly', 'method' => 'pix'])->assertOk();

        $sub = Subscription::query()->find($second->json('data.subscription.id'));

        // A 2ª vence em 10/10, mas o acesso sem pagar segue terminando em 08/10.
        expect($sub->next_billing_at->toDateString())->toBe('2026-10-10')
            ->and($sub->firstChargeAccessEndsAt()->toDateString())->toBe('2026-10-08');

        Carbon::setTestNow(CarbonImmutable::parse('2026-10-09 10:00:00'));
        expect(app(SubscriptionService::class)->hasAccess($this->clinic->fresh()))->toBeFalse();
    });

    it('contratação não paga encerrada nos últimos 30 dias: a nova só libera depois de paga', function () {
        Subscription::factory()->gateway('mercadopago')->for($this->clinic)->for($this->plan)->create([
            'gateway_subscription_id' => null,
            'status'                  => SubscriptionStatus::Expired,
            'last_payment_at'         => null,
            'cancelled_at'            => '2026-09-25 10:00:00',
            'ends_at'                 => '2026-09-25 23:59:59',
            'next_billing_at'         => '2026-09-25 23:59:59',
        ]);

        $response = crfAs()->postJson(route('panel.my-subscription.contract'), ['plan_id' => $this->plan->id, 'billing_cycle' => 'monthly', 'method' => 'pix'])
            ->assertOk()
            ->assertJsonPath('data.subscription.access_level', 'none');

        expect(Subscription::query()->find($response->json('data.subscription.id'))->hasAccess())->toBeFalse()
            ->and(app(SubscriptionService::class)->hasAccess($this->clinic->fresh()))->toBeFalse();
    });

    it('1ª contratação da clínica segue com o D5 (acesso até o vencimento)', function () {
        $response = crfAs()->postJson(route('panel.my-subscription.contract'), ['plan_id' => $this->plan->id, 'billing_cycle' => 'monthly', 'method' => 'pix'])
            ->assertOk()
            ->assertJsonPath('data.subscription.access_level', 'full');

        expect(Subscription::query()->find($response->json('data.subscription.id'))->firstChargeAccessEndsAt()->toDateString())->toBe('2026-10-08');
    });
});

// ── 3. Antifraude ─────────────────────────────────────────────────────────────

function crfRegisterPayload(array $overrides = []): array
{
    return [
        'name'                  => 'Maria Souza',
        'email'                 => 'maria' . strtolower(str()->random(6)) . '@clinica-nova.test',
        'password'              => 'Password1!',
        'password_confirmation' => 'Password1!',
        'company_name'          => 'Clínica Nova',
        'company_phone'         => '11988887777',
        'plan_id'               => test()->plan->id,
        'billing_cycle'         => 'monthly',
        'start_mode'            => 'checkout',
        ...$overrides,
    ];
}

function crfMpDeclined(): array
{
    return ['id' => 990, 'status' => 'rejected', 'status_detail' => 'cc_rejected_insufficient_amount', 'transaction_amount' => 299.90, 'payment_method_id' => 'visa'];
}

describe('3. antifraude no cadastro e no checkout', function () {
    it('POST /register tem limite por IP', function () {
        Event::fake([Registered::class]);
        config(['billing.checkout.fraud.register_per_ip_hour' => 2]);

        $this->postJson('/register', [])->assertStatus(422);
        $this->postJson('/register', [])->assertStatus(422);
        $this->postJson('/register', crfRegisterPayload())
            ->assertStatus(429)
            ->assertJsonPath('message', __('auth.register.too_many_signups'));
    });

    it('Turnstile: com as chaves, o cadastro exige o token validado no siteverify; sem as chaves, nada muda', function () {
        Event::fake([Registered::class]);
        config(['billing.turnstile.site_key' => '0x4AAAAAAA-site', 'billing.turnstile.secret_key' => '0x4AAAAAAA-secret']);

        Http::fake(['https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::sequence()
            ->push(['success' => false, 'error-codes' => ['invalid-input-response']])
            ->push(['success' => true, 'hostname' => 'localhost'])]);

        $this->get('/register')->assertInertia(fn (Assert $page) => $page->where('turnstileSiteKey', '0x4AAAAAAA-site'));

        $this->postJson('/register', crfRegisterPayload())->assertStatus(422)->assertJsonValidationErrors(['turnstile_token']);
        $this->postJson('/register', crfRegisterPayload(['turnstile_token' => 'tok-bad']))->assertStatus(422)->assertJsonValidationErrors(['turnstile_token']);
        $this->postJson('/register', crfRegisterPayload(['turnstile_token' => 'tok-ok']))->assertOk();

        Http::assertSent(fn (Request $r) => $r->url() === 'https://challenges.cloudflare.com/turnstile/v0/siteverify'
            && $r['secret'] === '0x4AAAAAAA-secret' && $r['response'] === 'tok-ok');
    });

    it('recusas de cartão por IP: estourado o limite, o cartão fica indisponível sem chamar o gateway', function () {
        config(['billing.checkout.fraud.declines_per_ip_hour' => 2]);
        [, $invoice] = crfOverdue();

        Http::fake(['https://api.mercadopago.com/v1/payments' => Http::response(crfMpDeclined(), 201)]);

        foreach ([1, 2] as $attempt) {
            crfAs()->postJson(route('panel.my-subscription.card', $invoice->id), ['card_token' => "tok-{$attempt}", 'payment_method_id' => 'visa'])
                ->assertStatus(422)
                ->assertJsonPath('code', 'card_declined');
        }

        crfAs()->postJson(route('panel.my-subscription.card', $invoice->id), ['card_token' => 'tok-3', 'payment_method_id' => 'visa'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'card_attempts_exceeded');

        Http::assertSentCount(2);
    });

    it('cadastro (e-mail não confirmado): depois da 1ª recusa o cartão exige o e-mail confirmado', function () {
        $user   = User::factory()->unverified()->create();
        $member = createEntityUser($this->clinic, $user, ClientRule::Admin->value, isOwner: true);

        Http::fake([
            'https://api.mercadopago.com/v1/customers/search*' => Http::response(['results' => [['id' => '1234567-cus']]]),
            'https://api.mercadopago.com/v1/payments'          => Http::response(crfMpDeclined(), 201),
        ]);

        $card = ['plan_id' => $this->plan->id, 'billing_cycle' => 'monthly', 'method' => 'credit_card', 'card_token' => 'tok-1', 'payment_method_id' => 'visa'];

        crfAs($user, $member)->postJson(route('signup-checkout.contract'), $card)->assertStatus(422)->assertJsonPath('code', 'card_declined');

        crfAs($user, $member)->postJson(route('signup-checkout.contract'), [...$card, 'card_token' => 'tok-2'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'card_requires_verified_email');

        Http::assertSentCount(2); // busca do cliente + a 1ª cobrança
    });

    it('limite global de recusas no cadastro', function () {
        config(['billing.checkout.fraud.signup_declines_global_hour' => 1, 'billing.checkout.fraud.signup_unverified_declines' => 99]);

        Http::fake([
            'https://api.mercadopago.com/v1/customers/search*' => Http::response(['results' => [['id' => '1234567-cus']]]),
            'https://api.mercadopago.com/v1/payments'          => Http::response(crfMpDeclined(), 201),
        ]);

        $card = ['plan_id' => $this->plan->id, 'billing_cycle' => 'monthly', 'method' => 'credit_card', 'card_token' => 'tok-1', 'payment_method_id' => 'visa'];

        crfAs()->postJson(route('signup-checkout.contract'), $card)->assertStatus(422);

        // Outra clínica nova, outro usuário, mesmo limite global do cadastro.
        $other                = Entity::factory()->make(['is_client' => true, 'active' => true, 'email' => 'x@y.test']);
        $other->skipAutoTrial = true;
        $other->save();
        $otherUser = User::factory()->create();

        crfAs($otherUser, createEntityUser($other, $otherUser, ClientRule::Admin->value, isOwner: true))
            ->withServerVariables(['REMOTE_ADDR' => '10.9.9.9'])
            ->postJson(route('signup-checkout.contract'), $card)
            ->assertStatus(429)
            ->assertJsonPath('code', 'card_attempts_exceeded');
    });
});

// ── 4. LGPD nos payloads ──────────────────────────────────────────────────────

describe('4. dados do portador fora do que é gravado', function () {
    it('nome e CPF do portador (subárvores) não vão para fatura, pagamento nem tentativa', function () {
        [, $invoice] = crfOverdue();

        Http::fake([
            'https://api.mercadopago.com/v1/customers/1234567-cus/cards' => Http::response(['id' => '1', 'last_four_digits' => '1111', 'payment_method' => ['id' => 'visa']], 201),
            'https://api.mercadopago.com/v1/payments'                    => Http::response([
                'id'   => 555, 'status' => 'approved', 'transaction_amount' => 299.90, 'payment_method_id' => 'visa',
                'card' => ['first_six_digits' => '411111', 'last_four_digits' => '1111', 'expiration_month' => 11, 'expiration_year' => 2030,
                    'cardholder'              => ['name' => 'JOAO DA SILVA', 'identification' => ['number' => '12345678909', 'type' => 'CPF']]],
                'payer' => ['identification' => ['number' => '12345678909', 'type' => 'CPF'], 'email' => 'x@y.z'],
            ], 201),
        ]);

        crfAs()->postJson(route('panel.my-subscription.card', $invoice->id), ['card_token' => 'tok-a', 'payment_method_id' => 'visa'])->assertOk();

        $stored = [
            $invoice->fresh()->raw_gateway_payload,
            Payment::query()->where('external_payment_id', '555')->first()->raw_gateway_payload,
            PaymentAttempt::query()->where('invoice_id', $invoice->id)->first()->response_payload,
        ];

        foreach ($stored as $payload) {
            $json = json_encode($payload);

            expect($json)->not->toContain('JOAO')
                ->and($json)->not->toContain('12345678909')
                ->and($payload['card']['cardholder'])->toBe(PayloadSanitizer::REDACTED)
                ->and($payload['payer']['identification'])->toBe(PayloadSanitizer::REDACTED)
                ->and($payload['card']['last_four_digits'])->toBe('1111');
        }
    });

    it('PayloadSanitizer: holder, billing_details e tax_id inteiros; Pix/boleto intactos', function () {
        $clean = PayloadSanitizer::clean([
            'charges'        => [['payment_method' => ['card' => ['holder' => ['name' => 'ANA', 'tax_id' => '1'], 'last_digits' => '0001']]]],
            'payment_method' => ['billing_details' => ['name' => 'ANA', 'email' => 'a@b'], 'card' => ['last4' => '4242']],
            'customer'       => ['tax_id' => '11222333000181', 'name' => 'Clínica'],
            'qr_codes'       => [['text' => '000201pix']],
            'boleto'         => ['barcode' => '237933812', 'formatted_barcode' => '2379.338'],
            'client_secret'  => 'pi_secret',
        ]);

        expect($clean['charges'][0]['payment_method']['card']['holder'])->toBe(PayloadSanitizer::REDACTED)
            ->and($clean['charges'][0]['payment_method']['card']['last_digits'])->toBe('0001')
            ->and($clean['payment_method']['billing_details'])->toBe(PayloadSanitizer::REDACTED)
            ->and($clean['customer']['tax_id'])->toBe(PayloadSanitizer::REDACTED)
            ->and($clean['qr_codes'][0]['text'])->toBe('000201pix')
            ->and($clean['boleto']['barcode'])->toBe('237933812')
            ->and($clean['client_secret'])->toBe(PayloadSanitizer::REDACTED);
    });
});

// ── 5. Cartão sem chave pública ───────────────────────────────────────────────

describe('5. cartão "transparente" exige a chave pública', function () {
    it('Mercado Pago sem public_key: cartão some das formas e a contratação no cartão é recusada com mensagem clara', function () {
        config(['billing.gateways.mercadopago.public_key' => null]);
        Http::fake();

        crfAs()->getJson(route('panel.my-subscription.options', ['plan_id' => $this->plan->id, 'billing_cycle' => 'monthly']))
            ->assertOk()
            ->assertJsonPath('data.card', null)
            ->assertJsonMissingPath('data.methods.2');

        crfAs()->postJson(route('panel.my-subscription.contract'), ['plan_id' => $this->plan->id, 'billing_cycle' => 'monthly', 'method' => 'credit_card', 'card_token' => 'tok', 'payment_method_id' => 'visa'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'card_unavailable')
            ->assertJsonPath('message', __('checkout.errors.card_unavailable'));

        Http::assertNothingSent();
        expect(Subscription::query()->forEntity((string) $this->clinic->id)->count())->toBe(0);
    });

    it('Stripe sem chave pública: cartão vai pelo link (fatura hospedada aceita cartão)', function () {
        config(['billing.gateways.stripe_br.public_key' => null, 'billing.default_gateway' => 'stripe_br']);

        expect(app(GatewayRegistry::class)->get('stripe_br')->supportsTransparent('credit_card'))->toBeFalse()
            ->and(app(GatewayRegistry::class)->get('stripe_br')->supportsCardLink())->toBeTrue();

        crfAs()->getJson(route('panel.my-subscription.options', ['plan_id' => $this->plan->id, 'billing_cycle' => 'monthly']))
            ->assertOk()
            ->assertJsonPath('data.methods.2.method', 'credit_card')
            ->assertJsonPath('data.methods.2.mode', 'link');
    });
});

// ── 6. Renovação do anual parcelado ───────────────────────────────────────────

describe('6. renovação no cartão é à vista (avisado na tela e no lembrete)', function () {
    it('nenhum gateway parcela a cobrança iniciada pelo lojista; a tela de contratação avisa', function () {
        foreach (['mercadopago', 'pagarme', 'pagbank', 'stripe_br', 'asaas', 'infinitepay'] as $code) {
            expect(app(GatewayRegistry::class)->get($code)->supportsRenewalInstallments())->toBeFalse();
        }

        config(['billing.default_gateway' => 'pagarme']);

        crfAs()->getJson(route('panel.my-subscription.options', ['plan_id' => $this->plan->id, 'billing_cycle' => 'yearly']))
            ->assertOk()
            ->assertJsonPath('data.card.renewal_in_full', true);
    });

    it('lembrete da renovação do anual parcelado no cartão diz que é à vista e não fala em link', function () {
        Notification::fake();

        $sub = Subscription::factory()->gateway('pagarme')->for($this->clinic)->for($this->plan)->create([
            'gateway_subscription_id' => null,
            'gateway_customer_id'     => 'cus_pgm1',
            'billing_cycle'           => BillingCycle::Yearly,
            'status'                  => SubscriptionStatus::Active,
            'amount'                  => 2878.80,
            'last_payment_at'         => '2025-10-08 10:00:00',
            'ends_at'                 => '2026-10-08 23:59:59',
            'next_billing_at'         => '2026-10-08 23:59:59',
            'payment_method'          => 'credit_card',
            'gateway_card_id'         => 'card_1',
            'card_brand'              => 'visa',
            'card_last4'              => '0010',
            'card_installments'       => 12,
        ]);

        expect(app(DunningService::class)->stepFor($sub))->toBe(DunningStep::Reminder);

        app(DunningService::class)->run();

        $notification = Notification::sent($this->user, SubscriptionDunningNotification::class)->first();
        $mail         = $notification->toMail($this->user);
        $text         = str_replace("\u{00A0}", ' ', implode(' ', $mail->introLines));

        expect($text)->toContain('final 0010')
            ->and($text)->toContain(__('billing_dunning.reminder.card_in_full'))
            ->and($text)->not->toContain(__('billing_dunning.reminder.no_link'))
            ->and($mail->actionUrl)->toBe(route('panel.my-subscription.index'));
    });
});

// ── 7. Trava e idempotência ───────────────────────────────────────────────────

describe('7. idempotência e concorrência', function () {
    it('o resultado de um pedido igual que terminou enquanto este esperava a trava é relido DENTRO dela', function () {
        [, $invoice] = crfOverdue();
        $key         = 'idem-123';
        $resultKey   = 'billing:checkout:result:' . $this->clinic->id . ':' . hash('sha256', 'card:' . $invoice->id . '|' . $key);
        $done        = false;

        // Simula o outro pedido terminando logo depois da 1ª leitura (fora da trava).
        Event::listen(CacheMissed::class, function (CacheMissed $event) use ($resultKey, &$done): void {
            if (! $done && $event->key === $resultKey) {
                $done = true;
                Cache::put($resultKey, ['status' => 'paid', 'from' => 'other_request'], 60);
            }
        });

        Http::fake();

        crfAs()->withHeaders(['Idempotency-Key' => $key])
            ->postJson(route('panel.my-subscription.card', $invoice->id), ['card_token' => 'tok-a', 'payment_method_id' => 'visa'])
            ->assertOk()
            ->assertJsonPath('data.from', 'other_request');

        Http::assertNothingSent();
    });

    it('contratação pendente do mesmo plano: as regras rodam dentro da trava da clínica (ocupada → 409, nada emitido)', function () {
        Http::fake([
            'https://api.mercadopago.com/v1/customers/search*' => Http::response(['results' => [['id' => '1234567-cus']]]),
            'https://api.mercadopago.com/v1/payments'          => Http::response(crfPixPayment(801, 299.90), 201),
        ]);

        crfAs()->postJson(route('panel.my-subscription.contract'), ['plan_id' => $this->plan->id, 'billing_cycle' => 'monthly', 'method' => 'pix'])->assertOk();
        Http::assertSentCount(2);

        $lock = Cache::lock('billing:checkout:entity:' . $this->clinic->id, 30);
        $lock->get();

        crfAs()->postJson(route('panel.my-subscription.contract'), ['plan_id' => $this->plan->id, 'billing_cycle' => 'monthly', 'method' => 'boleto'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'busy');

        $lock->release();
        Http::assertSentCount(2);
    });
});

// ── 8. GET só lê; alternar Pix ↔ boleto não reemite ──────────────────────────

describe('8. instruções: leitura sem efeito colateral', function () {
    it('GET nunca emite; POST emite; alternar entre Pix e boleto devolve as duas sem reemitir', function () {
        [, $invoice] = crfOverdue();

        Http::fake(['https://api.mercadopago.com/v1/payments' => Http::response([
            'id'                  => 222,
            'status'              => 'pending',
            'payment_method_id'   => 'bolbradesco',
            'transaction_amount'  => 299.90,
            'date_of_expiration'  => '2026-10-08T23:59:59.000-03:00',
            'transaction_details' => ['external_resource_url' => 'https://www.mercadopago.com.br/payments/222/ticket?caller_id=1', 'digitable_line' => '23793381286008200000000000000000197890000029990'],
        ], 201)]);

        // Pix da cobrança vigente (sem chamar o gateway).
        crfAs()->getJson(route('panel.my-subscription.instructions', ['invoice' => $invoice->id, 'method' => 'pix']))
            ->assertOk()->assertJsonPath('data.instructions.pix.copy_paste', '00020101pix-111');

        crfAs()->getJson(route('panel.my-subscription.instructions', ['invoice' => $invoice->id, 'method' => 'boleto']))
            ->assertOk()->assertJsonPath('data.issue_required', true);

        Http::assertNothingSent();

        crfAs()->postJson(route('panel.my-subscription.charge', $invoice->id), ['method' => 'boleto'])
            ->assertOk()->assertJsonPath('data.instructions.boleto.digitable_line', '23793381286008200000000000000000197890000029990');

        // Voltar ao Pix e ao boleto: as duas cobranças seguem valendo, nada é reemitido nem cancelado.
        crfAs()->getJson(route('panel.my-subscription.instructions', ['invoice' => $invoice->id, 'method' => 'pix']))
            ->assertOk()->assertJsonPath('data.instructions.pix.copy_paste', '00020101pix-111');
        crfAs()->getJson(route('panel.my-subscription.instructions', ['invoice' => $invoice->id, 'method' => 'boleto']))
            ->assertOk()->assertJsonPath('data.instructions.boleto.digitable_line', '23793381286008200000000000000000197890000029990');

        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PUT');
        expect(Payment::query()->where('external_payment_id', '111')->value('status'))->toBe(PaymentStatus::Pending);
    });
});

// ── 9. Chave pública por gateway ──────────────────────────────────────────────

describe('9. formato da chave pública no manager', function () {
    beforeEach(function () {
        $this->saas  = Entity::factory()->create(['is_client' => false, 'active' => true]);
        $this->admin = User::factory()->create();
        createEntityUser($this->saas, $this->admin, SaasRule::Admin->value);
    });

    function crfStoreCredential(string $code, array $data): mixed
    {
        $gateway = Gateway::query()->updateOrCreate(['code' => $code], ['name' => $code, 'active' => true, 'supports_webhooks' => false, 'priority' => 50]);

        return test()->withoutMiddleware(ThrottleRequests::class)
            ->actingAs(test()->admin)
            ->withSession(['selected_entity_id' => test()->saas->id, 'selected_entity_is_client' => false, 'selected_entity_user_rule' => 'admin'])
            ->postJson(route('manager.gateways.credentials.store', $gateway), [
                'secret' => 'APP_USR-1234567890123456-100101-abcdefabcdefabcdefabcdefabcdefab-123456789',
                'reason' => 'Rotação programada da credencial do gateway.',
                ...$data,
            ]);
    }

    it('recusa access token/segredo e formato errado; aceita o formato oficial de cada gateway', function () {
        // Mercado Pago: o access token também começa com APP_USR- (mas não é UUID).
        crfStoreCredential('mercadopago', ['public_key' => 'APP_USR-1234567890123456-100101-abcdefabcdefabcdefabcdefabcdefab-123456789'])
            ->assertStatus(422)->assertJsonValidationErrors(['public_key']);
        crfStoreCredential('mercadopago', ['public_key' => 'APP_USR-0a1b2c3d-4e5f-6789-abcd-ef0123456789'])->assertOk();
        crfStoreCredential('mercadopago', ['public_key' => 'TEST-0a1b2c3d-4e5f-6789-abcd-ef0123456789'])->assertOk();

        crfStoreCredential('pagarme', ['secret' => 'sk_test_abcdefgh', 'public_key' => 'sk_test_abcdefgh'])->assertStatus(422)->assertJsonValidationErrors(['public_key']);
        crfStoreCredential('pagarme', ['secret' => 'sk_test_abcdefgh', 'public_key' => 'abc123'])->assertStatus(422)->assertJsonValidationErrors(['public_key']);
        crfStoreCredential('pagarme', ['secret' => 'sk_test_abcdefgh', 'public_key' => 'pk_test_ABCdef1234'])->assertOk();

        crfStoreCredential('stripe_br', ['secret' => 'sk_test_abcdefgh', 'public_key' => 'pk_test_51ABCdefGHIjkl'])->assertOk();
        crfStoreCredential('stripe_br', ['secret' => 'sk_test_abcdefgh', 'public_key' => 'rk_live_51ABCdefGHIjkl'])->assertStatus(422);

        crfStoreCredential('pagbank', ['secret' => 'tok_pagbank_secret', 'public_key' => config('billing.gateways.pagbank.public_key')])->assertOk();
        crfStoreCredential('pagbank', ['secret' => 'tok_pagbank_secret', 'public_key' => 'not-a-pem'])->assertStatus(422);

        crfStoreCredential('asaas', ['secret' => '$aact_test_secret', 'public_key' => 'pk_test_ABCdef1234'])->assertStatus(422)->assertJsonValidationErrors(['public_key']);
    });
});

// ── 10. Juros no Mercado Pago ─────────────────────────────────────────────────

describe('10. "sem juros" no Mercado Pago', function () {
    it('pago a mais que o valor (juros cobrados do cliente) gera alerta crítico para o financeiro', function () {
        [, $invoice] = crfOverdue();

        Http::fake([
            'https://api.mercadopago.com/v1/customers/1234567-cus/cards' => Http::response(['id' => '1', 'last_four_digits' => '1111', 'payment_method' => ['id' => 'visa']], 201),
            'https://api.mercadopago.com/v1/payments'                    => Http::response([
                'id'                  => 556, 'status' => 'approved', 'transaction_amount' => 299.90, 'installments' => 6, 'payment_method_id' => 'visa',
                'transaction_details' => ['total_paid_amount' => 341.27, 'net_received_amount' => 285.0],
            ], 201),
        ]);

        crfAs()->postJson(route('panel.my-subscription.card', $invoice->id), ['card_token' => 'tok-a', 'payment_method_id' => 'visa'])->assertOk();

        $log = BillingLog::query()->where('level', 'critical')->where('message', 'like', '%juros%')->first();

        expect($log)->not->toBeNull()
            ->and($log->context['difference'])->toBe(41.37)
            ->and($log->context['external_payment_id'])->toBe('556');
    });
});

// ── 11. Cobrança desligada paga ───────────────────────────────────────────────

describe('11. pago pela cobrança desligada', function () {
    it('o Pix antigo (mantido) foi pago: a fatura quita e o boleto vigente é cancelado no gateway', function () {
        [$subscription, $invoice] = crfOverdue();

        Http::fake([
            'https://api.mercadopago.com/v1/payments/222' => Http::response(['id' => 222, 'status' => 'cancelled']),
            'https://api.mercadopago.com/v1/payments'     => Http::response([
                'id'                  => 222, 'status' => 'pending', 'payment_method_id' => 'bolbradesco', 'transaction_amount' => 299.90,
                'transaction_details' => ['external_resource_url' => 'https://www.mercadopago.com.br/payments/222/ticket', 'digitable_line' => '2379338128600'],
            ], 201),
        ]);

        crfAs()->getJson(route('panel.my-subscription.instructions', ['invoice' => $invoice->id, 'method' => 'pix']))->assertOk();
        crfAs()->postJson(route('panel.my-subscription.charge', $invoice->id), ['method' => 'boleto'])->assertOk();

        crfMpWebhook('111', ['id' => 111, 'status' => 'approved', 'transaction_amount' => 299.90, 'external_reference' => $invoice->id]);

        $invoice->refresh();

        expect($invoice->status)->toBe(InvoiceStatus::Paid)
            ->and($invoice->external_invoice_id)->toBe('111')
            ->and($invoice->metadata['cancelled_charges'])->toContain('222')
            ->and(Payment::query()->where('external_payment_id', '222')->value('status'))->toBe(PaymentStatus::Cancelled)
            ->and($subscription->fresh()->status)->toBe(SubscriptionStatus::Active);

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/v1/payments/222') && $r['status'] === 'cancelled');
    });
});

// ── 12. Régua × cartão ────────────────────────────────────────────────────────

describe('12. renovação no cartão e Pix aberto pelo cliente', function () {
    it('cartão recusado, cliente abre o Pix: no dia seguinte o cartão é tentado de novo; aprovado, o Pix é cancelado', function () {
        $sub = Subscription::factory()->gateway('mercadopago')->for($this->clinic)->for($this->plan)->create([
            'gateway_subscription_id' => null,
            'gateway_customer_id'     => '1234567-cus',
            'billing_cycle'           => BillingCycle::Monthly,
            'status'                  => SubscriptionStatus::Active,
            'amount'                  => 299.90,
            'last_payment_at'         => '2026-09-05 10:00:00',
            'ends_at'                 => '2026-10-05 23:59:59',
            'next_billing_at'         => '2026-10-05 23:59:59',
            'payment_method'          => 'credit_card',
            'gateway_card_id'         => 'card-1',
            'card_brand'              => 'visa',
            'card_last4'              => '1111',
            'gateway_payload'         => ['card' => ['origin_payment_id' => 'pay-first', 'sequence' => 1]],
        ]);

        Http::fake([
            'https://api.mercadopago.com/v1/card_tokens'  => Http::response(['id' => 'tok-srv'], 201),
            'https://api.mercadopago.com/v1/payments/900' => Http::response(['id' => 900, 'status' => 'cancelled']),
            'https://api.mercadopago.com/v1/payments'     => Http::sequence()
                ->push(crfMpDeclined(), 201)
                ->push(crfPixPayment(900, 299.90), 201)
                ->push(['id' => 901, 'status' => 'approved', 'transaction_amount' => 299.90, 'payment_method_id' => 'visa', 'card' => ['last_four_digits' => '1111']], 201),
        ]);

        app()->call([new RenewSubscriptionJob((string) $sub->id), 'handle']);
        $invoice = $sub->invoices()->first();
        expect($invoice->status)->toBe(InvoiceStatus::Failed);

        crfAs()->postJson(route('panel.my-subscription.charge', $invoice->id), ['method' => 'pix'])
            ->assertOk()->assertJsonPath('data.instructions.pix.copy_paste', '00020101pix-900');

        Carbon::setTestNow(CarbonImmutable::parse('2026-10-06 01:00:00'));
        Queue::fake();
        app(SubscriptionCycleService::class)->dispatchDueRenewals();
        Queue::assertPushed(RenewSubscriptionJob::class, fn (RenewSubscriptionJob $job) => $job->subscriptionId === (string) $sub->id);

        app()->call([new RenewSubscriptionJob((string) $sub->id), 'handle']);

        $invoice->refresh();

        expect($invoice->status)->toBe(InvoiceStatus::Paid)
            ->and($invoice->external_invoice_id)->toBe('901')
            ->and(Payment::query()->where('external_payment_id', '900')->value('status'))->toBe(PaymentStatus::Cancelled);

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/v1/payments/900'));
    });
});

// ── 13. PagBank sem troca avulsa ──────────────────────────────────────────────

describe('13. PagBank: sem troca de cartão avulsa', function () {
    it('can_change_card falso e a troca responde card_change_unsupported sem chamar o gateway', function () {
        Subscription::factory()->gateway('pagbank')->for($this->clinic)->for($this->plan)->create([
            'gateway_subscription_id' => null,
            'gateway_customer_id'     => 'CUST_1',
            'status'                  => SubscriptionStatus::Active,
            'billing_cycle'           => BillingCycle::Monthly,
            'amount'                  => 299.90,
            'last_payment_at'         => '2026-09-20 10:00:00',
            'ends_at'                 => '2026-10-20 23:59:59',
            'next_billing_at'         => '2026-10-20 23:59:59',
            'payment_method'          => 'credit_card',
            'gateway_card_id'         => 'CARD_1',
        ]);

        Http::fake();

        crfAs()->getJson(route('panel.my-subscription.summary'))->assertOk()->assertJsonPath('data.subscription.can_change_card', false);
        crfAs()->putJson(route('panel.my-subscription.replace-card'), ['card_token' => 'encrypted-card'])
            ->assertStatus(422)->assertJsonPath('code', 'card_change_unsupported');

        Http::assertNothingSent();
    });
});

// ── 14. Stripe 3DS ────────────────────────────────────────────────────────────

describe('14. 3DS pendente não troca o cartão da renovação', function () {
    it('o cartão novo só vira o da renovação depois de confirmado o pagamento', function () {
        [$sub, $invoice] = crfOverdue([
            'gateway'             => 'stripe_br',
            'gateway_customer_id' => 'cus_S1',
            'payment_method'      => 'credit_card',
            'gateway_card_id'     => 'pm_old',
            'card_brand'          => 'visa',
            'card_last4'          => '4242',
        ], ['gateway_code' => 'stripe_br', 'external_invoice_id' => 'pi_pix_old', 'raw_gateway_payload' => []]);

        Http::fake([
            'https://api.stripe.com/v1/payment_intents/pi_pix_old/cancel' => Http::response(['id' => 'pi_pix_old', 'status' => 'canceled']),
            'https://api.stripe.com/v1/payment_intents'                   => Http::response([
                'id'             => 'pi_3ds', 'status' => 'requires_action', 'amount' => 29990, 'client_secret' => 'pi_3ds_secret',
                'payment_method' => ['id' => 'pm_new', 'card' => ['brand' => 'mastercard', 'last4' => '4444']],
            ]),
        ]);

        crfAs()->postJson(route('panel.my-subscription.card', $invoice->id), ['card_token' => 'ctoken_x'])
            ->assertOk()->assertJsonPath('data.status', 'requires_action');

        expect($sub->fresh()->gateway_card_id)->toBe('pm_old')
            ->and($invoice->fresh()->metadata['checkout']['pending_card']['id'])->toBe('pm_new');

        // 3DS concluído: o webhook confirma o pagamento.
        DB::transaction(function () use ($sub, $invoice) {
            app(SubscriptionCycleService::class)->confirmPayment($sub->fresh(), $invoice->fresh(), Payment::query()->where('external_payment_id', 'pi_3ds')->first(), CarbonImmutable::parse('2026-10-01'), (string) str()->uuid(), 'webhook');
        });

        expect($sub->fresh()->gateway_card_id)->toBe('pm_new')
            ->and($sub->fresh()->card_last4)->toBe('4444');
    });
});

// ── 15. Automatic Payments ────────────────────────────────────────────────────

describe('15. troca de cartão no Mercado Pago', function () {
    it('mantém o id da 1ª cobrança da assinatura (reference.id) e a sequência; descarta o TID do cartão anterior', function () {
        $sub = crfPaid(null, [
            'payment_method'  => 'credit_card',
            'gateway_card_id' => 'card-old',
            'gateway_payload' => ['card' => ['origin_payment_id' => 'pay-first', 'network_transaction_id' => 'ntid-old', 'payment_method_id' => 'visa', 'sequence' => 3]],
        ]);

        Http::fake(['https://api.mercadopago.com/v1/customers/1234567-cus/cards' => Http::response(['id' => 'card-new', 'last_four_digits' => '5555', 'payment_method' => ['id' => 'master']], 201)]);

        crfAs()->putJson(route('panel.my-subscription.replace-card'), ['card_token' => 'tok-new'])->assertOk()->assertJsonPath('data.status', 'saved');

        expect($sub->fresh()->gateway_payload['card'])->toBe(['origin_payment_id' => 'pay-first', 'sequence' => 3])
            ->and($sub->fresh()->gateway_card_id)->toBe('card-new');
    });
});

// ── 16. CSP ───────────────────────────────────────────────────────────────────

describe('16. Content-Security-Policy nas telas de pagamento', function () {
    it('Minha assinatura e o cadastro têm CSP com os hosts dos SDKs; o resto do painel não muda', function () {
        $csp = crfAs()->get(route('panel.my-subscription.index'))->assertOk()->headers->get('Content-Security-Policy');

        expect($csp)->toContain("script-src 'self'")
            ->and($csp)->toContain('https://sdk.mercadopago.com')
            ->and($csp)->toContain('https://checkout.pagar.me')
            ->and($csp)->toContain('https://js.stripe.com')
            ->and($csp)->toContain('https://assets.pagseguro.com.br')
            ->and($csp)->toContain('frame-src')
            ->and($csp)->toContain("object-src 'none'");

        expect(crfAs()->get(route('panel.profile.edit'))->headers->has('Content-Security-Policy'))->toBeFalse();
    });

    it('cadastro no site (contratar já pagando + Turnstile) também tem CSP', function () {
        expect((string) $this->get('/register')->headers->get('Content-Security-Policy'))->toContain('https://challenges.cloudflare.com')
            ->and((string) $this->get('/login')->headers->get('Content-Security-Policy'))->toBe('');
    });
});

// ── 17. Recusa traduzida ──────────────────────────────────────────────────────

describe('17. motivo da recusa no idioma do usuário', function () {
    it('Mercado Pago: cc_rejected_* vira a chave de tradução (inglês)', function () {
        app()->setLocale('en');
        Http::fake(['https://api.mercadopago.com/v1/payments' => Http::response(crfMpDeclined(), 201)]);

        $result = app(GatewayRegistry::class)->get('mercadopago')->chargeCard(new CardChargeDTO(
            entityId: 'ent-1',
            invoiceId: '9d6b0c1e-0000-4000-8000-0000000000aa',
            subscriptionId: 'sub-1',
            customerId: 'cus_1',
            amount: 299.90,
            currency: 'BRL',
            description: 'Fatura',
            payer: new CustomerDTO(entityId: 'ent-1', name: 'Clínica', email: 'f@olhar.test', document: '11222333000181', phone: null),
            cardToken: 'tok',
            installments: 1,
            saveCard: false,
            paymentMethodId: 'visa',
        ));

        expect($result->status)->toBe('failed')
            ->and($result->errorMessage)->toBe('Insufficient card limit.');
    });
});

// ── 18. 403 em HTML ───────────────────────────────────────────────────────────

describe('18. Minha assinatura sem permissão', function () {
    it('navegação recebe a página 403 do sistema; XHR segue com JSON', function () {
        $secretary = User::factory()->create();
        $member    = createEntityUser($this->clinic, $secretary, ClientRule::Secretary->value);

        crfAs($secretary, $member)->get(route('panel.my-subscription.index'))
            ->assertStatus(403)
            ->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 403)->where('message', __('checkout.errors.forbidden')));

        crfAs($secretary, $member)->getJson(route('panel.my-subscription.summary'))
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');
    });
});

describe('CSP: fontes de ícones e imagens do app', function () {
    it('libera o ASSET_URL (CDN) em font-src, img-src, style-src e script-src', function () {
        config(['app.asset_url' => 'https://cdn.easyeye.app/build']);

        $policy = CheckoutContentSecurityPolicy::policy();

        foreach (['font-src', 'img-src', 'style-src', 'script-src'] as $directive) {
            expect($policy)->toMatch('/' . $directive . ' [^;]*https:\/\/cdn\.easyeye\.app/');
        }
    });
});
