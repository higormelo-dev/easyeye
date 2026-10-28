<?php

declare(strict_types=1);

use App\DTOs\Billing\{CustomerDTO, GatewayWebhookInputDTO};
use App\Enums\Billing\{DunningStep, InvoiceStatus};
use App\Enums\{BillingCycle, ClientRule, SaasRule, SubscriptionStatus};
use App\Models\Billing\{GatewayCustomer, Invoice, SubscriptionDunningStep, WebhookEvent};
use App\Models\{Entity, Plan, PlanPrice, Subscription, User};
use App\Notifications\GatewayOperationalAlertNotification;
use App\Services\Billing\DunningService;
use App\Services\Billing\Gateways\AsaasGateway;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\{Carbon, Str};
use Illuminate\Support\Facades\{Cache, Http, Notification};

/**
 * Pagamento perdido e o que o Asaas muda por fora:
 *  - antes de limitar (D+3) e de encerrar (D+7) a régua confere a cobrança no
 *    Asaas (GET /v3/payments/{id}); paga lá → aplicada pelo caminho do
 *    webhook e a etapa não acontece;
 *  - billing:reconcile-overdue confere as faturas vencidas (teto, parada no 429);
 *  - PAYMENT_UPDATED / PAYMENT_BANK_SLIP_CANCELLED apagam o Pix/boleto guardados;
 *  - SUBSCRIPTION_UPDATED com valor/ciclo/vencimento diferentes → alerta;
 *  - ACCESS_TOKEN_* → alerta;
 *  - notificações do Asaas desligadas no cliente (notificationDisabled —
 *    https://docs.asaas.com/docs/notificacoes) e comando one-off.
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

    $this->clinic                = Entity::factory()->make(['is_client' => true, 'active' => true, 'name' => 'Clínica Régua']);
    $this->clinic->skipAutoTrial = true;
    $this->clinic->save();
    $this->admin = User::factory()->create(['email_verified_at' => now()]);
    createEntityUser($this->clinic, $this->admin, ClientRule::Admin->value, isOwner: true);

    $this->saas      = Entity::factory()->create(['is_client' => false, 'active' => true]);
    $this->saasAdmin = User::factory()->create(['email_verified_at' => now()]);
    createEntityUser($this->saas, $this->saasAdmin, SaasRule::Admin->value);
});

afterEach(fn () => Carbon::setTestNow());

/** Cliente que pagou e está em atraso desde 08/10 (cobrança pay_atraso da recorrência sub_regua). */
function recPastDue(): array
{
    $subscription = Subscription::factory()->gateway('asaas')->for(test()->clinic)->for(test()->plan)->create([
        'gateway_subscription_id' => 'sub_regua',
        'gateway_customer_id'     => 'cus_000005219613',
        'billing_cycle'           => BillingCycle::Monthly,
        'status'                  => SubscriptionStatus::PastDue,
        'billing_state'           => 'past_due',
        'amount'                  => 299.90,
        'last_payment_at'         => '2026-09-08 10:00:00',
        'ends_at'                 => '2026-10-08 23:59:59',
        'next_billing_at'         => '2026-10-08 23:59:59',
        'past_due_at'             => '2026-10-08 23:59:59',
    ]);

    $invoice = Invoice::query()->create([
        'entity_id'           => $subscription->entity_id,
        'subscription_id'     => $subscription->id,
        'plan_id'             => $subscription->plan_id,
        'gateway_code'        => 'asaas',
        'reference'           => 'INV-20261008-REGUA',
        'external_invoice_id' => 'pay_atraso',
        'period_start'        => '2026-10-08',
        'period_end'          => '2026-11-08',
        'due_at'              => '2026-10-08 23:59:59',
        'amount'              => 299.90,
        'currency'            => 'BRL',
        'status'              => InvoiceStatus::Overdue->value,
        'billing_reason'      => 'subscription_cycle',
    ]);

    return [$subscription, $invoice];
}

/** GET /v3/payments/{id} no formato da doc. */
function recPayment(string $id, string $status, array $over = []): array
{
    return [
        'object'            => 'payment',
        'id'                => $id,
        'customer'          => 'cus_000005219613',
        'subscription'      => 'sub_regua',
        'value'             => 299.9,
        'billingType'       => 'PIX',
        'status'            => $status,
        'dueDate'           => '2026-10-08',
        'paymentDate'       => $status === 'RECEIVED' ? '2026-10-10' : null,
        'externalReference' => (string) Subscription::query()->value('id'),
        'deleted'           => false,
        ...$over,
    ];
}

/** Posta o webhook e devolve o resultado do processamento (outcome). */
function recWebhook(array $payload): ?string
{
    $id = 'evt_' . Str::random(24);
    test()->postJson('/api/billing/webhooks/asaas', ['id' => $id, 'dateCreated' => now()->format('Y-m-d H:i:s'), ...$payload], ['asaas-access-token' => 'whsec_asaas_teste'])->assertOk();

    return WebhookEvent::query()->where('external_event_id', $id)->value('normalized_payload')['outcome'] ?? null;
}

describe('régua confere no gateway antes de limitar ou encerrar', function () {
    it('D+3: pago no Asaas sem webhook → aplica o pagamento e não limita o acesso', function () {
        [$subscription, $invoice] = recPastDue();
        Http::fake(['https://api.asaas.com/v3/payments/pay_atraso' => Http::response(recPayment('pay_atraso', 'RECEIVED'))]);

        $this->travelTo(CarbonImmutable::parse('2026-10-11 09:00:00'));
        $stats = app(DunningService::class)->run();

        $subscription->refresh();
        expect($stats[DunningStep::Limited->value])->toBe(0)
            ->and($subscription->status)->toBe(SubscriptionStatus::Active)
            ->and($subscription->ends_at->toDateTimeString())->toBe('2026-11-08 23:59:59')
            ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
            ->and(SubscriptionDunningStep::query()->where('subscription_id', $subscription->id)->count())->toBe(0);
        Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET');
    });

    it('D+7: ainda vencida no Asaas → encerra normalmente (e cancela a recorrência)', function () {
        [$subscription] = recPastDue();
        Http::fake([
            'https://api.asaas.com/v3/payments/pay_atraso'               => Http::response(recPayment('pay_atraso', 'OVERDUE')),
            'https://api.asaas.com/v3/subscriptions/sub_regua/payments*' => Http::response(['hasMore' => false, 'data' => []]),
            'https://api.asaas.com/v3/subscriptions/sub_regua'           => Http::response(['id' => 'sub_regua', 'status' => 'ACTIVE', 'deleted' => true]),
        ]);

        $this->travelTo(CarbonImmutable::parse('2026-10-15 09:00:00'));
        $stats = app(DunningService::class)->run();

        expect($stats[DunningStep::Terminated->value])->toBe(1)
            ->and($subscription->fresh()->status)->toBe(SubscriptionStatus::Expired);
        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && $r->url() === 'https://api.asaas.com/v3/payments/pay_atraso');
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'https://api.asaas.com/v3/subscriptions/sub_regua');
    });

    it('D+7 com o webhook da 1ª parcela perdido: acha a cobrança paga na recorrência e não encerra', function () {
        [$subscription, $invoice] = recPastDue();
        $invoice->update(['external_invoice_id' => null]);
        Http::fake([
            'https://api.asaas.com/v3/subscriptions/sub_regua/payments*' => Http::response(['hasMore' => false, 'data' => [recPayment('pay_perdido', 'RECEIVED')]]),
            'https://api.asaas.com/v3/subscriptions/sub_regua'           => Http::response(['id' => 'sub_regua', 'status' => 'ACTIVE', 'cycle' => 'MONTHLY', 'value' => 299.9]),
            'https://api.asaas.com/v3/payments/pay_perdido'              => Http::response(recPayment('pay_perdido', 'RECEIVED')),
        ]);

        $this->travelTo(CarbonImmutable::parse('2026-10-15 09:00:00'));
        $stats = app(DunningService::class)->run();

        expect($stats[DunningStep::Terminated->value])->toBe(0)
            ->and($subscription->fresh()->status)->toBe(SubscriptionStatus::Active)
            ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
    });

    it('o gateway pediu para esperar (429): a etapa fica para a próxima rodada', function () {
        [$subscription] = recPastDue();
        Http::fake(['https://api.asaas.com/v3/payments/pay_atraso' => Http::response(['errors' => [['code' => 'too_many_requests']]], 429, ['RateLimit-Reset' => '60'])]);

        $this->travelTo(CarbonImmutable::parse('2026-10-15 09:00:00'));
        $stats = app(DunningService::class)->run();

        expect($stats[DunningStep::Terminated->value])->toBe(0)
            ->and($subscription->fresh()->status)->toBe(SubscriptionStatus::PastDue);
    });
});

describe('billing:reconcile-overdue', function () {
    it('aplica o pagamento perdido, deixa a pendente como está e respeita o --dry-run', function () {
        [$subscription, $invoice] = recPastDue();
        Http::fake(['https://api.asaas.com/v3/payments/pay_atraso' => Http::response(recPayment('pay_atraso', 'RECEIVED'))]);
        $this->travelTo(CarbonImmutable::parse('2026-10-10 08:30:00'));

        $this->artisan('billing:reconcile-overdue', ['--dry-run' => true])->assertExitCode(0);
        Http::assertNothingSent();

        $this->artisan('billing:reconcile-overdue')
            ->expectsOutputToContain(__('billing_console.reconcile_overdue.done', ['checked' => 1, 'applied' => 1]))
            ->assertExitCode(0);

        expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
            ->and($subscription->fresh()->status)->toBe(SubscriptionStatus::Active);

        // Já paga: a próxima rodada não confere de novo.
        $this->artisan('billing:reconcile-overdue')->assertExitCode(0);
        Http::assertSentCount(1);
    });

    it('para no primeiro 429 (limite da API)', function () {
        recPastDue();
        Http::fake(['https://api.asaas.com/v3/payments/*' => Http::response(['errors' => [['code' => 'too_many_requests']]], 429, ['RateLimit-Reset' => '120'])]);
        $this->travelTo(CarbonImmutable::parse('2026-10-10 08:30:00'));

        $this->artisan('billing:reconcile-overdue')
            ->expectsOutputToContain(__('billing_console.reconcile_overdue.rate_limited'))
            ->assertExitCode(0);
    });
});

describe('webhooks que mudam o que está guardado', function () {
    function recInvoiceWithInstructions(): array
    {
        [$subscription, $invoice] = recPastDue();
        $invoice->update([
            'external_invoice_id'  => 'pay_1',
            'status'               => InvoiceStatus::Pending->value,
            'payment_instructions' => [
                'pix'    => ['pix' => ['copy_paste' => '000201velho'], 'charge_id' => 'pay_1'],
                'boleto' => ['boleto' => ['digitable_line' => '23790.0000'], 'charge_id' => 'pay_1'],
            ],
        ]);

        return [$subscription, $invoice];
    }

    it('PAYMENT_UPDATED (valor/vencimento mudou): Pix e linha digitável guardados saem', function () {
        [, $invoice] = recInvoiceWithInstructions();

        recWebhook(['event' => 'PAYMENT_UPDATED', 'payment' => recPayment('pay_1', 'PENDING', ['dueDate' => '2026-10-15', 'value' => 299.9])]);

        expect($invoice->fresh()->payment_instructions)->toBeNull()
            ->and($invoice->fresh()->due_at->toDateString())->toBe('2026-10-15');
    });

    it('PAYMENT_BANK_SLIP_CANCELLED: registro do boleto cancelado — instruções saem, a cobrança segue', function () {
        [$subscription, $invoice] = recInvoiceWithInstructions();

        $outcome = recWebhook(['event' => 'PAYMENT_BANK_SLIP_CANCELLED', 'payment' => recPayment('pay_1', 'OVERDUE')]);

        expect($invoice->fresh()->payment_instructions)->toBeNull()
            ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Pending)
            ->and($subscription->fresh()->status)->toBe(SubscriptionStatus::PastDue)
            ->and($outcome)->toBe('instructions_invalidated');
    });

    it('SUBSCRIPTION_UPDATED com valor diferente do contratado: alerta ao time; igual: nada', function () {
        [$subscription] = recPastDue();
        $subscription->update(['status' => SubscriptionStatus::Active, 'ends_at' => '2026-11-08 23:59:59', 'next_billing_at' => '2026-11-08 23:59:59']);
        $event = fn (array $over) => ['event' => 'SUBSCRIPTION_UPDATED', 'subscription' => [
            'object' => 'subscription', 'id' => 'sub_regua', 'value' => 299.9, 'cycle' => 'MONTHLY', 'nextDueDate' => '2026-11-08',
            'status' => 'ACTIVE', 'deleted' => false, 'billingType' => 'UNDEFINED', ...$over,
        ]];

        expect(recWebhook($event([])))->toBe('recurrence_in_sync');
        Notification::assertNothingSent();

        expect(recWebhook($event(['value' => 399.9])))->toBe('alert_recurrence_diverged')
            ->and($subscription->fresh()->amount)->toEqual('299.90');
        Notification::assertSentTo($this->saasAdmin, GatewayOperationalAlertNotification::class, fn ($n) => $n->kind === 'recurrence_diverged' && str_contains($n->params['fields'], 'value'));

        recWebhook($event(['nextDueDate' => '2026-11-20']));
        Notification::assertSentTo($this->saasAdmin, GatewayOperationalAlertNotification::class, fn ($n) => $n->kind === 'recurrence_diverged' && str_contains($n->params['fields'], 'next_due_date'));
    });

    it('ACCESS_TOKEN_EXPIRING_SOON: alerta ao time (o objeto da chave é guardado sem os dados)', function () {
        $outcome = recWebhook(['event' => 'ACCESS_TOKEN_EXPIRING_SOON', 'accessToken' => [
            'id'             => 'tok_1', 'name' => 'Produção EasyEye', 'enabled' => true, 'disableReason' => null,
            'expirationDate' => null, 'projectedExpirationDateByLackOfUse' => '2027-01-10',
        ]]);

        expect($outcome)->toBe('alert_access_token');
        Notification::assertSentTo($this->saasAdmin, GatewayOperationalAlertNotification::class, fn ($n) => $n->kind === 'access_token'
            && $n->params['event'] === 'ACCESS_TOKEN_EXPIRING_SOON');

        // Direto do payload (sem passar pelo armazenamento), os dados da chave vêm junto.
        $n = app(AsaasGateway::class)->parseWebhook(new GatewayWebhookInputDTO('asaas', [], '{}', ['event' => 'ACCESS_TOKEN_DISABLED', 'accessToken' => ['id' => 'tok_1', 'disableReason' => 'LACK_OF_USE', 'projectedExpirationDateByLackOfUse' => '2027-01-10']], 'evt_1'));
        expect($n->eventType)->toBe('access_token_alert')
            ->and($n->metadata['access_token'])->toMatchArray(['event' => 'ACCESS_TOKEN_DISABLED', 'disable_reason' => 'LACK_OF_USE', 'expires_by_lack' => '2027-01-10']);
    });
});

describe('notificações do Asaas desligadas', function () {
    function recCustomer(): CustomerDTO
    {
        return new CustomerDTO(
            entityId: (string) test()->clinic->id,
            name: 'Clínica Régua',
            email: 'financeiro@regua.test',
            document: '12.345.678/0001-95',
            phone: '(11) 98765-4321',
            externalReference: (string) test()->clinic->id,
        );
    }

    it('cliente novo é criado com notificationDisabled e marcado', function () {
        Http::fake([
            'https://api.asaas.com/v3/customers?*' => Http::response(['object' => 'list', 'hasMore' => false, 'data' => []]),
            'https://api.asaas.com/v3/customers'   => Http::response(['object' => 'customer', 'id' => 'cus_novo', 'notificationDisabled' => true]),
        ]);

        expect(app(AsaasGateway::class)->upsertCustomer(recCustomer()))->toBe('cus_novo');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['notificationDisabled'] === true);
        expect(GatewayCustomer::notificationsDisabled('asaas', 'cus_novo'))->toBeTrue();
    });

    it('cliente reaproveitado com notificações ligadas: desliga uma vez (PUT) e não repete', function () {
        Http::fake([
            'https://api.asaas.com/v3/customers?*' => Http::response(['object' => 'list', 'hasMore' => false, 'data' => [
                ['object' => 'customer', 'id' => 'cus_antigo', 'cpfCnpj' => '12345678000195', 'notificationDisabled' => false, 'deleted' => false],
            ]]),
            'https://api.asaas.com/v3/customers/cus_antigo' => Http::response(['object' => 'customer', 'id' => 'cus_antigo', 'notificationDisabled' => true]),
        ]);

        app(AsaasGateway::class)->upsertCustomer(recCustomer());
        app(AsaasGateway::class)->upsertCustomer(recCustomer());

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->url() === 'https://api.asaas.com/v3/customers/cus_antigo' && $r['notificationDisabled'] === true);
        expect(Http::recorded(fn (Request $r) => $r->method() === 'PUT'))->toHaveCount(1)
            ->and(GatewayCustomer::notificationsDisabled('asaas', 'cus_antigo'))->toBeTrue();
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    });

    it('comando one-off: --dry-run só mostra; depois desliga os que faltam e é idempotente', function () {
        Subscription::factory()->gateway('asaas')->for($this->clinic)->for($this->plan)->create(['gateway_customer_id' => 'cus_a']);
        Subscription::factory()->gateway('asaas')->for($this->clinic)->for($this->plan)->create(['gateway_customer_id' => 'cus_b']);
        GatewayCustomer::markNotificationsDisabled('asaas', 'cus_b');

        Http::fake([
            'https://api.asaas.com/v3/customers/cus_a' => function (Request $r) {
                return $r->method() === 'GET'
                    ? Http::response(['id' => 'cus_a', 'notificationDisabled' => false])
                    : Http::response(['id' => 'cus_a', 'notificationDisabled' => true]);
            },
        ]);

        $this->artisan('billing:asaas-disable-notifications', ['--dry-run' => true, '--pause-ms' => 0])->assertExitCode(0);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PUT');
        expect(GatewayCustomer::notificationsDisabled('asaas', 'cus_a'))->toBeFalse();

        $this->artisan('billing:asaas-disable-notifications', ['--pause-ms' => 0])->assertExitCode(0);
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->url() === 'https://api.asaas.com/v3/customers/cus_a');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'cus_b'));
        expect(GatewayCustomer::notificationsDisabled('asaas', 'cus_a'))->toBeTrue();

        $before = Http::recorded()->count();
        $this->artisan('billing:asaas-disable-notifications', ['--pause-ms' => 0])->assertExitCode(0);
        expect(Http::recorded()->count())->toBe($before);
    });
});
