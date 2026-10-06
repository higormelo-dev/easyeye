<?php

declare(strict_types=1);

use App\DTOs\Billing\SubscriptionTerms;
use App\Enums\Billing\{CancellationReason, InvoiceStatus, PaymentStatus};
use App\Enums\{BillingCycle, ClientRule, FeatureKey, SaasRule, SubscriptionStatus};
use App\Jobs\Billing\{CancelGatewaySubscriptionJob, RecreateGatewayRecurrenceJob};
use App\Models\Billing\{BillingRefund, HostedCheckout, Invoice, Payment};
use App\Models\{Entity, Plan, PlanFeature, PlanPrice, Subscription, User};
use App\Notifications\GatewayOperationalAlertNotification;
use App\Services\Billing\{BillingCancellationService, BillingLogService, GatewayRegistry, SubscriptionManagementService};
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\{Carbon, Str};
use Illuminate\Support\Facades\{Cache, DB, Http, Notification, Queue};

/**
 * Correções da revisão do Asaas Checkout (cartão na página hospedada):
 *  - assinatura cancelada/encerrada/substituída/cortesia/plano trocado com
 *    checkout recorrente aberto ou pago aguardando a 1ª cobrança: o checkout
 *    deixa de valer e a recorrência nova é desfeita — o Asaas não pode seguir
 *    cobrando o cartão (https://docs.asaas.com/docs/checkout-com-assinatura-recorrente);
 *  - só adota a recorrência nova com a assinatura governada, a fatura a pagar
 *    e os mesmos termos;
 *  - segundo checkout com o 1º pago aguardando confirmação: 409; pagamento em
 *    duplicidade vira Payment estornável pelo manager;
 *  - cobranças seguintes da recorrência com a referência do checkout herdada
 *    renovam o período (nunca caem na fatura já paga);
 *  - troca de plano refaz a recorrência sem o cartão: a clínica e o time
 *    são avisados.
 */
beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-05 10:00:00'));
    Http::preventStrayRequests();
    Notification::fake();
    Cache::flush();

    config([
        'billing.enforce_subscription_access'   => true,
        'billing.default_gateway'               => 'asaas',
        'billing.gateways.asaas.base_url'       => 'https://api.asaas.com',
        'billing.gateways.asaas.secret'         => '$aact_prod_chave_de_teste',
        'billing.gateways.asaas.webhook_secret' => 'whsec_asaas_teste',
    ]);

    $this->plan = Plan::factory()->create(['name' => 'Pro', 'billing_cycle' => 'monthly', 'price' => 299.90, 'active' => true]);
    PlanPrice::create(['plan_id' => $this->plan->id, 'billing_cycle' => 'monthly', 'price' => 299.90]);
    PlanFeature::factory()->enabled(FeatureKey::HasAiChatAssistant)->for($this->plan)->create();
    $this->plan = $this->plan->fresh('prices');

    $this->clinic                = Entity::factory()->make(['is_client' => true, 'active' => true, 'name' => 'Clínica Revisão', 'national_registration' => '11222333000181']);
    $this->clinic->skipAutoTrial = true;
    $this->clinic->save();

    $this->user   = User::factory()->create();
    $this->member = createEntityUser($this->clinic, $this->user, ClientRule::Admin->value, isOwner: true);

    $this->saas      = Entity::factory()->create(['is_client' => false, 'active' => true]);
    $this->saasAdmin = User::factory()->create(['email_verified_at' => now()]);
    createEntityUser($this->saas, $this->saasAdmin, SaasRule::Admin->value);

    $this->asaasFaked = false;
    rvhFake();
});

afterEach(fn () => Carbon::setTestNow());

/** @param array<string, mixed> $routes rotas "MÉTODO url" (prefixo) → resposta */
function rvhFake(array $routes = []): void
{
    test()->asaas = [
        'POST https://api.asaas.com/v3/checkouts'              => fn (Request $r) => Http::response(['id' => 'chk_1', 'status' => 'ACTIVE', 'minutesToExpire' => 60, 'link' => 'https://asaas.com/checkoutSession/show?id=chk_1']),
        'DELETE https://api.asaas.com/v3/subscriptions/'       => fn (Request $r) => Http::response(['deleted' => true, 'id' => basename($r->url())]),
        'DELETE https://api.asaas.com/v3/payments/'            => fn (Request $r) => Http::response(['deleted' => true, 'id' => basename($r->url())]),
        'POST https://api.asaas.com/v3/checkouts/chk_1/cancel' => Http::response(['id' => 'chk_1', 'status' => 'CANCELED']),
        ...$routes,
    ];

    if (test()->asaasFaked) {
        return;
    }

    test()->asaasFaked = true;

    Http::fake(function (Request $request) {
        $key    = $request->method() . ' ' . $request->url();
        $routes = test()->asaas;
        uksort($routes, fn ($a, $b) => strlen($b) <=> strlen($a));

        foreach ($routes as $prefix => $response) {
            if (str_starts_with($key, $prefix)) {
                return is_callable($response) ? $response($request) : $response;
            }
        }

        return Http::response(['errors' => [['code' => 'not_faked', 'description' => $key]]], 404);
    });
}

/** Cliente pagante: recorrência "pergunte ao cliente" (sub_old) e a fatura de 08/10 em aberto (pay_old). */
function rvhPaying(array $subscription = [], array $invoice = []): array
{
    $sub = Subscription::factory()->gateway('asaas')->for(test()->clinic)->for(test()->plan)->create([
        'gateway_subscription_id' => 'sub_old',
        'gateway_customer_id'     => 'cus_000005219613',
        'billing_cycle'           => BillingCycle::Monthly,
        'status'                  => SubscriptionStatus::Active,
        'billing_state'           => 'paid',
        'amount'                  => 299.90,
        'last_payment_at'         => '2026-09-08 10:00:00',
        'starts_at'               => '2026-08-08 10:00:00',
        'ends_at'                 => '2026-10-08 23:59:59',
        'next_billing_at'         => '2026-10-08 23:59:59',
        ...$subscription,
    ]);

    $inv = Invoice::query()->create([
        'entity_id'           => $sub->entity_id,
        'subscription_id'     => $sub->id,
        'plan_id'             => $sub->plan_id,
        'gateway_code'        => 'asaas',
        'reference'           => 'INV-20261008-RVH',
        'external_invoice_id' => 'pay_old',
        'period_start'        => '2026-10-08',
        'period_end'          => '2026-11-08',
        'due_at'              => '2026-10-08 23:59:59',
        'amount'              => 299.90,
        'currency'            => 'BRL',
        'status'              => InvoiceStatus::Pending->value,
        'billing_reason'      => 'subscription_cycle',
        ...$invoice,
    ]);

    return [$sub, $inv];
}

function rvhAs(): mixed
{
    return test()->actingAs(test()->user)->withSession(panelSession(test()->member));
}

function rvhPost(array $payload): void
{
    test()->postJson('/api/billing/webhooks/asaas', $payload, ['asaas-access-token' => 'whsec_asaas_teste'])->assertOk();
}

function rvhPayment(string $event, string $id = 'pay_new', array $payment = []): array
{
    return [
        'id'          => 'evt_' . Str::random(24),
        'event'       => $event,
        'dateCreated' => now()->format('Y-m-d H:i:s'),
        'payment'     => [
            'object'            => 'payment',
            'id'                => $id,
            'customer'          => 'cus_000005219613',
            'subscription'      => 'sub_new',
            'checkoutSession'   => 'chk_1',
            'value'             => 299.9,
            'billingType'       => 'CREDIT_CARD',
            'status'            => 'CONFIRMED',
            'dueDate'           => '2026-10-08',
            'externalReference' => null,
            'deleted'           => false,
            'creditCard'        => ['creditCardNumber' => '4242', 'creditCardBrand' => 'VISA'],
            ...$payment,
        ],
    ];
}

function rvhSubscriptionCreated(string $id = 'sub_new', string $checkout = 'chk_1'): array
{
    return [
        'id'           => 'evt_' . Str::random(24),
        'event'        => 'SUBSCRIPTION_CREATED',
        'dateCreated'  => now()->format('Y-m-d H:i:s'),
        'subscription' => [
            'object' => 'subscription', 'id' => $id, 'customer' => 'cus_000005219613', 'checkoutSession' => $checkout,
            'value'  => 299.9, 'nextDueDate' => '2026-10-08', 'cycle' => 'MONTHLY', 'billingType' => 'CREDIT_CARD', 'status' => 'ACTIVE', 'deleted' => false,
        ],
    ];
}

function rvhCheckoutPaid(string $id = 'chk_1'): array
{
    return ['id' => 'evt_' . Str::random(24), 'event' => 'CHECKOUT_PAID', 'dateCreated' => now()->format('Y-m-d H:i:s'), 'checkout' => ['id' => $id, 'status' => 'PAID']];
}

function rvhOpenCard(Invoice $invoice): mixed
{
    return rvhAs()->postJson(route('panel.my-subscription.charge', ['invoice' => $invoice->id]), ['method' => 'credit_card']);
}

/** Checkout recorrente pago (CHECKOUT_PAID) e a assinatura nova criada (sub_new), aguardando a 1ª cobrança. */
function rvhPaidAwaitingFirstCharge(Invoice $invoice): void
{
    rvhOpenCard($invoice)->assertOk();
    rvhPost(rvhSubscriptionCreated());
    rvhPost(rvhCheckoutPaid());
}

function rvhDeleted(string $id): bool
{
    return collect(Http::recorded())->contains(fn ($pair) => $pair[0]->method() === 'DELETE' && str_ends_with($pair[0]->url(), '/' . $id));
}

describe('assinatura que mudou com checkout recorrente pendente (achado 1)', function () {
    it('cancelada com o checkout pago aguardando a 1ª cobrança: a recorrência nova é desfeita já no cancelamento e nunca é adotada', function () {
        Queue::fake([CancelGatewaySubscriptionJob::class]);
        [$subscription, $invoice] = rvhPaying();
        rvhPaidAwaitingFirstCharge($invoice);

        app(BillingCancellationService::class)->cancel($subscription->fresh(), $this->clinic, CancellationReason::cases()[0], 'client');

        // Na hora do cancelamento: o Asaas não chega a cobrar o cartão.
        expect(rvhDeleted('sub_new'))->toBeTrue()
            ->and(HostedCheckout::query()->sole()->status)->toBe(HostedCheckout::STATUS_SUPERSEDED);

        // Mesmo que a cobrança chegue (já tinha sido gerada), não adota.
        rvhPost(rvhPayment('PAYMENT_CONFIRMED'));

        $subscription->refresh();
        expect($subscription->status)->toBe(SubscriptionStatus::Cancelled)
            ->and($subscription->gateway_subscription_id)->toBe('sub_old')
            ->and($subscription->payment_method)->not->toBe('credit_card')
            ->and(HostedCheckout::query()->sole()->status)->toBe(HostedCheckout::STATUS_SUPERSEDED)
            ->and(data_get(HostedCheckout::query()->sole()->metadata, 'not_adopted'))->toBe('superseded');
    });

    it('cancelada antes do SUBSCRIPTION_CREATED: a assinatura criada depois pelo checkout é desfeita na chegada', function () {
        Queue::fake([CancelGatewaySubscriptionJob::class]);
        [$subscription, $invoice] = rvhPaying();
        rvhOpenCard($invoice)->assertOk();
        rvhPost(rvhCheckoutPaid());

        app(BillingCancellationService::class)->cancel($subscription->fresh(), $this->clinic, CancellationReason::cases()[0], 'client');
        expect(rvhDeleted('sub_new'))->toBeFalse();

        rvhPost(rvhSubscriptionCreated());

        expect(rvhDeleted('sub_new'))->toBeTrue()
            ->and($subscription->fresh()->gateway_subscription_id)->toBe('sub_old');
    });

    it('encerrada pela régua (expire dentro da transação, D+7): checkout aberto cancelado no Asaas e a recorrência do pago desfeita depois do commit', function () {
        [$subscription, $invoice] = rvhPaying();
        rvhOpenCard($invoice)->assertOk();
        rvhPost(rvhSubscriptionCreated());

        // A clínica não concluiu: checkout ainda aberto.
        DB::transaction(fn () => app(BillingCancellationService::class)->expire($subscription->fresh(), $this->clinic, cancelAtGateway: false));

        expect(HostedCheckout::query()->sole()->status)->toBe(HostedCheckout::STATUS_SUPERSEDED);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'https://api.asaas.com/v3/checkouts/chk_1/cancel');
        expect(rvhDeleted('sub_new'))->toBeTrue();
    });

    it('substituída pelo manager (nova assinatura de cortesia) e virada cortesia: checkouts da assinatura antiga deixam de valer', function (string $how) {
        Queue::fake([CancelGatewaySubscriptionJob::class]);
        [$subscription, $invoice] = rvhPaying();
        rvhPaidAwaitingFirstCharge($invoice);
        $terms = SubscriptionTerms::complimentary(CarbonImmutable::now(), CarbonImmutable::now()->addMonths(3));

        $how === 'replace'
            ? app(SubscriptionManagementService::class)->create($this->clinic, $this->plan, $terms, 'Cortesia de parceria comercial com a clínica')
            : app(SubscriptionManagementService::class)->updateTerms($subscription->fresh(), $this->plan, $terms, 'Virou cortesia por acordo comercial');

        expect(HostedCheckout::query()->sole()->status)->toBe(HostedCheckout::STATUS_SUPERSEDED)
            ->and(rvhDeleted('sub_new'))->toBeTrue();

        rvhPost(rvhPayment('PAYMENT_CONFIRMED'));
        expect($subscription->fresh()->gateway_subscription_id)->not->toBe('sub_new');
    })->with(['replace', 'complimentary']);
});

describe('segundo checkout e pagamento em duplicidade (achado 2)', function () {
    it('com o 1º checkout pago aguardando confirmação, abrir outro é recusado (409) — nada novo no Asaas', function () {
        [, $invoice] = rvhPaying();
        rvhPaidAwaitingFirstCharge($invoice);
        $sentBefore = count(Http::recorded());

        rvhOpenCard($invoice)
            ->assertStatus(409)
            ->assertJsonPath('code', 'card_awaiting_confirmation')
            ->assertJsonPath('message', __('checkout.errors.card_awaiting_confirmation'));

        expect(HostedCheckout::query()->count())->toBe(1)
            ->and(count(Http::recorded()))->toBe($sentBefore);

        $open = collect(rvhAs()->getJson(route('panel.my-subscription.summary'))->json('data.open_invoices'));
        expect($open->firstWhere('id', $invoice->id)['awaiting_confirmation'])->toBeTrue();
    });

    it('dois checkouts pagos (o 1º expirou na tela e a clínica abriu outro): o 2º pagamento vira Payment em duplicidade, estornável pelo manager', function () {
        [$subscription, $invoice] = rvhPaying();
        rvhOpenCard($invoice)->assertOk();
        rvhPost(rvhSubscriptionCreated());

        // O link do 1º expirou na tela; a clínica abre outro (o 1º ainda pode ser pago).
        $this->travel(61)->minutes();
        rvhFake(['POST https://api.asaas.com/v3/checkouts' => Http::response(['id' => 'chk_2', 'status' => 'ACTIVE', 'minutesToExpire' => 60])]);
        rvhOpenCard($invoice)->assertOk();
        rvhPost(rvhSubscriptionCreated('sub_b', 'chk_2'));

        // Os dois cartões são cobrados.
        rvhPost(rvhPayment('PAYMENT_CONFIRMED'));
        rvhPost(rvhPayment('PAYMENT_CONFIRMED', 'pay_b', ['subscription' => 'sub_b', 'checkoutSession' => 'chk_2']));

        $duplicate = Payment::query()->where('external_payment_id', 'pay_b')->first();

        expect($subscription->fresh()->gateway_subscription_id)->toBe('sub_new')
            ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
            ->and(data_get($invoice->fresh()->metadata, 'paid_by_charge'))->toBe('pay_new')
            ->and($duplicate)->not->toBeNull()
            ->and($duplicate->status)->toBe(PaymentStatus::Duplicate)
            ->and($duplicate->invoice_id)->toBe($invoice->id)
            ->and((float) $duplicate->amount)->toBe(299.9)
            ->and(Payment::query()->where('external_payment_id', 'pay_new')->value('status'))->toBe(PaymentStatus::Paid)
            ->and(rvhDeleted('sub_b'))->toBeTrue();

        // Manager: o duplicado aparece com "Estornar".
        $manager = test()->actingAs($this->saasAdmin)->withSession(['selected_entity_id' => $this->saas->id, 'selected_entity_is_client' => false, 'selected_entity_user_rule' => 'admin']);
        $row     = collect($manager->getJson(route('manager.subscriptions.invoices', $subscription->id))->assertOk()->json('data.0.payments'))->firstWhere('external_payment_id', 'pay_b');
        expect($row['refund']['can_refund'])->toBeTrue()
            ->and($row['status_label'])->toBe(__('manager_subscriptions.payment_status.duplicate'));

        rvhFake([
            'GET https://api.asaas.com/v3/payments/pay_b'         => Http::response(['id' => 'pay_b', 'value' => 299.9, 'billingType' => 'CREDIT_CARD', 'status' => 'CONFIRMED', 'refunds' => null]),
            'POST https://api.asaas.com/v3/payments/pay_b/refund' => Http::response(['id' => 'pay_b', 'value' => 299.9, 'status' => 'CONFIRMED', 'refunds' => [['status' => 'PENDING', 'value' => 299.9, 'dateCreated' => '2026-10-05 08:01:00']]]),
        ]);
        $manager->postJson($row['refund_url'], ['mode' => 'full', 'reason' => 'Cobrado duas vezes no cartão, devolver a duplicidade'])->assertOk();

        rvhPost(rvhPayment('PAYMENT_REFUNDED', 'pay_b', ['subscription' => 'sub_b', 'checkoutSession' => 'chk_2', 'status' => 'REFUNDED']));

        expect($duplicate->fresh()->status)->toBe(PaymentStatus::Refunded)
            ->and(BillingRefund::query()->sole()->status)->toBe(BillingRefund::STATUS_DONE)
            ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
            ->and((float) $invoice->fresh()->refunded_amount)->toBe(0.0)
            ->and($subscription->fresh()->status)->toBe(SubscriptionStatus::Active);
    });
});

describe('cobranças da recorrência do checkout (achado 3)', function () {
    it('a cobrança do ciclo seguinte renova o período — herdando ou não a referência do checkout', function (bool $inherits, ?string $session) {
        [$subscription, $invoice] = rvhPaying();
        rvhOpenCard($invoice)->assertOk();
        $ref = "easyeye:sub:{$subscription->id}:inv:{$invoice->id}";

        rvhPost(rvhSubscriptionCreated());
        rvhPost(rvhPayment('PAYMENT_CONFIRMED', payment: ['externalReference' => $inherits ? $ref : null]));
        expect($subscription->fresh()->gateway_subscription_id)->toBe('sub_new')
            ->and($subscription->fresh()->ends_at->toDateTimeString())->toBe('2026-11-08 23:59:59');

        $this->travelTo(CarbonImmutable::parse('2026-11-08 09:00:00'));
        rvhPost(rvhPayment('PAYMENT_RECEIVED', 'pay_next', ['status' => 'RECEIVED', 'dueDate' => '2026-11-08', 'externalReference' => $inherits ? $ref : null, 'checkoutSession' => $session]));

        $next = Payment::query()->where('external_payment_id', 'pay_next')->sole();

        expect($subscription->fresh()->ends_at->toDateTimeString())->toBe('2026-12-08 23:59:59')
            ->and($next->status)->toBe(PaymentStatus::Paid)
            ->and($next->invoice_id)->not->toBe($invoice->id);
    })->with([
        'herda a referência, sem checkoutSession' => [true, null],
        'herda a referência e o checkoutSession'  => [true, 'chk_1'],
        'não herda (referência nula)'             => [false, null],
    ]);

    it('a 1ª cobrança sem checkoutSession (só a referência herdada) ainda troca a recorrência — pela assinatura criada pelo checkout', function () {
        [$subscription, $invoice] = rvhPaying();
        rvhOpenCard($invoice)->assertOk();
        rvhPost(rvhSubscriptionCreated());

        rvhPost(rvhPayment('PAYMENT_CONFIRMED', payment: ['checkoutSession' => null, 'externalReference' => "easyeye:sub:{$subscription->id}:inv:{$invoice->id}"]));

        expect($subscription->fresh()->gateway_subscription_id)->toBe('sub_new')
            ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
            ->and(HostedCheckout::query()->sole()->status)->toBe(HostedCheckout::STATUS_COMPLETED)
            ->and(rvhDeleted('sub_old'))->toBeTrue();
    });
});

describe('troca de plano e o cartão (achado 5)', function () {
    it('recorrência no cartão refeita na troca de plano: sai sem cartão (UNDEFINED), os dados do cartão saem da assinatura, a clínica vê o aviso e o time é alertado', function () {
        $subscription = Subscription::factory()->gateway('asaas')->for($this->clinic)->for($this->plan)->create([
            'gateway_subscription_id' => 'sub_card', 'gateway_customer_id' => 'cus_000005219613', 'billing_cycle' => BillingCycle::Monthly,
            'status'                  => SubscriptionStatus::Active, 'amount' => 299.90, 'last_payment_at' => '2026-10-01 10:00:00',
            'ends_at'                 => '2026-11-01 23:59:59', 'next_billing_at' => '2026-11-01 23:59:59',
            'payment_method'          => 'credit_card', 'card_brand' => 'visa', 'card_last4' => '4242', 'card_installments' => 1,
        ]);
        rvhFake(['POST https://api.asaas.com/v3/subscriptions' => Http::response(['id' => 'sub_recriada', 'customer' => 'cus_000005219613', 'status' => 'ACTIVE'])]);

        (new RecreateGatewayRecurrenceJob((string) $subscription->id, 'sub_card', '2026-11-01', (string) Str::uuid()))
            ->handle(app(GatewayRegistry::class), app(BillingLogService::class));

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'https://api.asaas.com/v3/subscriptions' && $r['billingType'] === 'UNDEFINED');

        $subscription->refresh();
        expect($subscription->gateway_subscription_id)->toBe('sub_recriada')
            ->and($subscription->payment_method)->toBeNull()
            ->and($subscription->card_brand)->toBeNull()
            ->and($subscription->card_last4)->toBeNull()
            ->and(data_get($subscription->gateway_payload, 'card_reregister_required.card.last4'))->toBe('4242')
            ->and(rvhDeleted('sub_card'))->toBeTrue();

        $summary = rvhAs()->getJson(route('panel.my-subscription.summary'))->assertOk()->json('data.subscription');
        expect($summary['card_reregister_required'])->toBeTrue()
            ->and($summary['card'])->toBeNull();

        Notification::assertSentTo($this->saasAdmin, GatewayOperationalAlertNotification::class, fn ($n) => $n->kind === 'card_reregister');
    });

    it('checkout recorrente aberto antes da troca e pago depois: não adota os termos antigos (a vigente fica), a recorrência nova é desfeita e o time é alertado', function () {
        [$subscription, $invoice] = rvhPaying();
        rvhOpenCard($invoice)->assertOk();
        rvhPost(rvhSubscriptionCreated());

        // A assinatura mudou de termos depois de abrir (ex.: ajuste direto).
        $subscription->update(['amount' => 399.90, 'billing_cycle' => BillingCycle::Quarterly]);

        rvhPost(rvhPayment('PAYMENT_CONFIRMED'));

        expect($subscription->fresh()->gateway_subscription_id)->toBe('sub_old')
            ->and(HostedCheckout::query()->sole()->status)->toBe(HostedCheckout::STATUS_SUPERSEDED)
            ->and(data_get(HostedCheckout::query()->sole()->metadata, 'not_adopted'))->toBe('terms_changed')
            ->and(rvhDeleted('sub_new'))->toBeTrue()
            ->and(rvhDeleted('sub_old'))->toBeFalse();
        Notification::assertSentTo($this->saasAdmin, GatewayOperationalAlertNotification::class, fn ($n) => $n->kind === 'checkout_terms_changed');
    });

    it('troca de plano agendada (downgrade) invalida o checkout recorrente pago; depois dela o cartão da fatura sai avulso', function () {
        Queue::fake([RecreateGatewayRecurrenceJob::class]);
        $basic = Plan::factory()->create(['name' => 'Basic', 'billing_cycle' => 'monthly', 'price' => 149.90, 'active' => true]);
        PlanPrice::create(['plan_id' => $basic->id, 'billing_cycle' => 'monthly', 'price' => 149.90]);
        [$subscription, $invoice] = rvhPaying(
            ['last_payment_at' => '2026-10-01 10:00:00', 'starts_at' => '2026-10-01 10:00:00', 'ends_at' => '2026-11-01 23:59:59', 'next_billing_at' => '2026-11-01 23:59:59'],
            ['period_start'    => '2026-11-01', 'period_end' => '2026-12-01', 'due_at' => '2026-11-01 23:59:59'],
        );
        rvhPaidAwaitingFirstCharge($invoice);

        rvhAs()->postJson(route('panel.my-subscription.contract'), ['plan_id' => $basic->id, 'billing_cycle' => 'monthly', 'method' => 'pix'])->assertOk();

        expect($subscription->fresh()->scheduledChange())->not->toBeNull()
            ->and(HostedCheckout::query()->sole()->status)->toBe(HostedCheckout::STATUS_SUPERSEDED)
            ->and(rvhDeleted('sub_new'))->toBeTrue();

        // Fatura do período atual paga no cartão agora: avulso (DETACHED), sem recorrência nos termos velhos.
        rvhFake(['POST https://api.asaas.com/v3/checkouts' => Http::response(['id' => 'chk_3', 'status' => 'ACTIVE', 'minutesToExpire' => 60])]);
        expect(rvhOpenCard($invoice)->assertOk()->json('data.recurrent'))->toBeFalse();
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.asaas.com/v3/checkouts' && $r['chargeTypes'] === ['DETACHED']);
    });
});
