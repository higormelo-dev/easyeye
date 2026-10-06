<?php

declare(strict_types=1);

use App\Domains\AI\Models\{AiCreditPurchase, AiCreditWallet};
use App\Enums\AI\AiCreditPurchaseStatus;
use App\Enums\Billing\InvoiceStatus;
use App\Enums\{BillingCycle, ClientRule, FeatureKey, SaasRule, SubscriptionStatus};
use App\Jobs\Billing\RecreateGatewayRecurrenceJob;
use App\Models\Billing\{BillingLog, HostedCheckout, Invoice, Payment};
use App\Models\{Entity, Plan, PlanFeature, PlanPrice, Subscription, User};
use App\Notifications\GatewayOperationalAlertNotification;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\{Carbon, Str};
use Illuminate\Support\Facades\{Cache, Http, Notification, Queue};

/**
 * Cartão no Asaas pelo Asaas Checkout (página hospedada —
 * https://docs.asaas.com/docs/checkout-asaas):
 *  - fatura do plano → checkout RECURRENT (assinatura no cartão,
 *    https://docs.asaas.com/docs/checkout-com-assinatura-recorrente) com o
 *    valor, o ciclo e o 1º vencimento da fatura; volta para Minha assinatura;
 *  - troca segura: a recorrência antiga (boleto/Pix) só é cancelada depois
 *    da 1ª cobrança da nova confirmada — nunca duas cobrando nem nenhuma; a
 *    cobrança antiga em aberto da fatura é cancelada (não paga duas vezes);
 *  - recusa ou fatura paga por outra cobrança → a recorrência nova é desfeita;
 *  - pacote de IA e diferença do upgrade → checkout DETACHED.
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

    $this->clinic                = Entity::factory()->make(['is_client' => true, 'active' => true, 'name' => 'Clínica Olhar Ltda', 'national_registration' => '11222333000181']);
    $this->clinic->skipAutoTrial = true;
    $this->clinic->save();

    $this->user   = User::factory()->create();
    $this->member = createEntityUser($this->clinic, $this->user, ClientRule::Admin->value, isOwner: true);

    // Time do SaaS (recebe os alertas operacionais).
    $this->saas      = Entity::factory()->create(['is_client' => false, 'active' => true]);
    $this->saasAdmin = User::factory()->create(['email_verified_at' => now()]);
    createEntityUser($this->saas, $this->saasAdmin, SaasRule::Admin->value);

    $this->asaasFaked = false;
    hcFake();
});

afterEach(fn () => Carbon::setTestNow());

/**
 * Asaas simulado: cada rota "MÉTODO url" (prefixo) responde o que estiver em
 * test()->asaas; o resto é 404 (nada de rede).
 *
 * @param array<string, mixed> $routes
 */
function hcFake(array $routes = []): void
{
    test()->asaas = [
        'POST https://api.asaas.com/v3/checkouts'              => fn (Request $r) => Http::response(['id' => 'chk_1', 'status' => 'ACTIVE', 'minutesToExpire' => 60, 'link' => 'https://asaas.com/checkoutSession/show?id=chk_1', 'externalReference' => $r['externalReference']]),
        'DELETE https://api.asaas.com/v3/subscriptions/'       => fn (Request $r) => Http::response(['deleted' => true, 'id' => basename($r->url())]),
        'DELETE https://api.asaas.com/v3/payments/'            => fn (Request $r) => Http::response(['deleted' => true, 'id' => basename($r->url())]),
        'POST https://api.asaas.com/v3/checkouts/chk_1/cancel' => Http::response(['id' => 'chk_1', 'status' => 'CANCELED']),
        ...$routes,
    ];

    // Registrado uma vez: as rotas são lidas de test()->asaas a cada chamada.
    if (test()->asaasFaked) {
        return;
    }

    test()->asaasFaked = true;

    Http::fake(function (Request $request) {
        $key = $request->method() . ' ' . $request->url();
        // Rota mais específica primeiro.
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

/** Cliente pagante no Asaas: recorrência "pergunte ao cliente" (sub_old) e a fatura de 08/10 em aberto (pay_old). */
function hcPaying(array $subscription = [], array $invoice = []): array
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
        'reference'           => 'INV-20261008-ASAAS',
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

function hcAs(): mixed
{
    return test()->actingAs(test()->user)->withSession(panelSession(test()->member));
}

function hcPost(array $payload): void
{
    test()->postJson('/api/billing/webhooks/asaas', $payload, ['asaas-access-token' => 'whsec_asaas_teste'])->assertOk();
}

/** Cobrança da assinatura criada pelo checkout (checkoutSession chk_1). */
function hcPayment(string $event, string $id = 'pay_new', array $payment = []): array
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
            'status'            => 'PENDING',
            'dueDate'           => '2026-10-08',
            'invoiceUrl'        => "https://www.asaas.com/i/{$id}",
            'externalReference' => null,
            'deleted'           => false,
            'creditCard'        => ['creditCardNumber' => '4242', 'creditCardBrand' => 'VISA', 'creditCardToken' => 'tok_nao_guardado'],
            ...$payment,
        ],
    ];
}

function hcSubscriptionEvent(string $event, string $id = 'sub_new', array $subscription = []): array
{
    return [
        'id'           => 'evt_' . Str::random(24),
        'event'        => $event,
        'dateCreated'  => now()->format('Y-m-d H:i:s'),
        'subscription' => [
            'object'          => 'subscription',
            'id'              => $id,
            'customer'        => 'cus_000005219613',
            'checkoutSession' => 'chk_1',
            'value'           => 299.9,
            'nextDueDate'     => '2026-10-08',
            'cycle'           => 'MONTHLY',
            'billingType'     => 'CREDIT_CARD',
            'status'          => 'ACTIVE',
            'deleted'         => false,
            ...$subscription,
        ],
    ];
}

function hcCheckoutEvent(string $event, string $id = 'chk_1'): array
{
    return [
        'id'          => 'evt_' . Str::random(24),
        'event'       => $event,
        'dateCreated' => now()->format('Y-m-d H:i:s'),
        'checkout'    => ['id' => $id, 'status' => str_replace('CHECKOUT_', '', $event), 'chargeTypes' => ['RECURRENT'], 'billingTypes' => ['CREDIT_CARD']],
    ];
}

function hcOpenCard(Invoice $invoice): array
{
    return hcAs()->postJson(route('panel.my-subscription.charge', ['invoice' => $invoice->id]), ['method' => 'credit_card'])
        ->assertOk()
        ->json('data');
}

describe('abertura do checkout', function () {
    it('fatura do plano: checkout RECURRENT no cartão com valor, ciclo, 1º vencimento, cliente, referência e as URLs de volta', function () {
        [$subscription, $invoice] = hcPaying();

        $data = hcOpenCard($invoice);

        expect($data['mode'])->toBe('hosted')
            ->and($data['recurrent'])->toBeTrue()
            ->and($data['checkout_url'])->toBe('https://asaas.com/checkoutSession/show?id=chk_1')
            // Cobrado no vencimento da fatura (a recorrência nasce alinhada ao período).
            ->and($data['first_charge_on'])->toBe('2026-10-08');

        Http::assertSent(function (Request $r) use ($subscription, $invoice) {
            if ($r->url() !== 'https://api.asaas.com/v3/checkouts') {
                return false;
            }

            return $r['billingTypes'] === ['CREDIT_CARD']
                && $r['chargeTypes'] === ['RECURRENT']
                && $r['subscription'] === ['cycle' => 'MONTHLY', 'nextDueDate' => '2026-10-08']
                && $r['minutesToExpire'] === 60
                && $r['customer'] === 'cus_000005219613'
                && $r['externalReference'] === "easyeye:sub:{$subscription->id}:inv:{$invoice->id}"
                && $r['items'][0]['value'] === 299.9
                && $r['items'][0]['quantity'] === 1
                && mb_strlen($r['items'][0]['name']) <= 30
                && str_contains($r['callback']['successUrl'], 'checkout_return=success')
                && str_contains($r['callback']['cancelUrl'], 'checkout_return=cancel')
                && str_contains($r['callback']['expiredUrl'], 'checkout_return=expired')
                && str_contains($r['callback']['successUrl'], (string) $invoice->id)
                && ! array_key_exists('creditCard', $r->data());
        });

        $checkout = HostedCheckout::query()->sole();
        expect($checkout->kind)->toBe(HostedCheckout::KIND_RECURRENT)
            ->and($checkout->status)->toBe(HostedCheckout::STATUS_ACTIVE)
            ->and($checkout->replaces_recurrence_id)->toBe('sub_old')
            ->and($checkout->replaces_charge_id)->toBe('pay_old')
            ->and($checkout->next_due_date->toDateString())->toBe('2026-10-08');

        // Abrir de novo (ainda válido) reaproveita — não cria outro no Asaas.
        hcOpenCard($invoice);
        Http::assertSentCount(1);
    });

    it('a leitura (GET) nunca abre checkout; o já aberto é devolvido', function () {
        [, $invoice] = hcPaying();

        hcAs()->getJson(route('panel.my-subscription.instructions', ['invoice' => $invoice->id, 'method' => 'credit_card']))
            ->assertOk()
            ->assertJsonPath('data.mode', 'hosted')
            ->assertJsonPath('data.issue_required', true);
        Http::assertNothingSent();

        hcOpenCard($invoice);

        hcAs()->getJson(route('panel.my-subscription.instructions', ['invoice' => $invoice->id, 'method' => 'credit_card']))
            ->assertOk()
            ->assertJsonPath('data.checkout_url', 'https://asaas.com/checkoutSession/show?id=chk_1');
        Http::assertSentCount(1);
    });

    it('a forma cartão aparece como "ambiente seguro" nas opções do gateway', function () {
        hcPaying();

        $methods = collect(hcAs()->getJson(route('panel.my-subscription.summary'))->assertOk()->json('data.payment.methods'));

        expect($methods->firstWhere('method', 'credit_card')['mode'])->toBe('hosted')
            ->and($methods->firstWhere('method', 'pix')['mode'])->toBe('transparent');
    });

    it('recusa da API ao criar o checkout: erro do gateway para a tela, nada gravado', function () {
        [, $invoice] = hcPaying();
        hcFake(['POST https://api.asaas.com/v3/checkouts' => Http::response(['errors' => [['code' => 'invalid_callback', 'description' => 'Domínio não cadastrado']]], 400)]);

        hcAs()->postJson(route('panel.my-subscription.charge', ['invoice' => $invoice->id]), ['method' => 'credit_card'])
            ->assertStatus(502)
            ->assertJsonPath('code', 'gateway_error');

        expect(HostedCheckout::query()->count())->toBe(0)
            ->and(BillingLog::query()->where('message', 'like', 'Checkout hospedado do cartão não foi criado%')->exists())->toBeTrue();
    });
});

describe('troca segura da recorrência', function () {
    it('a antiga só é cancelada depois da 1ª cobrança da nova confirmada; a cobrança antiga da fatura é cancelada', function () {
        [$subscription, $invoice] = hcPaying();
        hcOpenCard($invoice);

        // Checkout concluído: assinatura nova criada e a 1ª cobrança emitida.
        hcPost(hcSubscriptionEvent('SUBSCRIPTION_CREATED'));
        hcPost(hcPayment('PAYMENT_CREATED'));
        hcPost(hcCheckoutEvent('CHECKOUT_PAID'));

        // Ainda não confirmada: nada trocado nem cancelado (nunca "nenhuma").
        expect($subscription->fresh()->gateway_subscription_id)->toBe('sub_old')
            ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Pending)
            ->and($invoice->fresh()->external_invoice_id)->toBe('pay_old')
            ->and(HostedCheckout::query()->sole()->status)->toBe(HostedCheckout::STATUS_PAID)
            ->and(Payment::query()->where('external_payment_id', 'pay_new')->exists())->toBeFalse();
        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE');

        // Tela: a fatura aparece como "aguardando confirmação".
        $open = collect(hcAs()->getJson(route('panel.my-subscription.summary'))->json('data.open_invoices'));
        expect($open->firstWhere('id', $invoice->id)['awaiting_confirmation'])->toBeTrue();

        // 1ª cobrança confirmada: a nova é a da assinatura, a antiga sai.
        hcPost(hcPayment('PAYMENT_CONFIRMED', payment: ['status' => 'CONFIRMED', 'confirmedDate' => '2026-10-05']));

        $subscription->refresh();
        $invoice->refresh();

        expect($subscription->gateway_subscription_id)->toBe('sub_new')
            ->and($subscription->payment_method)->toBe('credit_card')
            ->and($subscription->card_brand)->toBe('visa')
            ->and($subscription->card_last4)->toBe('4242')
            ->and($subscription->gateway_card_id)->toBeNull()
            ->and($subscription->status)->toBe(SubscriptionStatus::Active)
            ->and($subscription->ends_at->toDateTimeString())->toBe('2026-11-08 23:59:59')
            ->and($subscription->recurrenceCancelledByUs('sub_old'))->toBeTrue()
            ->and($invoice->status)->toBe(InvoiceStatus::Paid)
            ->and(data_get($invoice->metadata, 'paid_by_charge'))->toBe('pay_new')
            ->and(data_get($invoice->metadata, 'cancelled_charges'))->toContain('pay_old')
            ->and(HostedCheckout::query()->sole()->status)->toBe(HostedCheckout::STATUS_COMPLETED);

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'https://api.asaas.com/v3/subscriptions/sub_old');
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'https://api.asaas.com/v3/payments/pay_old');
        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), 'sub_new'));
        // Recorrência já nasceu no vencimento do período: nada a alinhar.
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PUT');

        // O aviso do Asaas da remoção da antiga não vira alerta nem desfaz a nova.
        hcPost(hcSubscriptionEvent('SUBSCRIPTION_DELETED', 'sub_old', ['checkoutSession' => null, 'status' => 'INACTIVE', 'deleted' => true]));
        hcPost(hcPayment('PAYMENT_RECEIVED', payment: ['status' => 'RECEIVED']));

        expect($subscription->fresh()->gateway_subscription_id)->toBe('sub_new')
            ->and($subscription->fresh()->recurrence_alert_at)->toBeNull()
            ->and(Payment::query()->where('external_payment_id', 'pay_new')->count())->toBe(1);

        // Próximo ciclo da recorrência adotada segue o fluxo normal.
        $this->travelTo(CarbonImmutable::parse('2026-11-08 09:00:00'));
        hcPost(hcPayment('PAYMENT_RECEIVED', 'pay_next', ['status' => 'RECEIVED', 'dueDate' => '2026-11-08']));

        expect($subscription->fresh()->ends_at->toDateTimeString())->toBe('2026-12-08 23:59:59');
    });

    it('pagou atrasado: a recorrência nova nasce hoje e o próximo vencimento é alinhado ao período no Asaas', function () {
        [$subscription, $invoice] = hcPaying(
            ['status'       => SubscriptionStatus::PastDue, 'ends_at' => '2026-10-01 23:59:59', 'next_billing_at' => '2026-10-01 23:59:59', 'past_due_at' => '2026-10-01 23:59:59'],
            ['period_start' => '2026-10-01', 'period_end' => '2026-11-01', 'due_at' => '2026-10-01 23:59:59', 'status' => InvoiceStatus::Overdue->value],
        );
        hcFake(['PUT https://api.asaas.com/v3/subscriptions/sub_new' => Http::response(['id' => 'sub_new', 'nextDueDate' => '2026-11-01'])]);

        hcOpenCard($invoice);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.asaas.com/v3/checkouts' && $r['subscription']['nextDueDate'] === '2026-10-05');

        hcPost(hcSubscriptionEvent('SUBSCRIPTION_CREATED', subscription: ['nextDueDate' => '2026-10-05']));
        hcPost(hcPayment('PAYMENT_CONFIRMED', payment: ['status' => 'CONFIRMED', 'dueDate' => '2026-10-05']));

        expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Active)
            ->and($subscription->fresh()->ends_at->toDateTimeString())->toBe('2026-11-01 23:59:59');

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
            && $r->url() === 'https://api.asaas.com/v3/subscriptions/sub_new'
            && $r['nextDueDate'] === '2026-11-01');
    });

    it('sem como alinhar pela API (tokenização desabilitada): o time é avisado para ajustar no painel', function () {
        [, $invoice] = hcPaying(
            ['status'       => SubscriptionStatus::PastDue, 'ends_at' => '2026-10-01 23:59:59', 'next_billing_at' => '2026-10-01 23:59:59', 'past_due_at' => '2026-10-01 23:59:59'],
            ['period_start' => '2026-10-01', 'period_end' => '2026-11-01', 'due_at' => '2026-10-01 23:59:59', 'status' => InvoiceStatus::Overdue->value],
        );
        hcFake(['PUT https://api.asaas.com/v3/subscriptions/sub_new' => Http::response(['errors' => [['code' => 'invalid_action', 'description' => 'Tokenização não habilitada']]], 400)]);

        hcOpenCard($invoice);
        hcPost(hcSubscriptionEvent('SUBSCRIPTION_CREATED', subscription: ['nextDueDate' => '2026-10-05']));
        hcPost(hcPayment('PAYMENT_CONFIRMED', payment: ['status' => 'CONFIRMED', 'dueDate' => '2026-10-05']));

        expect(BillingLog::query()->where('level', 'critical')->get()->contains(fn ($log) => data_get($log->context, 'alert') === 'recurrence_alignment'))->toBeTrue();
        Notification::assertSentTo($this->saasAdmin, GatewayOperationalAlertNotification::class, fn ($n) => $n->kind === 'recurrence_alignment' && $n->params['to'] === '2026-11-01');
    });

    it('cartão recusado na 1ª cobrança da nova: ela é desfeita e a fatura segue com a cobrança antiga (a recorrência antiga continua)', function () {
        [$subscription, $invoice] = hcPaying();
        hcOpenCard($invoice);

        hcPost(hcSubscriptionEvent('SUBSCRIPTION_CREATED'));
        hcPost(hcPayment('PAYMENT_REPROVED_BY_RISK_ANALYSIS', payment: ['status' => 'PENDING']));

        expect($subscription->fresh()->gateway_subscription_id)->toBe('sub_old')
            ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Pending)
            ->and($invoice->fresh()->external_invoice_id)->toBe('pay_old')
            ->and(HostedCheckout::query()->sole()->status)->toBe(HostedCheckout::STATUS_FAILED);

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'https://api.asaas.com/v3/subscriptions/sub_new');
        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), 'sub_old'));
        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), 'pay_old'));
    });

    it('recusada e depois confirmada (análise de risco): o dinheiro paga a fatura, mas a recorrência desfeita não é adotada', function () {
        [$subscription, $invoice] = hcPaying();
        hcOpenCard($invoice);
        hcPost(hcSubscriptionEvent('SUBSCRIPTION_CREATED'));
        hcPost(hcPayment('PAYMENT_REPROVED_BY_RISK_ANALYSIS'));

        hcPost(hcPayment('PAYMENT_CONFIRMED', payment: ['status' => 'CONFIRMED']));

        expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
            ->and(data_get($invoice->fresh()->metadata, 'paid_by_charge'))->toBe('pay_new')
            ->and($subscription->fresh()->gateway_subscription_id)->toBe('sub_old');
        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), 'sub_old'));
    });

    it('a fatura foi paga pelo Pix antigo antes: a recorrência nova é desfeita e o checkout cancelado (sem cobrar duas vezes)', function () {
        [$subscription, $invoice] = hcPaying();
        hcOpenCard($invoice);
        hcPost(hcSubscriptionEvent('SUBSCRIPTION_CREATED'));

        // Pagou a cobrança antiga (da recorrência boleto/Pix).
        hcPost([
            'id'          => 'evt_' . Str::random(24),
            'event'       => 'PAYMENT_RECEIVED',
            'dateCreated' => now()->format('Y-m-d H:i:s'),
            'payment'     => [
                'object'            => 'payment', 'id' => 'pay_old', 'customer' => 'cus_000005219613', 'subscription' => 'sub_old',
                'value'             => 299.9, 'billingType' => 'PIX', 'status' => 'RECEIVED', 'dueDate' => '2026-10-08',
                'externalReference' => $subscription->id, 'deleted' => false,
            ],
        ]);

        expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
            ->and($subscription->fresh()->gateway_subscription_id)->toBe('sub_old')
            ->and(HostedCheckout::query()->sole()->status)->toBe(HostedCheckout::STATUS_SUPERSEDED);

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'https://api.asaas.com/v3/subscriptions/sub_new');
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'https://api.asaas.com/v3/checkouts/chk_1/cancel');
        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), 'sub_old'));
    });

    it('checkout cancelado ou expirado: só a situação dele muda', function (string $event, string $status) {
        [$subscription, $invoice] = hcPaying();
        hcOpenCard($invoice);

        hcPost(hcCheckoutEvent($event));

        expect(HostedCheckout::query()->sole()->status)->toBe($status)
            ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Pending)
            ->and($subscription->fresh()->gateway_subscription_id)->toBe('sub_old');
        Http::assertSentCount(1);
    })->with([
        ['CHECKOUT_CANCELED', HostedCheckout::STATUS_CANCELED],
        ['CHECKOUT_EXPIRED', HostedCheckout::STATUS_EXPIRED],
    ]);
});

describe('cobranças avulsas no cartão (DETACHED)', function () {
    it('pacote de créditos de IA: checkout avulso e créditos na confirmação', function () {
        hcPaying(['ends_at' => '2026-11-08 23:59:59', 'next_billing_at' => '2026-11-08 23:59:59'], ['status' => InvoiceStatus::Paid->value, 'paid_at' => '2026-10-04 10:00:00']);
        hcFake(['POST https://api.asaas.com/v3/checkouts' => Http::response(['id' => 'chk_pack', 'status' => 'ACTIVE', 'minutesToExpire' => 60])]);

        $data = hcAs()->postJson(route('panel.my-subscription.ai-credits.purchase'), ['package_code' => 'operational', 'method' => 'credit_card'])
            ->assertOk()
            ->json('data');

        expect($data['mode'])->toBe('hosted')
            ->and($data['checkout_url'])->toBe('https://asaas.com/checkoutSession/show?id=chk_pack');

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.asaas.com/v3/checkouts'
            && $r['chargeTypes'] === ['DETACHED']
            && ! array_key_exists('subscription', $r->data())
            && $r['items'][0]['value'] === 249.9);

        $packInvoice = Invoice::query()->where('billing_reason', Invoice::BILLING_REASON_AI_CREDIT_PACK)->sole();

        hcPost(hcPayment('PAYMENT_CONFIRMED', 'pay_pack', ['subscription' => null, 'checkoutSession' => 'chk_pack', 'value' => 249.9, 'status' => 'CONFIRMED', 'dueDate' => '2026-10-05']));

        expect($packInvoice->fresh()->status)->toBe(InvoiceStatus::Paid)
            ->and(AiCreditPurchase::query()->sole()->status)->toBe(AiCreditPurchaseStatus::Credited)
            ->and((int) AiCreditWallet::query()->where('entity_id', $this->clinic->id)->value('balance'))->toBe(100)
            ->and(HostedCheckout::query()->where('external_checkout_id', 'chk_pack')->value('status'))->toBe(HostedCheckout::STATUS_COMPLETED);
    });

    it('diferença do upgrade: checkout avulso e o plano muda quando confirmado', function () {
        // Só a recriação da recorrência fica na fila (o webhook processa na hora).
        Queue::fake([RecreateGatewayRecurrenceJob::class]);
        $premium = Plan::factory()->create(['name' => 'Premium', 'billing_cycle' => 'monthly', 'price' => 599.90, 'active' => true]);
        PlanPrice::create(['plan_id' => $premium->id, 'billing_cycle' => 'monthly', 'price' => 599.90]);
        [$subscription] = hcPaying(
            ['last_payment_at' => '2026-10-01 10:00:00', 'starts_at' => '2026-10-01 10:00:00', 'ends_at' => '2026-11-01 23:59:59', 'next_billing_at' => '2026-11-01 23:59:59'],
            ['period_start' => '2026-10-01', 'period_end' => '2026-11-01', 'status' => InvoiceStatus::Paid->value, 'paid_at' => '2026-10-01 10:00:00'],
        );
        hcFake(['POST https://api.asaas.com/v3/checkouts' => Http::response(['id' => 'chk_upg', 'status' => 'ACTIVE'])]);

        $data = hcAs()->postJson(route('panel.my-subscription.contract'), ['plan_id' => $premium->id, 'billing_cycle' => 'monthly', 'method' => 'credit_card'])
            ->assertOk()
            ->json('data');

        expect($data['mode'])->toBe('hosted')->and($data['recurrent'])->toBeFalse();

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.asaas.com/v3/checkouts'
            && $r['chargeTypes'] === ['DETACHED'] && ! array_key_exists('subscription', $r->data()));

        $upgrade = Invoice::query()->where('billing_reason', Invoice::BILLING_REASON_PLAN_CHANGE)->sole();

        hcPost(hcPayment('PAYMENT_CONFIRMED', 'pay_upg', ['subscription' => null, 'checkoutSession' => 'chk_upg', 'value' => (float) $upgrade->amount, 'status' => 'CONFIRMED', 'dueDate' => '2026-10-05']));

        expect($upgrade->fresh()->status)->toBe(InvoiceStatus::Paid)
            ->and($subscription->fresh()->plan_id)->toBe($premium->id);
        Queue::assertPushed(RecreateGatewayRecurrenceJob::class);
    });
});
