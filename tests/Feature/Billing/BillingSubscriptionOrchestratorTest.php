<?php

use App\Enums\Billing\{InvoiceStatus, PaymentStatus};
use App\Enums\{BillingCycle, SaasRule, SubscriptionBillingMode, SubscriptionStatus};
use App\Exceptions\Billing\GatewayIntegrationException;
use App\Models\Billing\{GatewayFallbackRule, Invoice, Payment, PaymentAttempt, SubscriptionChange};
use App\Models\{Entity, Plan, PlanPrice, Subscription, User};
use App\Services\Billing\{BillingSubscriptionOrchestrator, GatewayCredentialResolver, GatewayRegistry, ProcessWebhookEventService, WebhookIngestionService};
use App\Services\Billing\Gateways\AbstractHttpGateway;
use App\Services\{ManagerDashboardService, SubscriptionService};
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{Http, Queue};
use Illuminate\Validation\ValidationException;

/**
 * Ativação pela cobrança automática (manager → "Cobrança automática"):
 * a vigente só é substituída depois do sucesso no gateway, o Asaas não recebe
 * cobrança avulsa (a assinatura dele já emite a 1ª) e a contratação dá acesso
 * até o fim do dia do vencimento da 1ª cobrança.
 */
beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-05 10:00:00'));
    Http::preventStrayRequests();
    Queue::fake();

    $this->clinic = orchClinic();
    $this->plan   = orchPlan(['monthly' => 299.90, 'yearly' => 2878.99]);
});

afterEach(fn () => Carbon::setTestNow());

function orchClinic(): Entity
{
    $entity                = Entity::factory()->make(['is_client' => true, 'active' => true, 'name' => 'Clínica Olhar']);
    $entity->skipAutoTrial = true;
    $entity->save();

    return $entity;
}

/** @param array<string, float> $prices */
function orchPlan(array $prices): Plan
{
    $default = array_key_first($prices);
    $plan    = Plan::factory()->create(['name' => 'Pro', 'billing_cycle' => $default, 'price' => $prices[$default], 'active' => true]);

    foreach ($prices as $cycle => $price) {
        PlanPrice::create(['plan_id' => $plan->id, 'billing_cycle' => $cycle, 'price' => $price]);
    }

    return $plan->fresh('prices');
}

/** Respostas reais do Asaas (sandbox) para cliente e assinatura; o resto é 404. */
function orchFakeAsaas(int $subscriptionStatus = 200): void
{
    Http::fake(function (Request $request) use ($subscriptionStatus) {
        $url = $request->url();

        if (str_starts_with($url, 'https://api.asaas.com/v3/customers')) {
            return $request->method() === 'GET'
                ? Http::response(['object' => 'list', 'hasMore' => false, 'totalCount' => 0, 'limit' => 10, 'offset' => 0, 'data' => []])
                : Http::response(['object' => 'customer', 'id' => 'cus_000005219613', 'name' => 'Clínica Olhar']);
        }

        if ($url === 'https://api.asaas.com/v3/subscriptions' && $request->method() === 'POST') {
            return $subscriptionStatus === 200
                ? Http::response([
                    'object'            => 'subscription',
                    'id'                => 'sub_VXJBYgP2u0eO',
                    'dateCreated'       => '2026-10-05',
                    'customer'          => 'cus_000005219613',
                    'billingType'       => 'BOLETO',
                    'cycle'             => $request['cycle'],
                    'value'             => $request['value'],
                    'nextDueDate'       => $request['nextDueDate'],
                    'description'       => $request['description'],
                    'status'            => 'ACTIVE',
                    'deleted'           => false,
                    'externalReference' => $request['externalReference'],
                ])
                : Http::response(['errors' => [['code' => 'invalid_customer', 'description' => 'Cliente inválido.']]], $subscriptionStatus);
        }

        if (str_starts_with($url, 'https://api.asaas.com/v3/subscriptions/') && $request->method() === 'DELETE') {
            return Http::response(['deleted' => true, 'id' => basename($url)]);
        }

        return Http::response(['errors' => [['code' => 'not_faked']]], 404);
    });
}

function orchActivate(BillingCycle $cycle = BillingCycle::Monthly, string $gateway = 'asaas'): Subscription
{
    return app(BillingSubscriptionOrchestrator::class)->activateWithGateway(test()->clinic, test()->plan, $cycle, $gateway);
}

it('Asaas: emite a assinatura com o 1º vencimento e não cria cobrança avulsa em /v3/payments', function () {
    orchFakeAsaas();

    $subscription = orchActivate();

    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.asaas.com/v3/subscriptions'
        && $r['nextDueDate'] === '2026-10-08'
        && (float) $r['value'] === 299.90
        && $r['cycle'] === 'MONTHLY'
        && $r['externalReference'] === $subscription->id);
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/v3/payments'));

    $invoice = $subscription->currentInvoice;

    expect($subscription->status)->toBe(SubscriptionStatus::PastDue)
        ->and($subscription->billing_state)->toBe('pending_activation')
        ->and($subscription->billing_mode)->toBe(SubscriptionBillingMode::Gateway)
        ->and($subscription->gateway)->toBe('asaas')
        ->and($subscription->gateway_subscription_id)->toBe('sub_VXJBYgP2u0eO')
        ->and($subscription->next_billing_at->toDateTimeString())->toBe('2026-10-08 23:59:59')
        ->and($subscription->ends_at->toDateTimeString())->toBe('2026-10-08 23:59:59')
        ->and($subscription->last_payment_at)->toBeNull()
        ->and($subscription->past_due_at)->toBeNull()
        ->and($invoice->status)->toBe(InvoiceStatus::Pending)
        ->and($invoice->period_start->toDateString())->toBe('2026-10-08')
        ->and($invoice->period_end->toDateString())->toBe('2026-11-08')
        ->and($invoice->due_at->toDateTimeString())->toBe('2026-10-08 23:59:59')
        ->and((float) $invoice->amount)->toBe(299.90)
        // A 1ª cobrança é a 1ª parcela da assinatura: o webhook dela cria o pagamento.
        ->and(Payment::count())->toBe(0)
        ->and(PaymentAttempt::count())->toBe(0);
});

it('PAYMENT_CREATED da 1ª parcela que chega antes do fim da ativação liga a fatura e não é sobrescrito', function () {
    config(['billing.gateways.asaas.webhook_secret' => 'whsec_asaas_teste']);

    Http::fake(function (Request $request) {
        $url = $request->url();

        if (str_starts_with($url, 'https://api.asaas.com/v3/customers')) {
            return Http::response($request->method() === 'GET' ? ['data' => []] : ['id' => 'cus_000005219613']);
        }

        // O Asaas avisa a 1ª parcela antes de a resposta da criação voltar.
        $body = json_encode([
            'id'      => 'evt_corrida_primeira_parcela',
            'event'   => 'PAYMENT_CREATED',
            'payment' => [
                'object'            => 'payment',
                'id'                => 'pay_corrida01',
                'subscription'      => 'sub_VXJBYgP2u0eO',
                'value'             => 299.9,
                'status'            => 'PENDING',
                'billingType'       => 'BOLETO',
                'dueDate'           => $request['nextDueDate'],
                'invoiceUrl'        => 'https://www.asaas.com/i/corrida01',
                'externalReference' => $request['externalReference'],
            ],
        ]);
        $event = app(WebhookIngestionService::class)->ingest('asaas', ['asaas-access-token' => 'whsec_asaas_teste'], $body);
        app(ProcessWebhookEventService::class)->process($event);

        return Http::response(['id' => 'sub_VXJBYgP2u0eO', 'status' => 'ACTIVE', 'customer' => 'cus_000005219613']);
    });

    $subscription = orchActivate();
    $invoice      = $subscription->currentInvoice;

    expect($invoice->external_invoice_id)->toBe('pay_corrida01')
        ->and($invoice->payment_url)->toBe('https://www.asaas.com/i/corrida01')
        ->and($invoice->status)->toBe(InvoiceStatus::Pending)
        ->and(Payment::sole()->invoice_id)->toBe($invoice->id)
        ->and($subscription->gateway_subscription_id)->toBe('sub_VXJBYgP2u0eO')
        ->and($subscription->billing_state)->toBe('pending_activation');
});

it('trial: o acesso segue até o fim do dia do vencimento da 1ª cobrança e a nova só vira ativa no pagamento', function () {
    orchFakeAsaas();
    $trial = Subscription::factory()->trial(10)->for($this->clinic)->for($this->plan)->create();

    $subscription = orchActivate();
    $service      = app(SubscriptionService::class);

    // Sucesso no gateway: o trial é substituído e a troca entra no histórico.
    $trial->refresh();
    $change = SubscriptionChange::where('subscription_id', $subscription->id)->sole();

    expect($trial->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($trial->cancelled_reason)->toBe('replaced')
        ->and($change->change_type)->toBe('activation')
        ->and($change->metadata['replaced_subscription_ids'])->toBe([$trial->id])
        ->and($change->metadata['previous']['status'])->toBe('trial')
        ->and($subscription->status)->toBe(SubscriptionStatus::PastDue)
        ->and($service->hasAccess($this->clinic))->toBeTrue()
        ->and($service->getCurrent($this->clinic)?->id)->toBe($subscription->id);

    // Fim do dia do vencimento: ainda tem acesso (regra PHP e SQL iguais).
    $this->travelTo(CarbonImmutable::parse('2026-10-08 23:59:00'));
    expect($subscription->fresh()->hasAccess())->toBeTrue()
        ->and($service->hasAccess($this->clinic))->toBeTrue();

    // Venceu sem pagamento: sem acesso, sem dias de graça e sem régua.
    $this->travelTo(CarbonImmutable::parse('2026-10-09 00:00:01'));
    expect($subscription->fresh()->hasAccess())->toBeFalse()
        ->and($service->hasAccess($this->clinic))->toBeFalse()
        ->and($subscription->fresh()->status)->toBe(SubscriptionStatus::PastDue);
});

it('troca de plano: a vigente paga é substituída e a recorrência antiga para no gateway só depois do sucesso', function () {
    orchFakeAsaas();
    $old = Subscription::factory()->gateway()->for($this->clinic)->for($this->plan)->create([
        'gateway_subscription_id' => 'sub_antiga_mensal',
        'last_payment_at'         => now()->subDays(20),
    ]);

    $new = orchActivate(BillingCycle::Yearly);

    expect($old->fresh()->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($old->fresh()->billing_state)->toBe('cancelled')
        ->and((float) $new->amount)->toBe(2878.99);

    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/v3/subscriptions/sub_antiga_mensal'));
    Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/v3/subscriptions/sub_VXJBYgP2u0eO'));
});

it('falha no gateway mantém a vigente anterior intacta; a tentativa não dá acesso nem entra no MRR', function () {
    orchFakeAsaas(subscriptionStatus: 500);
    $current = Subscription::factory()->gateway()->for($this->clinic)->for($this->plan)->create([
        'gateway_subscription_id' => 'sub_vigente',
        'billing_cycle'           => BillingCycle::Monthly,
        'amount'                  => 299.90,
        'last_payment_at'         => now()->subDays(5),
        'ends_at'                 => now()->addDays(25),
    ]);

    expect(fn () => orchActivate(BillingCycle::Yearly))->toThrow(GatewayIntegrationException::class);

    $attempt = Subscription::whereKeyNot($current->id)->sole();

    expect($current->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($current->fresh()->ends_at->equalTo(now()->addDays(25)))->toBeTrue()
        ->and($attempt->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($attempt->cancelled_reason)->toBe('activation_failed')
        ->and($attempt->billing_state)->toBe('error')
        ->and($attempt->last_billing_error)->not->toBeEmpty()
        ->and($attempt->hasAccess())->toBeFalse()
        ->and($attempt->currentInvoice->status)->toBe(InvoiceStatus::Failed)
        ->and(app(SubscriptionService::class)->currentInForce($this->clinic)?->id)->toBe($current->id)
        ->and(app(ManagerDashboardService::class)->getFinancialKpis()['mrr'])->toBe(299.90);

    // A recorrência vigente continua cobrando: nenhum cancelamento no gateway.
    Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE');
});

it('success=false na assinatura (4xx) também é falha: o trial continua valendo', function () {
    orchFakeAsaas(subscriptionStatus: 400);
    $trial = Subscription::factory()->trial(5)->for($this->clinic)->for($this->plan)->create();

    expect(fn () => orchActivate())->toThrow(GatewayIntegrationException::class);

    expect($trial->fresh()->status)->toBe(SubscriptionStatus::Trial)
        ->and($trial->fresh()->hasAccess())->toBeTrue()
        ->and(app(SubscriptionService::class)->getCurrent($this->clinic)?->id)->toBe($trial->id);
});

it('recusa da cobrança avulsa (InfinitePay 422 no POST /links) mantém a vigente e não deixa a tentativa vigente', function () {
    config(['billing.gateways.infinitepay.handle' => 'easyeye']);
    Http::fake([
        'https://api.checkout.infinitepay.io/links' => Http::response(['message' => 'Dados inválidos.'], 422),
    ]);
    $courtesy = Subscription::factory()->complimentary()->for($this->clinic)->for($this->plan)->create(['ends_at' => now()->addDays(2)]);

    expect(fn () => orchActivate(BillingCycle::Monthly, 'infinitepay'))->toThrow(GatewayIntegrationException::class);

    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.checkout.infinitepay.io/links' && $r['handle'] === 'easyeye');

    expect($courtesy->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and(Subscription::whereKeyNot($courtesy->id)->sole()->status)->toBe(SubscriptionStatus::Cancelled)
        ->and(Payment::count())->toBe(0);
});

it('cobrança paga na hora (Pagar.me devolve a cobrança paga no pedido) ativa já: vigência = vencimento + ciclo e substitui a anterior', function () {
    // A InfinitePay só devolve o link (nunca "pago" na resposta): o
    // comportamento fica coberto por um gateway que devolve o status.
    config(['billing.gateways.pagarme.secret' => 'sk_test_easyeye']);
    Http::fake([
        'https://api.pagar.me/core/v5/customers' => Http::response(['id' => 'cus_oy23JRQCM1cvzlmD']),
        'https://api.pagar.me/core/v5/orders'    => Http::response([
            'id'      => 'or_9f8e7d6c5b4a',
            'amount'  => 29990,
            'status'  => 'paid',
            'charges' => [['id' => 'ch_9f8e7d6c5b4a', 'amount' => 29990, 'status' => 'paid', 'payment_method' => 'pix']],
        ]),
    ]);
    $trial = Subscription::factory()->trial(3)->for($this->clinic)->for($this->plan)->create();

    $subscription = orchActivate(BillingCycle::Monthly, 'pagarme');

    $payment = Payment::sole();

    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->billing_state)->toBe('paid')
        ->and($subscription->gateway_subscription_id)->toBeNull()
        ->and($subscription->gateway_customer_id)->toBe('cus_oy23JRQCM1cvzlmD')
        ->and($subscription->last_payment_at)->not->toBeNull()
        ->and($subscription->ends_at->toDateTimeString())->toBe('2026-11-08 23:59:59')
        ->and($subscription->next_billing_at->toDateTimeString())->toBe('2026-11-08 23:59:59')
        ->and($subscription->currentInvoice->status)->toBe(InvoiceStatus::Paid)
        ->and($payment->status)->toBe(PaymentStatus::Paid)
        ->and($payment->external_payment_id)->toBe('ch_9f8e7d6c5b4a')
        ->and(PaymentAttempt::sole()->payment_id)->toBe($payment->id)
        ->and($trial->fresh()->status)->toBe(SubscriptionStatus::Cancelled);

    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.pagar.me/core/v5/orders'
        && $r['items'][0]['amount'] === 29990
        && $r['code'] === $subscription->current_invoice_id);
});

it('cliente e cobrança levam o endereço do cadastro da empresa: o boleto do PagBank sai com o holder.address', function () {
    $this->clinic->update(['zipcode' => '01310-100', 'address' => 'Av. Paulista', 'number' => '1000', 'complement' => 'Sala 5', 'district' => 'Bela Vista', 'city' => 'São Paulo', 'state' => 'SP', 'national_registration' => '11222333000181', 'cellphone' => '11987654321']);
    config(['billing.gateways.pagbank.secret' => 'tok_pagbank']);
    Http::fake(['https://api.pagseguro.com/orders' => Http::response([
        'id'      => 'ORDE_F87334AC-BB8B-42E2-AA85-8579F70AA328',
        'charges' => [['id' => 'CHAR_67D0D02B-4F1C-41C1-B47C-D52E0E2B6CA9', 'status' => 'WAITING', 'amount' => ['value' => 29990, 'currency' => 'BRL']]],
    ])]);

    orchActivate(BillingCycle::Monthly, 'pagbank');

    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.pagseguro.com/orders'
        && $r['charges'][0]['payment_method']['type'] === 'BOLETO'
        && $r['charges'][0]['payment_method']['boleto']['holder']['address']['postal_code'] === '01310100'
        && $r['charges'][0]['payment_method']['boleto']['holder']['address']['street'] === 'AV. PAULISTA'
        && $r['charges'][0]['payment_method']['boleto']['holder']['address']['region_code'] === 'SP'
        && $r['customer']['phones'][0]['number'] === '987654321');
});

it('gateway com recorrência própria que não emite a 1ª cobrança: cobrança recusada cancela a recorrência órfã', function () {
    // Nenhum gateway de hoje tem essa combinação (o Asaas emite a 1ª cobrança
    // junto; Mercado Pago e InfinitePay não criam recorrência): um gateway de
    // teste com os comportamentos padrão do AbstractHttpGateway.
    config(['billing.gateways.recorrente_teste' => [
        'base_url'  => 'https://api.recorrente.test',
        'secret'    => 'sk_test_recorrente',
        'endpoints' => [
            'customers'           => '/customers',
            'subscriptions'       => '/subscriptions',
            'subscription_cancel' => '/subscriptions/{id}',
            'charges'             => '/charges',
            'payments'            => '/charges/{id}',
        ],
    ]]);

    $gateway = new class(app(GatewayCredentialResolver::class)) extends AbstractHttpGateway {
        public function code(): string
        {
            return 'recorrente_teste';
        }
    };
    app()->instance(GatewayRegistry::class, new GatewayRegistry(['recorrente_teste' => $gateway]));

    Http::fake([
        'https://api.recorrente.test/customers'       => Http::response(['id' => 'cus_7Hq2Lm']),
        'https://api.recorrente.test/subscriptions'   => Http::response(['id' => 'sub_orfa_9f8e', 'status' => 'active']),
        'https://api.recorrente.test/charges'         => Http::response(['message' => 'internal_error'], 500),
        'https://api.recorrente.test/subscriptions/*' => Http::response(['id' => 'sub_orfa_9f8e', 'status' => 'canceled']),
    ]);
    $trial = Subscription::factory()->trial(5)->for($this->clinic)->for($this->plan)->create();

    expect(fn () => orchActivate(BillingCycle::Monthly, 'recorrente_teste'))->toThrow(GatewayIntegrationException::class);

    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE'
        && $r->url() === 'https://api.recorrente.test/subscriptions/sub_orfa_9f8e');

    expect($trial->fresh()->status)->toBe(SubscriptionStatus::Trial)
        ->and(Invoice::sole()->status)->toBe(InvoiceStatus::Failed);
});

it('o manager recebe o erro do gateway (502) e a clínica segue com a vigente', function () {
    orchFakeAsaas(subscriptionStatus: 500);
    $trial = Subscription::factory()->trial(5)->for($this->clinic)->for($this->plan)->create();

    $saas  = Entity::factory()->create(['is_client' => false, 'active' => true]);
    $admin = User::factory()->create();
    createEntityUser($saas, $admin, SaasRule::Admin->value);

    $this->actingAs($admin)->withSession([
        'selected_entity_id'        => $saas->id,
        'selected_entity_is_client' => false,
        'selected_entity_user_rule' => SaasRule::Admin->value,
    ])->postJson(route('manager.subscriptions.store'), [
        'entity_id'     => $this->clinic->id,
        'plan_id'       => $this->plan->id,
        'mode'          => 'gateway',
        'billing_cycle' => 'monthly',
        'gateway'       => 'asaas',
    ])->assertStatus(502)->assertJsonPath('message', fn (string $message) => str_contains($message, 'asaas'));

    expect($trial->fresh()->status)->toBe(SubscriptionStatus::Trial)
        ->and(Invoice::sole()->status)->toBe(InvoiceStatus::Failed);
});

it('depois do 502 a vigente da empresa no manager continua sendo a que vale: listagem, resumo, planos, histórico e adicionar período', function () {
    orchFakeAsaas(subscriptionStatus: 500);
    $trial = Subscription::factory()->trial(10)->for($this->clinic)->for($this->plan)->create();
    // Clínica sem assinatura nenhuma cuja 1ª contratação também é recusada.
    $newcomer = orchClinic();

    $saas  = Entity::factory()->create(['is_client' => false, 'active' => true]);
    $admin = User::factory()->create();
    createEntityUser($saas, $admin, SaasRule::Admin->value);
    $manager = fn () => test()->actingAs($admin)->withSession([
        'selected_entity_id'        => $saas->id,
        'selected_entity_is_client' => false,
        'selected_entity_user_rule' => SaasRule::Admin->value,
    ]);

    $this->travel(5)->minutes();

    foreach ([$this->clinic, $newcomer] as $clinic) {
        $manager()->postJson(route('manager.subscriptions.store'), [
            'entity_id'     => $clinic->id,
            'plan_id'       => $this->plan->id,
            'mode'          => 'gateway',
            'billing_cycle' => 'monthly',
            'gateway'       => 'asaas',
        ])->assertStatus(502);
    }

    $failed = Subscription::query()->where('entity_id', $this->clinic->id)->whereKeyNot($trial->id)->sole();

    expect($failed->isFailedActivation())->toBeTrue()
        ->and(Subscription::query()->forEntity((string) $this->clinic->id)->latestPerEntity()->sole()->id)->toBe($trial->id)
        ->and(Subscription::query()->forEntity((string) $newcomer->id)->latestPerEntity()->exists())->toBeFalse();

    // Listagem padrão (uma linha por empresa): o trial, que pode ser alterado.
    $manager()->get(route('manager.subscriptions.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->has('subscriptions.data', 1)
            ->where('subscriptions.data.0.id', $trial->id)
            ->where('subscriptions.data.0.status', 'trial')
            ->where('subscriptions.data.0.has_access', true)
            ->where('subscriptions.data.0.is_current', true)
            ->where('subscriptions.data.0.can_extend', true)
            ->where('subscriptions.data.0.can_change_terms', true)
            ->where('summary.companies', 1)
            ->where('summary.trial', 1)
            ->where('summary.without_access', 0)
            // A recém-chegada só tem a tentativa recusada: sem assinatura.
            ->where('summary.no_subscription', 1));

    // Histórico completo: as tentativas aparecem, mas nunca como a vigente.
    $manager()->get(route('manager.subscriptions.index', ['scope' => 'all']))->assertOk()
        ->assertInertia(fn ($page) => $page->has('subscriptions.data', 3)
            ->where('subscriptions.data', fn ($rows) => collect($rows)->where('is_current', true)->pluck('id')->all() === [$trial->id]));

    $manager()->getJson(route('manager.subscriptions.history', $failed))->assertOk()
        ->assertJsonCount(2, 'data.subscriptions')
        ->assertJsonPath('data.subscriptions.0.id', $failed->id)
        ->assertJsonPath('data.subscriptions.0.is_current', false)
        ->assertJsonPath('data.subscriptions.1.id', $trial->id)
        ->assertJsonPath('data.subscriptions.1.is_current', true);

    $manager()->getJson(route('manager.subscriptions.entities', ['id' => $this->clinic->id]))->assertOk()
        ->assertJsonPath('data.0.current.id', $trial->id)
        ->assertJsonPath('data.0.current.modality', 'trial')
        ->assertJsonPath('data.0.current.has_access', true);

    // Planos: a clínica segue contando como assinante do Pro.
    $manager()->get(route('manager.plans.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('plans.data.0.id', $this->plan->id)->where('plans.data.0.subscribers', 1));
    $manager()->getJson(route('manager.plans.show', $this->plan))->assertOk()
        ->assertJsonPath('data.subscribers.total', 1)
        ->assertJsonPath('data.subscribers.trial', 1);

    // Tentativa recusada não é assinatura nem cancelamento (churn) no painel.
    expect(app(ManagerDashboardService::class)->getSubscriptionKpis()['subscriptionCounts']['cancelled'])->toBe(0)
        ->and(app(ManagerDashboardService::class)->getFinancialKpis()['cancelledThisMonth'])->toBe(0);

    // O trial que continua valendo pode ganhar período e ser alterado.
    $manager()->postJson(route('manager.subscriptions.extend', $trial), [
        'unit' => 'days', 'quantity' => 7, 'reason' => 'Prazo extra enquanto o CPF do responsável é corrigido no Asaas.',
    ])->assertOk();

    expect($trial->fresh()->status)->toBe(SubscriptionStatus::Trial)
        ->and($trial->fresh()->trial_ends_at->toDateString())->toBe(now()->addDays(17)->toDateString());

    // A tentativa recusada continua sem poder ser alterada.
    $manager()->postJson(route('manager.subscriptions.extend', $failed), [
        'unit' => 'days', 'quantity' => 7, 'reason' => 'Prazo extra enquanto o CPF do responsável é corrigido no Asaas.',
    ])->assertUnprocessable();
});

/**
 * Asaas que perde a resposta da criação da assinatura. Estado mutável:
 *  - create: 'timeout', um status HTTP de erro ou 'ok' (cria);
 *  - found: ids que a busca pela referência (GET /v3/subscriptions?externalReference=) devolve;
 *  - searchStatus: status da busca (200 ou erro);
 *  - searched: referências consultadas.
 */
function orchFakeAsaasLostResponse(object $asaas): void
{
    $asaas->searched ??= [];

    Http::fake(function (Request $request) use ($asaas) {
        $url  = $request->url();
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (str_starts_with($url, 'https://api.asaas.com/v3/customers')) {
            return $request->method() === 'GET'
                ? Http::response(['object' => 'list', 'hasMore' => false, 'totalCount' => 0, 'data' => []])
                : Http::response(['object' => 'customer', 'id' => 'cus_000005219613']);
        }

        if ($path === '/v3/subscriptions' && $request->method() === 'POST') {
            return match ($asaas->create) {
                'timeout' => (Http::failedConnection('cURL error 28: Operation timed out after 30001 milliseconds'))($request),
                'ok'      => Http::response([
                    'object'            => 'subscription', 'id' => 'sub_nova_ok', 'customer' => 'cus_000005219613', 'status' => 'ACTIVE',
                    'cycle'             => $request['cycle'], 'value' => $request['value'], 'nextDueDate' => $request['nextDueDate'],
                    'externalReference' => $request['externalReference'], 'deleted' => false,
                ]),
                default => Http::response(['errors' => [['code' => 'internal_error']]], $asaas->create),
            };
        }

        if ($path === '/v3/subscriptions' && $request->method() === 'GET') {
            $asaas->searched[] = $request['externalReference'];

            return $asaas->searchStatus === 200
                ? Http::response(['object' => 'list', 'hasMore' => false, 'totalCount' => count($asaas->found), 'limit' => 100, 'offset' => 0,
                    'data'                 => array_map(fn (string $id) => ['object' => 'subscription', 'id' => $id, 'status' => 'ACTIVE', 'deleted' => false, 'externalReference' => $request['externalReference']], $asaas->found)])
                : Http::response(['errors' => [['code' => 'unavailable']]], $asaas->searchStatus);
        }

        if (str_starts_with($path, '/v3/subscriptions/') && $request->method() === 'DELETE') {
            return Http::response(['deleted' => true, 'id' => basename($path)]);
        }

        return Http::response(['errors' => [['code' => 'not_faked']]], 404);
    });
}

it('timeout na criação da assinatura do Asaas: acha pela referência a recorrência criada mesmo assim e a cancela', function () {
    $asaas = (object) ['create' => 'timeout', 'found' => ['sub_orfa_timeout'], 'searchStatus' => 200];
    orchFakeAsaasLostResponse($asaas);
    $trial = Subscription::factory()->trial(5)->for($this->clinic)->for($this->plan)->create();

    expect(fn () => orchActivate())->toThrow(GatewayIntegrationException::class);

    $attempt = Subscription::query()->whereKeyNot($trial->id)->sole();

    // A referência enviada na criação e a buscada são o id da tentativa.
    expect($asaas->searched)->toBe([$attempt->id])
        ->and($attempt->isFailedActivation())->toBeTrue()
        ->and(data_get($attempt->gateway_payload, 'orphan_check'))->toBeNull()
        ->and($trial->fresh()->status)->toBe(SubscriptionStatus::Trial);

    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/v3/subscriptions/sub_orfa_timeout'));
});

it('5xx na criação sem como conferir no Asaas: sem fallback, e a nova contratação só sai depois de conferir e cancelar a órfã', function () {
    // Fallback configurado para a falha do Asaas: sem a conferência, ele
    // emitiria uma 2ª cobrança no InfinitePay.
    GatewayFallbackRule::query()->create([
        'primary_gateway_code'  => 'asaas',
        'fallback_gateway_code' => 'infinitepay',
        'trigger_type'          => 'gateway_unavailable',
        'active'                => true,
        'priority'              => 1,
    ]);

    $asaas = (object) ['create' => 503, 'found' => [], 'searchStatus' => 503];
    orchFakeAsaasLostResponse($asaas);

    expect(fn () => orchActivate())->toThrow(
        GatewayIntegrationException::class,
        __('manager_subscriptions.errors.gateway_recurrence_unconfirmed', ['gateway' => 'asaas']),
    );

    $attempt = Subscription::query()->sole();

    expect($attempt->isFailedActivation())->toBeTrue()
        ->and(data_get($attempt->gateway_payload, 'orphan_check.status'))->toBe('pending')
        ->and(data_get($attempt->gateway_payload, 'orphan_check.gateway'))->toBe('asaas');
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'infinitepay'));

    // Nova tentativa com o Asaas ainda sem responder à busca: recusada antes
    // de criar outra assinatura (nem local, nem no gateway).
    $asaas->create = 'ok';

    expect(fn () => orchActivate())->toThrow(ValidationException::class);
    expect(Subscription::query()->count())->toBe(1)
        ->and($asaas->searched)->toBe([$attempt->id, $attempt->id]);
    Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'https://api.asaas.com/v3/subscriptions' && $r['externalReference'] !== $attempt->id);

    // O Asaas voltou: a recorrência da tentativa existia — é cancelada e a
    // contratação segue.
    $asaas->searchStatus = 200;
    $asaas->found        = ['sub_orfa_503'];

    $subscription = orchActivate();

    expect($subscription->gateway_subscription_id)->toBe('sub_nova_ok')
        ->and(data_get($attempt->fresh()->gateway_payload, 'orphan_check.status'))->toBe('cleared');
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/v3/subscriptions/sub_orfa_503'));
});

it('4xx na criação (recusa de validação): o Asaas não criou nada — sem busca pela referência', function () {
    $asaas = (object) ['create' => 400, 'found' => [], 'searchStatus' => 200];
    orchFakeAsaasLostResponse($asaas);

    expect(fn () => orchActivate())->toThrow(GatewayIntegrationException::class);

    expect($asaas->searched)->toBe([])
        ->and(data_get(Subscription::query()->sole()->gateway_payload, 'orphan_check'))->toBeNull();
});
