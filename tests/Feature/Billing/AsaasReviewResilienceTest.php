<?php

declare(strict_types=1);

use App\DTOs\Billing\{CancelSubscriptionDTO, CreateChargeDTO};
use App\Enums\Billing\{DunningStep, InvoiceStatus, PaymentAttemptStatus};
use App\Enums\{BillingCycle, ClientRule, SaasRule, SubscriptionStatus};
use App\Exceptions\Billing\GatewayIntegrationException;
use App\Jobs\Billing\CancelGatewayChargeJob;
use App\Models\Billing\{BillingLog, Invoice, PaymentAttempt, SubscriptionDunningStep};
use App\Models\{Entity, Plan, PlanPrice, Subscription, User};
use App\Notifications\GatewayOperationalAlertNotification;
use App\Services\Billing\DunningService;
use App\Services\Billing\Gateways\AsaasGateway;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\{Carbon, Str};
use Illuminate\Support\Facades\{Cache, Http, Notification, Queue, RateLimiter};

/**
 * Correções da revisão — resiliência da integração Asaas:
 *  - régua (D+7) só encerra com "não pago" conclusivo; consulta sem resposta
 *    conclusiva adia e, depois de N adiamentos, alerta o time;
 *  - 429 congela só a rota (ou a conta, na cota); concorrência = espera
 *    curta; cancelamento de cobrança que deixou de valer vai para job
 *    (https://docs.asaas.com/reference/rate-e-quota-limit);
 *  - webhook com token falso não gasta o limite do gateway;
 *  - conciliação diária roda pelas conferidas há mais tempo;
 *  - timeout na criação nunca reaproveita a cobrança vigente/vencida;
 *  - chave recusada: um registro crítico por janela.
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
        'billing.reconcile.pause_ms'            => 0,
    ]);

    $this->plan = Plan::factory()->create(['name' => 'Pro', 'billing_cycle' => 'monthly', 'price' => 299.90, 'active' => true]);
    PlanPrice::create(['plan_id' => $this->plan->id, 'billing_cycle' => 'monthly', 'price' => 299.90]);

    $this->clinic                = Entity::factory()->make(['is_client' => true, 'active' => true, 'name' => 'Clínica Resiliência', 'national_registration' => '11222333000181']);
    $this->clinic->skipAutoTrial = true;
    $this->clinic->save();
    $this->user   = User::factory()->create(['email_verified_at' => now()]);
    $this->member = createEntityUser($this->clinic, $this->user, ClientRule::Admin->value, isOwner: true);

    $this->saas      = Entity::factory()->create(['is_client' => false, 'active' => true]);
    $this->saasAdmin = User::factory()->create(['email_verified_at' => now()]);
    createEntityUser($this->saas, $this->saasAdmin, SaasRule::Admin->value);
});

afterEach(fn () => Carbon::setTestNow());

function rvsAsaas(): AsaasGateway
{
    return app(AsaasGateway::class);
}

/** Em atraso desde 08/10 (cobrança pay_atraso da recorrência sub_regua). */
function rvsPastDue(array $invoice = []): array
{
    $subscription = Subscription::factory()->gateway('asaas')->for(test()->clinic)->for(test()->plan)->create([
        'gateway_subscription_id' => 'sub_regua', 'gateway_customer_id' => 'cus_000005219613', 'billing_cycle' => BillingCycle::Monthly,
        'status'                  => SubscriptionStatus::PastDue, 'billing_state' => 'past_due', 'amount' => 299.90,
        'last_payment_at'         => '2026-09-08 10:00:00', 'ends_at' => '2026-10-08 23:59:59', 'next_billing_at' => '2026-10-08 23:59:59',
        'past_due_at'             => '2026-10-08 23:59:59',
    ]);

    $inv = Invoice::query()->create([
        'entity_id'    => $subscription->entity_id, 'subscription_id' => $subscription->id, 'plan_id' => $subscription->plan_id,
        'gateway_code' => 'asaas', 'reference' => 'INV-20261008-RVS', 'external_invoice_id' => 'pay_atraso',
        'period_start' => '2026-10-08', 'period_end' => '2026-11-08', 'due_at' => '2026-10-08 23:59:59',
        'amount'       => 299.90, 'currency' => 'BRL', 'status' => InvoiceStatus::Overdue->value, 'billing_reason' => 'subscription_cycle',
        ...$invoice,
    ]);

    return [$subscription, $inv];
}

function rvsPayment(string $id, string $status, array $over = []): array
{
    return [
        'object'      => 'payment', 'id' => $id, 'customer' => 'cus_000005219613', 'subscription' => 'sub_regua', 'value' => 299.9,
        'billingType' => 'PIX', 'status' => $status, 'dueDate' => '2026-10-08', 'deleted' => false, ...$over,
    ];
}

describe('régua só encerra com "não pago" conclusivo (achado 8)', function () {
    it('D+7 com a conferência sem resposta conclusiva: não encerra, adia; no 3º adiamento alerta o time; conclusiva depois → encerra', function (int $status) {
        [$subscription] = rvsPastDue();
        $answer         = Http::response(['errors' => [['code' => 'x', 'description' => 'falha']]], $status, $status === 429 ? ['RateLimit-Reset' => '60'] : []);
        Http::fake([
            'https://api.asaas.com/v3/payments/pay_atraso'               => Http::sequence()->pushResponse($answer)->pushResponse($answer)->pushResponse($answer)->push(rvsPayment('pay_atraso', 'OVERDUE')),
            'https://api.asaas.com/v3/subscriptions/sub_regua/payments*' => Http::response(['hasMore' => false, 'data' => []]),
            'https://api.asaas.com/v3/subscriptions/sub_regua'           => Http::response(['id' => 'sub_regua', 'status' => 'ACTIVE', 'deleted' => true]),
        ]);

        foreach (['2026-10-15', '2026-10-16'] as $day) {
            $this->travelTo(CarbonImmutable::parse("{$day} 09:00:00"));
            expect(app(DunningService::class)->run()[DunningStep::Terminated->value])->toBe(0);
        }

        expect($subscription->fresh()->status)->toBe(SubscriptionStatus::PastDue)
            ->and(SubscriptionDunningStep::query()->count())->toBe(0);
        Notification::assertNotSentTo($this->saasAdmin, GatewayOperationalAlertNotification::class, fn ($n) => $n->kind === 'dunning_check_failed');

        // 3º adiamento: alerta crítico — e continua sem encerrar sozinho.
        $this->travelTo(CarbonImmutable::parse('2026-10-17 09:00:00'));
        expect(app(DunningService::class)->run()[DunningStep::Terminated->value])->toBe(0)
            ->and($subscription->fresh()->status)->toBe(SubscriptionStatus::PastDue);
        Notification::assertSentTo($this->saasAdmin, GatewayOperationalAlertNotification::class, fn ($n) => $n->kind === 'dunning_check_failed' && (int) $n->params['count'] === 3);

        // A conferência volta e diz "vencida": aí sim encerra.
        $this->travelTo(CarbonImmutable::parse('2026-10-18 09:00:00'));
        expect(app(DunningService::class)->run()[DunningStep::Terminated->value])->toBe(1)
            ->and($subscription->fresh()->status)->toBe(SubscriptionStatus::Expired);
    })->with(['5xx' => 503, 'chave recusada' => 401, 'limite (429)' => 429]);

    it('timeout na conferência também adia (nunca vira "não pago")', function () {
        [$subscription] = rvsPastDue();
        Http::fake(['https://api.asaas.com/*' => Http::failedConnection()]);

        $this->travelTo(CarbonImmutable::parse('2026-10-15 09:00:00'));
        expect(app(DunningService::class)->run()[DunningStep::Terminated->value])->toBe(0)
            ->and($subscription->fresh()->status)->toBe(SubscriptionStatus::PastDue)
            ->and(BillingLog::query()->where('message', 'like', 'Régua: não foi possível conferir%')->exists())->toBeTrue();
    });
});

describe('429 por rota, não do gateway inteiro (achado 9)', function () {
    it('limite de frequência de um endpoint (RateLimit-Reset) não congela os outros', function () {
        Http::fake([
            'https://api.asaas.com/v3/payments/pay_1/pixQrCode' => Http::response(['errors' => [['code' => 'too_many_requests']]], 429, ['RateLimit-Remaining' => '0', 'RateLimit-Reset' => '60']),
            'https://api.asaas.com/v3/subscriptions/sub_1'      => Http::response(['deleted' => true, 'id' => 'sub_1']),
        ]);

        expect(fn () => rvsAsaas()->paymentInstructions('pix', 'pay_1'))->toThrow(GatewayIntegrationException::class);

        // Outra rota segue funcionando (cancelar uma recorrência é crítico).
        expect(rvsAsaas()->cancelSubscription(new CancelSubscriptionDTO(entityId: 'e', subscriptionId: 's', externalSubscriptionId: 'sub_1'))->success)->toBeTrue();

        // A mesma rota espera o tempo pedido.
        expect(fn () => rvsAsaas()->paymentInstructions('pix', 'pay_1'))
            ->toThrow(fn (GatewayIntegrationException $e) => expect($e->isRateLimit())->toBeTrue());
        Http::assertSentCount(2);
    });

    it('429 sem tempo pedido (concorrência): espera curta só na rota', function () {
        Http::fake(['https://api.asaas.com/v3/payments/pay_1/pixQrCode' => Http::sequence()
            ->push(['errors' => [['code' => 'too_many_requests', 'description' => 'Limite de requisições concorrentes']]], 429)
            ->push(['encodedImage' => 'iVBOR=', 'payload' => '00020101pix', 'expirationDate' => '2027-10-08 23:59:59'])]);

        expect(fn () => rvsAsaas()->paymentInstructions('pix', 'pay_1'))->toThrow(GatewayIntegrationException::class);

        $this->travel(6)->seconds();
        expect(rvsAsaas()->paymentInstructions('pix', 'pay_1')?->toArray()['pix']['copy_paste'] ?? null)->toBe('00020101pix');
    });

    it('cota da conta esgotada: aí sim todas as rotas esperam', function () {
        Http::fake(['https://api.asaas.com/v3/payments/pay_1/pixQrCode' => Http::response(['errors' => [['code' => 'too_many_requests', 'description' => 'Cota de requisições excedida']]], 429, ['RateLimit-Reset' => '600'])]);

        expect(fn () => rvsAsaas()->paymentInstructions('pix', 'pay_1'))->toThrow(GatewayIntegrationException::class);
        expect(fn () => rvsAsaas()->cancelSubscription(new CancelSubscriptionDTO(entityId: 'e', subscriptionId: 's', externalSubscriptionId: 'sub_1')))
            ->toThrow(fn (GatewayIntegrationException $e) => expect($e->isRateLimit())->toBeTrue());
        Http::assertSentCount(1);
    });

    it('fatura paga por uma cobrança e a outra não cancela agora (429): job com novas tentativas, não só um log', function () {
        Queue::fake([CancelGatewayChargeJob::class]);
        [$subscription, $invoice] = rvsPastDue(['status' => InvoiceStatus::Pending->value, 'external_invoice_id' => 'pay_boleto', 'payment_instructions' => [
            'pix' => ['pix' => ['copy_paste' => '000201'], 'charge_id' => 'pay_pix'],
        ]]);
        Http::fake(['https://api.asaas.com/v3/payments/pay_boleto' => Http::response(['errors' => [['code' => 'too_many_requests']]], 429, ['RateLimit-Reset' => '30'])]);

        test()->postJson('/api/billing/webhooks/asaas', [
            'id'      => 'evt_' . Str::random(20), 'event' => 'PAYMENT_RECEIVED', 'dateCreated' => now()->format('Y-m-d H:i:s'),
            'payment' => rvsPayment('pay_pix', 'RECEIVED', ['subscription' => null, 'externalReference' => (string) $invoice->id]),
        ], ['asaas-access-token' => 'whsec_asaas_teste'])->assertOk();

        expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
        Queue::assertPushed(CancelGatewayChargeJob::class, fn (CancelGatewayChargeJob $job) => $job->externalChargeId === 'pay_boleto' && $job->invoiceId === $invoice->id);
        expect(BillingLog::query()->where('level', 'critical')->where('message', 'like', '%não pôde ser cancelada%')->exists())->toBeFalse();

        // Esgotadas as tentativas: aí o alerta crítico (cancelar à mão).
        (new CancelGatewayChargeJob('asaas', 'pay_boleto', (string) $invoice->id, (string) Str::uuid()))->failed(new RuntimeException('429'));
        expect(BillingLog::query()->where('level', 'critical')->where('message', 'like', '%não pôde ser cancelada%')->exists())->toBeTrue();
    });
});

describe('webhook: token falso não gasta o limite do gateway (achado 10)', function () {
    it('flood com token inválido recebe 429 no balde dele; o Asaas de verdade segue com 200', function () {
        Queue::fake();
        config(['billing.webhooks.rate_limit_per_minute' => 60, 'billing.webhooks.invalid_rate_limit_per_minute' => 30]);
        RateLimiter::clear('billing-webhook:asaas');

        foreach (range(1, 70) as $i) {
            $response = $this->postJson('/api/billing/webhooks/asaas', ['id' => "evt_falso_{$i}", 'event' => 'PAYMENT_RECEIVED'], ['asaas-access-token' => 'token-falso-' . $i]);
        }
        $response->assertStatus(429);

        $this->postJson('/api/billing/webhooks/asaas', [
            'id' => 'evt_legitimo', 'event' => 'PAYMENT_BANK_SLIP_VIEWED', 'payment' => ['id' => 'pay_1', 'value' => 1, 'status' => 'PENDING'],
        ], ['asaas-access-token' => 'whsec_asaas_teste'])->assertOk();
    });
});

describe('conciliação diária chega às faturas recentes (achado 13)', function () {
    it('com mais faturas que o teto, a rodada seguinte começa pelas nunca/há mais tempo conferidas e pula as conferidas hoje', function () {
        $sub = Subscription::factory()->gateway('asaas')->for($this->clinic)->for($this->plan)->create(['gateway_subscription_id' => null]);

        foreach (['pay_a' => '2026-09-01', 'pay_b' => '2026-09-10', 'pay_c' => '2026-10-01'] as $charge => $due) {
            Invoice::query()->create([
                'entity_id' => $this->clinic->id, 'subscription_id' => $sub->id, 'plan_id' => $this->plan->id, 'gateway_code' => 'asaas',
                'reference' => 'INV-' . $charge, 'external_invoice_id' => $charge, 'due_at' => "{$due} 23:59:59", 'amount' => 299.90,
                'currency'  => 'BRL', 'status' => InvoiceStatus::Overdue->value, 'billing_reason' => 'subscription_cycle',
            ]);
        }
        Http::fake(['https://api.asaas.com/v3/payments/*' => fn (Request $r) => Http::response(rvsPayment(basename($r->url()), 'OVERDUE'))]);
        $checked = fn () => collect(Http::recorded())->map(fn ($p) => basename($p[0]->url()))->values()->all();

        $this->travelTo(CarbonImmutable::parse('2026-10-10 08:30:00'));
        $this->artisan('billing:reconcile-overdue', ['--limit' => 2])->assertExitCode(0);
        expect($checked())->toBe(['pay_a', 'pay_b']);

        // Mesmo dia: as já conferidas hoje ficam de fora.
        $this->artisan('billing:reconcile-overdue', ['--limit' => 2])->assertExitCode(0);
        expect($checked())->toBe(['pay_a', 'pay_b', 'pay_c']);

        // Dia seguinte: a fila gira (a mais antiga conferida primeiro).
        $this->travelTo(CarbonImmutable::parse('2026-10-11 08:30:00'));
        $this->artisan('billing:reconcile-overdue', ['--limit' => 1])->assertExitCode(0);
        expect($checked())->toBe(['pay_a', 'pay_b', 'pay_c', 'pay_a']);
    });
});

describe('timeout na criação nunca reaproveita a cobrança vigente (achado 14)', function () {
    it('a consulta pela referência ignora a vencida/cancelada/removida mesmo sem a lista de conhecidas', function () {
        Http::fake([
            'https://api.asaas.com/v3/payments?*' => Http::response(['object' => 'list', 'hasMore' => false, 'data' => [
                ['id' => 'pay_vencida', 'value' => 299.9, 'billingType' => 'PIX', 'status' => 'OVERDUE', 'externalReference' => '9f1c2d3e-0000-4000-8000-0000000000ff', 'deleted' => false, 'dateCreated' => '2026-10-01'],
                ['id' => 'pay_removida', 'value' => 299.9, 'billingType' => 'PIX', 'status' => 'PENDING', 'externalReference' => '9f1c2d3e-0000-4000-8000-0000000000ff', 'deleted' => true, 'dateCreated' => '2026-10-02'],
            ]]),
            'https://api.asaas.com/v3/payments' => Http::failedConnection(),
        ]);

        expect(fn () => rvsAsaas()->createCharge(new CreateChargeDTO(
            entityId: '9f1c2d3e-0000-4000-8000-000000000001',
            invoiceId: '9f1c2d3e-0000-4000-8000-0000000000ff',
            subscriptionId: '9f1c2d3e-0000-4000-8000-0000000000aa',
            customerId: 'cus_000005219613',
            amount: 299.90,
            currency: 'BRL',
            description: 'Fatura',
            dueDate: '2026-10-08',
            paymentMethod: 'pix',
        )))->toThrow(GatewayIntegrationException::class);
    });

    it('reemissão do Pix da fatura vencida com timeout: a cobrança vigente (vencida) não volta como "a nova"', function () {
        $sub = Subscription::factory()->gateway('asaas')->for($this->clinic)->for($this->plan)->create([
            'gateway_subscription_id' => null, 'gateway_customer_id' => 'cus_000005219613', 'billing_cycle' => BillingCycle::Monthly,
            'status'                  => SubscriptionStatus::PastDue, 'billing_state' => 'past_due', 'amount' => 299.90, 'last_payment_at' => '2026-09-01 10:00:00',
            'ends_at'                 => '2026-10-01 23:59:59', 'next_billing_at' => '2026-10-01 23:59:59', 'past_due_at' => '2026-10-01 23:59:59',
        ]);
        $invoice = Invoice::query()->create([
            'entity_id' => $this->clinic->id, 'subscription_id' => $sub->id, 'plan_id' => $this->plan->id, 'gateway_code' => 'asaas',
            'reference' => 'INV-VENCIDA', 'external_invoice_id' => 'pay_vigente', 'period_start' => '2026-10-01', 'period_end' => '2026-11-01',
            'due_at'    => '2026-10-01 23:59:59', 'amount' => 299.90, 'currency' => 'BRL', 'status' => InvoiceStatus::Overdue->value, 'billing_reason' => 'subscription_cycle',
        ]);
        $sub->update(['current_invoice_id' => $invoice->id]);
        Http::fake(function (Request $r) use ($invoice) {
            return match (true) {
                $r->method() === 'GET' && str_starts_with($r->url(), 'https://api.asaas.com/v3/payments?') => Http::response(['object' => 'list', 'hasMore' => false, 'data' => [
                    ['id' => 'pay_vigente', 'value' => 299.9, 'billingType' => 'PIX', 'status' => 'OVERDUE', 'externalReference' => (string) $invoice->id, 'deleted' => false, 'dateCreated' => '2026-09-25'],
                ]]),
                $r->method() === 'POST' && $r->url() === 'https://api.asaas.com/v3/payments' => Http::failedConnection(),
                // Pix da vigente: vencido.
                str_contains($r->url(), '/pay_vigente/pixQrCode') => Http::response(['encodedImage' => 'iVBOR=', 'payload' => '000201velho', 'expirationDate' => '2026-10-01 23:59:59']),
                default                                           => Http::response(['errors' => [['code' => 'not_faked']]], 404),
            };
        });

        $this->actingAs($this->user)->withSession(panelSession($this->member))
            ->postJson(route('panel.my-subscription.charge', ['invoice' => $invoice->id]), ['method' => 'pix'])
            ->assertStatus(502)
            ->assertJsonPath('code', 'gateway_error');

        // A falha fica registrada como inconclusiva (a próxima tentativa
        // procura a cobrança nova) — a vencida nunca foi "reaproveitada".
        $attempt = PaymentAttempt::query()->where('invoice_id', $invoice->id)->latest('attempt_number')->first();
        expect($attempt?->status)->toBe(PaymentAttemptStatus::Failed)
            ->and($attempt?->error_code)->toBe('timeout')
            ->and($invoice->fresh()->external_invoice_id)->toBe('pay_vigente')
            ->and(data_get($invoice->fresh()->payment_instructions, 'pix.pix.copy_paste'))->not->toBe('000201velho');
    });
});

describe('chave recusada: um registro por janela (achado 15)', function () {
    it('várias chamadas com 401 deixam um único log crítico (e um e-mail) na janela', function () {
        Http::fake(['https://api.asaas.com/v3/payments/*' => Http::response(['errors' => [['code' => 'invalid_access_token']]], 401)]);

        foreach (range(1, 4) as $i) {
            try {
                rvsAsaas()->paymentStatusEvent('pay_' . $i);
            } catch (GatewayIntegrationException) {
                // esperado
            }
        }

        expect(BillingLog::query()->where('level', 'critical')->get()->filter(fn ($l) => data_get($l->context, 'alert') === 'credential_rejected')->count())->toBe(1);
        Notification::assertSentToTimes($this->saasAdmin, GatewayOperationalAlertNotification::class, 1);

        // Passada a janela, registra de novo.
        $this->travel(61)->minutes();

        try {
            rvsAsaas()->paymentStatusEvent('pay_9');
        } catch (GatewayIntegrationException) {
        }
        expect(BillingLog::query()->where('level', 'critical')->get()->filter(fn ($l) => data_get($l->context, 'alert') === 'credential_rejected')->count())->toBe(2);
    });
});
