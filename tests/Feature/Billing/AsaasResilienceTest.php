<?php

declare(strict_types=1);

use App\DTOs\Billing\{CancelSubscriptionDTO, CreateChargeDTO, CreateSubscriptionDTO, GatewayHealthDTO};
use App\Enums\Billing\{InvoiceStatus, PaymentAttemptStatus};
use App\Enums\{BillingCycle, ClientRule, SaasRule, SubscriptionStatus};
use App\Enums\FeatureKey;
use App\Exceptions\Billing\GatewayIntegrationException;
use App\Jobs\Billing\{CancelGatewaySubscriptionJob, RenewSubscriptionJob};
use App\Models\Billing\{BillingLog, Gateway, Invoice, PaymentAttempt};
use App\Models\{Entity, Plan, PlanPrice, Subscription, User};
use App\Models\PlanFeature;
use App\Notifications\GatewayOperationalAlertNotification;
use App\Services\Billing\{BillingLogService, CircuitBreakerService, FinancialEventService, GatewayRegistry, SubscriptionCycleService};
use App\Services\Billing\Gateways\AsaasGateway;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\{Carbon, Str};
use Illuminate\Support\Facades\{Cache, Http, Notification, Queue, RateLimiter, Route};

/**
 * Correções de risco da integração com o Asaas:
 *  - idempotência sem chave no gateway: timeout/5xx → consulta pela
 *    referência e reaproveita a cobrança criada (renovação, reemissão do
 *    checkout, pacote de IA) —
 *    https://docs.asaas.com/docs/cobrança-duplicada-após-retry-sem-idempotência;
 *  - instruções Pix/boleto: 401/403/429/5xx são erro do gateway (tente de
 *    novo + alerta), não "forma indisponível";
 *  - 429 respeita RateLimit-Reset (https://docs.asaas.com/reference/rate-e-quota-limit);
 *  - health check real (GET /v3/myAccount/status/) e prefixo da chave × base;
 *  - webhook sem limite por IP (https://docs.asaas.com/docs/penalização-de-filas);
 *  - ciclo desconhecido nunca vira mensal.
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
    ]);

    $this->saas      = Entity::factory()->create(['is_client' => false, 'active' => true]);
    $this->saasAdmin = User::factory()->create(['email_verified_at' => now()]);
    createEntityUser($this->saas, $this->saasAdmin, SaasRule::Admin->value);
});

afterEach(fn () => Carbon::setTestNow());

function resAsaas(): AsaasGateway
{
    return app(AsaasGateway::class);
}

function resChargeDto(?array $known = null, string $method = 'pix'): CreateChargeDTO
{
    return new CreateChargeDTO(
        entityId: '9f1c2d3e-0000-4000-8000-000000000001',
        invoiceId: '9f1c2d3e-0000-4000-8000-0000000000ff',
        subscriptionId: '9f1c2d3e-0000-4000-8000-0000000000aa',
        customerId: 'cus_000005219613',
        amount: 299.90,
        currency: 'BRL',
        description: 'Fatura INV-1',
        dueDate: '2026-10-08',
        paymentMethod: $method,
        knownChargeIds: $known,
    );
}

/** Cobrança no formato da listagem GET /v3/payments. */
function resRow(string $id, array $over = []): array
{
    return [
        'object'            => 'payment',
        'id'                => $id,
        'customer'          => 'cus_000005219613',
        'value'             => 299.9,
        'billingType'       => 'PIX',
        'status'            => 'PENDING',
        'dueDate'           => '2026-10-08',
        'dateCreated'       => '2026-10-05',
        'invoiceUrl'        => "https://www.asaas.com/i/{$id}",
        'externalReference' => '9f1c2d3e-0000-4000-8000-0000000000ff',
        'deleted'           => false,
        ...$over,
    ];
}

describe('idempotência por externalReference', function () {
    it('timeout no POST: consulta pela referência, acha a cobrança criada e não emite outra', function () {
        Http::fake([
            'https://api.asaas.com/v3/payments?*' => Http::response(['object' => 'list', 'hasMore' => false, 'data' => [resRow('pay_criada')]]),
            'https://api.asaas.com/v3/payments'   => Http::failedConnection(),
        ]);

        $result = resAsaas()->createCharge(resChargeDto());

        expect($result->success)->toBeTrue()
            ->and($result->externalPaymentId)->toBe('pay_criada')
            ->and($result->rawResponse['easyeye_reused'])->toBeTrue();

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && $r['externalReference'] === '9f1c2d3e-0000-4000-8000-0000000000ff');
    });

    it('5xx no POST e nada criado: devolve a falha (o chamador registra)', function () {
        Http::fake([
            'https://api.asaas.com/v3/payments?*' => Http::response(['object' => 'list', 'hasMore' => false, 'data' => []]),
            'https://api.asaas.com/v3/payments'   => Http::response(['errors' => [['code' => 'internal', 'description' => 'erro']]], 503),
        ]);

        $result = resAsaas()->createCharge(resChargeDto());

        expect($result->success)->toBeFalse()->and($result->errorCode)->toBe('503');
    });

    it('retentativa: reaproveita a cobrança com a nossa referência que ainda não conhecemos — sem POST', function () {
        Http::fake([
            'https://api.asaas.com/v3/payments?*' => Http::response(['object' => 'list', 'hasMore' => false, 'data' => [
                resRow('pay_conhecida'),
                resRow('pay_boleto', ['billingType' => 'BOLETO']),
                resRow('pay_removida', ['deleted' => true]),
                resRow('pay_outro_valor', ['value' => 199.9]),
                resRow('pay_perdida'),
            ]]),
        ]);

        $result = resAsaas()->createCharge(resChargeDto(['pay_conhecida']));

        expect($result->externalPaymentId)->toBe('pay_perdida');
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    });

    it('retentativa sem cobrança desconhecida: cria normalmente', function () {
        Http::fake([
            'https://api.asaas.com/v3/payments?*' => Http::response(['object' => 'list', 'hasMore' => false, 'data' => [resRow('pay_conhecida')]]),
            'https://api.asaas.com/v3/payments'   => Http::response(resRow('pay_nova')),
        ]);

        expect(resAsaas()->createCharge(resChargeDto(['pay_conhecida']))->externalPaymentId)->toBe('pay_nova');
        Http::assertSent(fn (Request $r) => $r->method() === 'POST');
    });

    it('renovação: a tentativa que deu timeout não vira cobrança dupla na seguinte', function () {
        $clinic                = Entity::factory()->make(['is_client' => true, 'active' => true, 'national_registration' => '11222333000181']);
        $clinic->skipAutoTrial = true;
        $clinic->save();
        $plan = Plan::factory()->create(['name' => 'Pro', 'billing_cycle' => 'monthly', 'price' => 299.90, 'active' => true]);
        PlanPrice::create(['plan_id' => $plan->id, 'billing_cycle' => 'monthly', 'price' => 299.90]);

        // Asaas sem recorrência (renovação local, ex.: a recorrência foi perdida).
        $subscription = Subscription::factory()->gateway('asaas')->for($clinic)->for($plan)->create([
            'gateway_subscription_id' => null,
            'gateway_customer_id'     => 'cus_000005219613',
            'billing_cycle'           => BillingCycle::Monthly,
            'status'                  => SubscriptionStatus::Active,
            'amount'                  => 299.90,
            'last_payment_at'         => '2026-09-08 10:00:00',
            'ends_at'                 => '2026-10-08 23:59:59',
            'next_billing_at'         => '2026-10-08 23:59:59',
        ]);

        // 1ª tentativa: o POST se perde (o Asaas criou, mas a resposta não
        // chegou) e a consulta logo depois ainda não lista a cobrança.
        $visible = false;
        Http::fake(function (Request $r) use (&$visible) {
            if ($r->method() === 'GET' && str_starts_with($r->url(), 'https://api.asaas.com/v3/payments?')) {
                $invoiceId = $r['externalReference'];

                return Http::response(['object' => 'list', 'hasMore' => false, 'data' => $visible ? [resRow('pay_perdida', ['externalReference' => $invoiceId, 'billingType' => 'UNDEFINED'])] : []]);
            }

            if ($r->method() === 'POST' && $r->url() === 'https://api.asaas.com/v3/payments') {
                return Http::failedConnection();
            }

            return Http::response(['errors' => [['code' => 'not_faked']]], 404);
        });

        RenewSubscriptionJob::dispatchSync((string) $subscription->id);

        $invoice = Invoice::query()->where('subscription_id', $subscription->id)->sole();
        expect($invoice->external_invoice_id)->toBeNull()
            ->and(PaymentAttempt::query()->where('invoice_id', $invoice->id)->sole()->status)->toBe(PaymentAttemptStatus::Failed);

        // Dia seguinte: a consulta acha a cobrança criada — nenhum POST novo.
        $visible = true;
        $this->travelTo(CarbonImmutable::parse('2026-10-06 01:00:00'));
        $posts = Http::recorded(fn (Request $r) => $r->method() === 'POST')->count();

        RenewSubscriptionJob::dispatchSync((string) $subscription->id);

        expect(Http::recorded(fn (Request $r) => $r->method() === 'POST')->count())->toBe($posts)
            ->and($invoice->fresh()->external_invoice_id)->toBe('pay_perdida')
            ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Pending);
    });
});

describe('idempotência nas emissões do checkout', function () {
    beforeEach(function () {
        $this->clinic                = Entity::factory()->make(['is_client' => true, 'active' => true, 'national_registration' => '11222333000181']);
        $this->clinic->skipAutoTrial = true;
        $this->clinic->save();
        $this->user   = User::factory()->create();
        $this->member = createEntityUser($this->clinic, $this->user, ClientRule::Admin->value, isOwner: true);
        $this->plan   = Plan::factory()->create(['name' => 'Pro', 'billing_cycle' => 'monthly', 'price' => 299.90, 'active' => true]);
        PlanPrice::create(['plan_id' => $this->plan->id, 'billing_cycle' => 'monthly', 'price' => 299.90]);
        PlanFeature::factory()->enabled(FeatureKey::HasAiChatAssistant)->for($this->plan)->create();
        $this->subscription = Subscription::factory()->gateway('asaas')->for($this->clinic)->for($this->plan)->create([
            'gateway_subscription_id' => null, 'gateway_customer_id' => 'cus_000005219613', 'billing_cycle' => BillingCycle::Monthly,
            'status'                  => SubscriptionStatus::Active, 'billing_state' => 'paid', 'amount' => 299.90,
            'last_payment_at'         => '2026-10-01 10:00:00', 'ends_at' => '2026-11-01 23:59:59', 'next_billing_at' => '2026-11-01 23:59:59',
        ]);
    });

    /** Tentativa anterior que terminou sem resposta (timeout). */
    function resFailedAttempt(Invoice $invoice, ?string $subscriptionId): void
    {
        PaymentAttempt::query()->create([
            'entity_id'       => $invoice->entity_id,
            'subscription_id' => $subscriptionId,
            'invoice_id'      => $invoice->id,
            'gateway_code'    => 'asaas',
            'attempt_number'  => 1,
            'status'          => PaymentAttemptStatus::Failed->value,
            'trigger'         => 'checkout',
            'error_code'      => 'timeout',
            'error_message'   => 'Timeout na chamada ao gateway.',
            'idempotency_key' => 'test:' . Str::uuid(),
            'started_at'      => now(),
            'finished_at'     => now(),
        ]);
    }

    it('reemissão do Pix depois de timeout: reaproveita a cobrança criada (sem POST)', function () {
        $invoice = Invoice::query()->create([
            'entity_id' => $this->clinic->id, 'subscription_id' => $this->subscription->id, 'plan_id' => $this->plan->id, 'gateway_code' => 'asaas',
            'reference' => 'INV-REEMITE', 'period_start' => '2026-11-01', 'period_end' => '2026-12-01', 'due_at' => '2026-11-01 23:59:59',
            'amount'    => 299.90, 'currency' => 'BRL', 'status' => InvoiceStatus::Pending->value, 'billing_reason' => 'subscription_cycle',
        ]);
        resFailedAttempt($invoice, (string) $this->subscription->id);
        Http::fake([
            'https://api.asaas.com/v3/payments?*'                     => Http::response(['object' => 'list', 'hasMore' => false, 'data' => [resRow('pay_perdida', ['externalReference' => (string) $invoice->id])]]),
            'https://api.asaas.com/v3/payments/pay_perdida/pixQrCode' => Http::response(['encodedImage' => 'iVBOR=', 'payload' => '00020101pix-perdido', 'expirationDate' => '2027-10-08 23:59:59']),
        ]);

        $this->actingAs($this->user)->withSession(panelSession($this->member))
            ->postJson(route('panel.my-subscription.charge', ['invoice' => $invoice->id]), ['method' => 'pix'])
            ->assertOk()
            ->assertJsonPath('data.instructions.pix.copy_paste', '00020101pix-perdido');

        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
        expect($invoice->fresh()->external_invoice_id)->toBe('pay_perdida');
    });

    it('pacote de IA depois de timeout: reaproveita a cobrança criada (sem POST)', function () {
        // O Asaas criou a cobrança, mas a resposta se perdeu; ela só aparece
        // na consulta pela referência depois.
        $visible = false;
        Http::fake(function (Request $r) use (&$visible) {
            if ($r->method() === 'GET' && str_starts_with($r->url(), 'https://api.asaas.com/v3/payments?')) {
                return Http::response(['object' => 'list', 'hasMore' => false, 'data' => $visible
                    ? [resRow('pay_pack', ['externalReference' => $r['externalReference'], 'value' => 249.9])]
                    : []]);
            }

            if ($r->method() === 'POST' && $r->url() === 'https://api.asaas.com/v3/payments') {
                return Http::failedConnection();
            }

            if ($r->url() === 'https://api.asaas.com/v3/payments/pay_pack/pixQrCode') {
                return Http::response(['encodedImage' => 'iVBOR=', 'payload' => '00020101pix-pack', 'expirationDate' => '2027-10-08 23:59:59']);
            }

            return Http::response(['errors' => [['code' => 'not_faked']]], 404);
        });

        $this->actingAs($this->user)->withSession(panelSession($this->member))
            ->postJson(route('panel.my-subscription.ai-credits.purchase'), ['package_code' => 'operational', 'method' => 'pix'])
            ->assertStatus(502);

        $invoice = Invoice::query()->where('billing_reason', Invoice::BILLING_REASON_AI_CREDIT_PACK)->sole();
        expect(PaymentAttempt::query()->where('invoice_id', $invoice->id)->sole()->error_code)->toBe('timeout');

        // Nova tentativa: a cobrança apareceu na consulta pela referência.
        $visible = true;
        $posts   = Http::recorded(fn (Request $r) => $r->method() === 'POST')->count();

        $this->actingAs($this->user)->withSession(panelSession($this->member))
            ->postJson(route('panel.my-subscription.charge', ['invoice' => $invoice->id]), ['method' => 'pix'])
            ->assertOk()
            ->assertJsonPath('data.instructions.pix.copy_paste', '00020101pix-pack');

        expect(Http::recorded(fn (Request $r) => $r->method() === 'POST')->count())->toBe($posts)
            ->and($invoice->fresh()->external_invoice_id)->toBe('pay_pack');
    });
});

describe('instruções Pix/boleto: erro do gateway ≠ forma indisponível', function () {
    it('400/404: a cobrança não tem essa forma (null)', function (int $status) {
        Http::fake(['https://api.asaas.com/v3/payments/pay_1/pixQrCode' => Http::response(['errors' => [['code' => 'invalid_action']]], $status)]);

        expect(resAsaas()->paymentInstructions('pix', 'pay_1'))->toBeNull();
    })->with([400, 404]);

    it('401/403: lança erro de credencial e avisa o time (uma vez por janela)', function (int $status) {
        Http::fake(['https://api.asaas.com/v3/payments/pay_1/pixQrCode' => Http::response(['errors' => [['code' => 'invalid_access_token', 'description' => 'A chave de API fornecida é inválida']]], $status)]);

        expect(fn () => resAsaas()->paymentInstructions('pix', 'pay_1'))
            ->toThrow(fn (GatewayIntegrationException $e) => expect($e->isAuthError())->toBeTrue());
        expect(fn () => resAsaas()->paymentInstructions('pix', 'pay_1'))->toThrow(GatewayIntegrationException::class);

        Notification::assertSentToTimes($this->saasAdmin, GatewayOperationalAlertNotification::class, 1);
        expect(BillingLog::query()->where('level', 'critical')->get()->contains(fn ($l) => data_get($l->context, 'alert') === 'credential_rejected'))->toBeTrue();
    })->with([401, 403]);

    it('429 e 5xx: lança (tente de novo), nunca null', function (int $status) {
        Http::fake(['https://api.asaas.com/v3/payments/pay_1/identificationField' => Http::response(['errors' => [['code' => 'x']]], $status, ['RateLimit-Reset' => '30'])]);

        expect(fn () => resAsaas()->paymentInstructions('boleto', 'pay_1'))->toThrow(GatewayIntegrationException::class);
    })->with([429, 500, 503]);

    it('no checkout: erro do gateway vira "não foi possível gerar agora, tente de novo" (503)', function () {
        $clinic                = Entity::factory()->make(['is_client' => true, 'active' => true]);
        $clinic->skipAutoTrial = true;
        $clinic->save();
        $user   = User::factory()->create();
        $member = createEntityUser($clinic, $user, ClientRule::Admin->value, isOwner: true);
        $plan   = Plan::factory()->create(['name' => 'Pro', 'billing_cycle' => 'monthly', 'price' => 299.90, 'active' => true]);
        PlanPrice::create(['plan_id' => $plan->id, 'billing_cycle' => 'monthly', 'price' => 299.90]);
        $subscription = Subscription::factory()->gateway('asaas')->for($clinic)->for($plan)->create([
            'gateway_subscription_id' => 'sub_x', 'billing_cycle' => BillingCycle::Monthly, 'status' => SubscriptionStatus::Active, 'amount' => 299.90,
            'last_payment_at'         => '2026-09-08 10:00:00', 'ends_at' => '2026-10-08 23:59:59', 'next_billing_at' => '2026-10-08 23:59:59',
        ]);
        $invoice = Invoice::query()->create([
            'entity_id' => $clinic->id, 'subscription_id' => $subscription->id, 'plan_id' => $plan->id, 'gateway_code' => 'asaas',
            'reference' => 'INV-X', 'external_invoice_id' => 'pay_1', 'period_start' => '2026-10-08', 'period_end' => '2026-11-08',
            'due_at'    => '2026-10-08 23:59:59', 'amount' => 299.90, 'currency' => 'BRL', 'status' => InvoiceStatus::Pending->value,
        ]);
        Http::fake(['https://api.asaas.com/v3/payments/pay_1/pixQrCode' => Http::response(['errors' => [['code' => 'invalid_access_token']]], 401)]);

        $this->actingAs($user)->withSession(panelSession($member))
            ->getJson(route('panel.my-subscription.instructions', ['invoice' => $invoice->id, 'method' => 'pix']))
            ->assertStatus(503)
            ->assertJsonPath('code', 'instructions_failed')
            ->assertJsonPath('message', __('checkout.errors.instructions_failed'));
    });
});

describe('429: respeita o tempo pedido pela API', function () {
    it('depois de um 429 nenhuma chamada sai até o RateLimit-Reset; depois libera', function () {
        Http::fake([
            'https://api.asaas.com/v3/subscriptions/sub_1' => Http::sequence()
                ->push(['errors' => [['code' => 'too_many_requests']]], 429, ['RateLimit-Remaining' => '0', 'RateLimit-Reset' => '30'])
                ->push(['deleted' => true, 'id' => 'sub_1'], 200),
        ]);
        $dto = new CancelSubscriptionDTO(entityId: 'e', subscriptionId: 's', externalSubscriptionId: 'sub_1');

        expect(resAsaas()->cancelSubscription($dto)->success)->toBeFalse();

        expect(fn () => resAsaas()->cancelSubscription($dto))
            ->toThrow(fn (GatewayIntegrationException $e) => expect($e->isRateLimit())->toBeTrue()->and($e->retryAfter())->toBeLessThanOrEqual(30)->toBeGreaterThan(0));
        Http::assertSentCount(1);

        $this->travel(31)->seconds();
        expect(resAsaas()->cancelSubscription($dto)->success)->toBeTrue();
        Http::assertSentCount(2);
    });

    it('job de cancelamento no gateway é reagendado para depois do limite (sem repetir na hora)', function () {
        $clinic                = Entity::factory()->make(['is_client' => true, 'active' => true]);
        $clinic->skipAutoTrial = true;
        $clinic->save();
        $subscription = Subscription::factory()->gateway('asaas')->for($clinic)->for(Plan::factory()->create())->create(['gateway_subscription_id' => 'sub_1']);
        Http::fake(['https://api.asaas.com/v3/subscriptions/sub_1' => Http::response(['errors' => [['code' => 'too_many_requests']]], 429, ['Retry-After' => '45'])]);

        // 1ª chamada recebe o 429 (resposta normal); a 2ª nem sai — o job é reagendado.
        resAsaas()->cancelSubscription(new CancelSubscriptionDTO(entityId: 'e', subscriptionId: 's', externalSubscriptionId: 'sub_1'));

        $job = (new CancelGatewaySubscriptionJob((string) $subscription->id, 'asaas', (string) Str::uuid()))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        $job->assertReleased();
        Http::assertSentCount(1);
    });

    it('renovação: 429 reagenda o job depois do tempo pedido (no máximo 3 vezes), sem registrar falha', function () {
        Queue::fake();
        $clinic                = Entity::factory()->make(['is_client' => true, 'active' => true]);
        $clinic->skipAutoTrial = true;
        $clinic->save();
        $plan         = Plan::factory()->create(['price' => 299.90, 'active' => true]);
        $subscription = Subscription::factory()->gateway('asaas')->for($clinic)->for($plan)->create([
            'gateway_subscription_id' => null, 'gateway_customer_id' => 'cus_1', 'billing_cycle' => BillingCycle::Monthly,
            'status'                  => SubscriptionStatus::Active, 'amount' => 299.90, 'last_payment_at' => '2026-09-08 10:00:00',
            'ends_at'                 => '2026-10-08 23:59:59', 'next_billing_at' => '2026-10-08 23:59:59',
        ]);
        Cache::put('billing:gateway:asaas:rate_limited_until', now()->addSeconds(120)->getTimestamp(), 120);
        Http::fake();

        (new RenewSubscriptionJob((string) $subscription->id))->handle(...array_map(fn ($c) => app($c), [
            GatewayRegistry::class,
            CircuitBreakerService::class,
            FinancialEventService::class,
            BillingLogService::class,
            SubscriptionCycleService::class,
        ]));

        Http::assertNothingSent();
        Queue::assertPushed(RenewSubscriptionJob::class, fn (RenewSubscriptionJob $job) => $job->rateLimitRetries === 1);
        expect(PaymentAttempt::query()->count())->toBe(0);
    });
});

describe('health check real', function () {
    it('ok: GET /v3/myAccount/status/ com a chave, resultado gravado no gateway', function () {
        Gateway::query()->updateOrCreate(['code' => 'asaas'], ['name' => 'Asaas', 'active' => true, 'priority' => 1]);
        Http::fake(['https://api.asaas.com/v3/myAccount/status/' => Http::response(['id' => 'acc', 'general' => 'APPROVED', 'commercialInfo' => 'APPROVED'])]);

        $this->artisan('billing:gateway-health', ['--gateway' => 'asaas'])->assertExitCode(0);

        $gateway = Gateway::query()->where('code', 'asaas')->sole();
        expect($gateway->health['status'])->toBe(GatewayHealthDTO::STATUS_OK)
            ->and($gateway->health['details']['account_status'])->toBe('APPROVED')
            ->and($gateway->health_checked_at)->not->toBeNull();
        Http::assertSent(fn (Request $r) => $r->hasHeader('access_token', '$aact_prod_chave_de_teste'));
        Notification::assertNothingSent();
    });

    it('401: chave recusada — status auth_error e alerta ao time', function () {
        Gateway::query()->updateOrCreate(['code' => 'asaas'], ['name' => 'Asaas', 'active' => true, 'priority' => 1]);
        Http::fake(['https://api.asaas.com/v3/myAccount/status/' => Http::response(['errors' => [['code' => 'invalid_access_token', 'description' => 'A chave de API fornecida é inválida']]], 401)]);

        $this->artisan('billing:gateway-health', ['--gateway' => 'asaas'])->assertExitCode(1);

        expect(Gateway::query()->where('code', 'asaas')->value('health')['status'])->toBe(GatewayHealthDTO::STATUS_AUTH_ERROR);
        Notification::assertSentTo($this->saasAdmin, GatewayOperationalAlertNotification::class, fn ($n) => $n->kind === 'credential_rejected');
    });

    it('chave de sandbox ($aact_hmlg_) com a base de produção: environment_mismatch sem chamar a API, com alerta', function () {
        config(['billing.gateways.asaas.secret' => '$aact_hmlg_chave_de_sandbox']);
        Gateway::query()->updateOrCreate(['code' => 'asaas'], ['name' => 'Asaas', 'active' => true, 'priority' => 1]);
        Http::fake();

        $health = resAsaas()->healthCheck();

        expect($health->healthy)->toBeFalse()
            ->and($health->status)->toBe(GatewayHealthDTO::STATUS_ENVIRONMENT_MISMATCH)
            ->and($health->details)->toMatchArray(['environment' => 'production', 'key_environment' => 'sandbox']);
        Http::assertNothingSent();

        $this->artisan('billing:gateway-health', ['--gateway' => 'asaas'])->assertExitCode(1);
        Notification::assertSentTo($this->saasAdmin, GatewayOperationalAlertNotification::class, fn ($n) => $n->kind === 'environment_mismatch');
    });

    it('401 invalid_environment vira environment_mismatch', function () {
        Http::fake(['https://api.asaas.com/v3/myAccount/status/' => Http::response(['errors' => [['code' => 'invalid_environment', 'description' => 'A chave de API informada não pertence a este ambiente']]], 401)]);

        expect(resAsaas()->healthCheck()->status)->toBe(GatewayHealthDTO::STATUS_ENVIRONMENT_MISMATCH);
    });

    it('agendado todo dia (mantém a chave em uso)', function () {
        $events = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command ?? '')->implode(' ');

        expect($events)->toContain('billing:gateway-health')->toContain('billing:reconcile-overdue');
    });
});

describe('webhook sem limite por IP', function () {
    it('a rota usa o limite por gateway (alto), não por IP', function () {
        $route = Route::getRoutes()->getByName('billing.webhooks');

        expect($route->middleware())->toContain('throttle:billing-webhook')
            ->not->toContain('throttle:240,1');

        $limiter = RateLimiter::limiter('billing-webhook');
        // Com o token do webhook (o do Asaas de verdade): balde do gateway.
        $request = Illuminate\Http\Request::create('/api/billing/webhooks/asaas', 'POST', server: ['HTTP_ASAAS_ACCESS_TOKEN' => 'whsec_asaas_teste'], content: '{}');
        $request->setRouteResolver(fn () => $route->bind($request));
        $limit = $limiter($request);

        expect($limit->maxAttempts)->toBeGreaterThanOrEqual(3000)
            ->and($limit->key)->toBe('billing-webhook:asaas');
    });

    it('rajada acima do antigo limite por IP (240/min) segue respondendo 200', function () {
        Queue::fake();

        foreach (range(1, 250) as $i) {
            $this->postJson('/api/billing/webhooks/asaas', [
                'id'      => "evt_rajada_{$i}",
                'event'   => 'PAYMENT_BANK_SLIP_VIEWED',
                'payment' => ['id' => "pay_{$i}", 'value' => 1, 'status' => 'PENDING'],
            ], ['asaas-access-token' => 'whsec_asaas_teste'])->assertOk();
        }
    });
});

describe('ciclo', function () {
    it('bimestral vira BIMONTHLY; ciclo sem equivalente no Asaas é erro (nunca mensal)', function () {
        Http::fake(['https://api.asaas.com/v3/subscriptions' => fn (Request $r) => Http::response(['id' => 'sub_x', 'customer' => 'cus_1', 'status' => 'ACTIVE', 'cycle' => $r['cycle']])]);
        $dto = fn (string $interval, int $count) => new CreateSubscriptionDTO(
            entityId: 'e',
            subscriptionId: 's',
            planId: 'p',
            customerId: 'cus_1',
            amount: 100,
            currency: 'BRL',
            interval: $interval,
            intervalCount: $count,
            firstDueDate: '2026-10-08',
        );

        resAsaas()->createSubscription($dto('month', 2));
        Http::assertSent(fn (Request $r) => $r['cycle'] === 'BIMONTHLY');

        expect(fn () => resAsaas()->createSubscription($dto('month', 5)))->toThrow(GatewayIntegrationException::class, 'Ciclo sem equivalente');
        expect(fn () => resAsaas()->createSubscription($dto('week', 1)))->toThrow(GatewayIntegrationException::class);
        Http::assertSentCount(1);
    });
});
