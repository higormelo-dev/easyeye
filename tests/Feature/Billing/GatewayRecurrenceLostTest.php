<?php

declare(strict_types=1);

use App\Enums\{BillingCycle, ClientRule, SaasRule, SubscriptionAccessLevel, SubscriptionStatus};
use App\Jobs\Billing\RenewSubscriptionJob;
use App\Models\Billing\{BillingLog, SubscriptionChange, WebhookEvent};
use App\Models\{Entity, Plan, Subscription, User};
use App\Notifications\{GatewayRecurrenceLostNotification, SubscriptionDunningNotification};
use App\Services\Billing\{BillingCancellationService, GatewayRecurrenceLossService, SubscriptionCycleService};
use App\Services\SubscriptionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\{Carbon, Str};
use Illuminate\Support\Facades\{Http, Notification, Queue};

/**
 * Rodada 5 — A1: o Asaas desativa SOZINHO a recorrência
 * (SUBSCRIPTION_INACTIVATED / SUBSCRIPTION_DELETED — docs.asaas.com/docs/eventos-para-assinaturas).
 * Decisão do dono: avisar o manager e seguir pela régua, sem bloquear na
 * hora. Quem pagou segue com acesso até o fim do período; a cobrança passa
 * para a renovação local; alerta uma vez só; cancelamento nosso ou
 * assinatura que não é a vigente não geram alerta.
 */
beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-20 10:00:00'));
    config(['billing.gateways.asaas.webhook_secret' => 'whsec_r5_asaas']);
    Http::preventStrayRequests();
    Notification::fake();

    $this->saas  = Entity::factory()->create(['is_client' => false, 'active' => true]);
    $this->staff = User::factory()->create(['name' => 'Fábio Financeiro']);
    createEntityUser($this->saas, $this->staff, SaasRule::Financial->value);
    $this->support = User::factory()->create();
    createEntityUser($this->saas, $this->support, SaasRule::Support->value);

    $this->clinic                = Entity::factory()->make(['is_client' => true, 'active' => true, 'name' => 'Clínica Visão Clara']);
    $this->clinic->skipAutoTrial = true;
    $this->clinic->save();
    $this->clinicAdmin = User::factory()->create();
    createEntityUser($this->clinic, $this->clinicAdmin, ClientRule::Admin->value);

    $this->plan = Plan::factory()->create(['name' => 'Pro', 'active' => true]);

    // Em dia: pago em 08/10, período até 08/11 (recorrência nativa no Asaas).
    $this->subscription = Subscription::factory()->gateway()->for($this->clinic)->for($this->plan)->create([
        'gateway_subscription_id' => 'sub_r5_inativa',
        'billing_cycle'           => BillingCycle::Monthly,
        'amount'                  => 299.90,
        'last_payment_at'         => '2026-10-08 10:00:00',
        'starts_at'               => '2026-09-08 10:00:00',
        'ends_at'                 => '2026-11-08 23:59:59',
        'next_billing_at'         => '2026-11-08 23:59:59',
    ]);
});

afterEach(fn () => Carbon::setTestNow());

/** SUBSCRIPTION_* no formato documentado do Asaas. */
function r5RecurrenceEvent(string $event, string $recurrenceId, ?string $eventId = null): array
{
    return [
        'id'           => $eventId ?? 'evt_' . Str::random(32),
        'event'        => $event,
        'dateCreated'  => now()->format('Y-m-d H:i:s'),
        'account'      => ['id' => '47ed0d25-f9fb-4b35-b23a-d8895caf92b7', 'ownerId' => null],
        'subscription' => [
            'object'            => 'subscription',
            'id'                => $recurrenceId,
            'customer'          => 'cus_000005219613',
            'value'             => 299.9,
            'nextDueDate'       => '2026-11-08',
            'cycle'             => 'MONTHLY',
            'billingType'       => 'UNDEFINED',
            'deleted'           => $event === 'SUBSCRIPTION_DELETED',
            'status'            => 'INACTIVE',
            'externalReference' => (string) test()->subscription->id,
        ],
    ];
}

function r5PostAsaas(array $payload): void
{
    test()->postJson('/api/billing/webhooks/asaas', $payload, ['asaas-access-token' => 'whsec_r5_asaas'])->assertOk();
}

function r5Outcome(string $eventId): ?string
{
    return WebhookEvent::query()->where('external_event_id', $eventId)->value('normalized_payload')['outcome'] ?? null;
}

it('em dia: continua com acesso até o fim do período, a próxima fatura passa a ser local e o manager é avisado uma vez', function () {
    r5PostAsaas(r5RecurrenceEvent('SUBSCRIPTION_INACTIVATED', 'sub_r5_inativa', 'evt_r5_inactivated'));

    $sub = $this->subscription->fresh();

    // Nada bloqueado: mesma situação e acesso total até 08/11.
    expect($sub->status)->toBe(SubscriptionStatus::Active)
        ->and($sub->accessLevel())->toBe(SubscriptionAccessLevel::Full)
        ->and(app(SubscriptionService::class)->accessLevel($this->clinic))->toBe(SubscriptionAccessLevel::Full)
        ->and($sub->ends_at->toDateString())->toBe('2026-11-08')
        ->and($sub->cancelled_at)->toBeNull()
        // Renovação local: sem recorrência nativa, próxima cobrança no fim do período.
        ->and($sub->gateway_subscription_id)->toBeNull()
        ->and($sub->hasGatewayRecurrence())->toBeFalse()
        ->and($sub->nextBillingDate()->toDateString())->toBe('2026-11-08')
        ->and($sub->recurrence_alert_at)->not->toBeNull()
        ->and(r5Outcome('evt_r5_inactivated'))->toBe('alert_gateway_recurrence_lost');

    $change = SubscriptionChange::query()->where('change_type', GatewayRecurrenceLossService::CHANGE_TYPE)->sole();
    expect($change->subscription_id)->toBe($sub->id)
        ->and($change->metadata['external_subscription_id'])->toBe('sub_r5_inativa')
        ->and($change->metadata['gateway_event'])->toBe('SUBSCRIPTION_INACTIVATED')
        ->and($change->metadata['billing'])->toBe('local_renewal')
        ->and($change->metadata['alerted'])->toBeTrue();

    expect(BillingLog::query()->where('subscription_id', $sub->id)->where('level', 'warning')->where('message', 'like', 'O gateway desativou a recorrência%')->count())->toBe(1);

    // Alerta só para o time do SaaS (admin/financeiro/dono) — nunca para a clínica.
    Notification::assertSentToTimes($this->staff, GatewayRecurrenceLostNotification::class, 1);
    Notification::assertNotSentTo($this->support, GatewayRecurrenceLostNotification::class);
    Notification::assertNotSentTo($this->clinicAdmin, GatewayRecurrenceLostNotification::class);

    // A próxima fatura sai do agendador da renovação local (5 dias antes do vencimento).
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-03 01:00:00'));
    expect($sub->fresh()->isDueForLocalRenewal())->toBeTrue();
    app(SubscriptionCycleService::class)->dispatchDueRenewals();
    Queue::assertPushed(RenewSubscriptionJob::class, fn (RenewSubscriptionJob $job) => $job->subscriptionId === $sub->id);
});

it('sem pagamento, segue a régua normal (acesso limitado no D+3) — nunca bloqueio pelo gateway', function () {
    r5PostAsaas(r5RecurrenceEvent('SUBSCRIPTION_INACTIVATED', 'sub_r5_inativa'));

    // D+1 do vencimento sem pagamento: em atraso, acesso total com aviso.
    $this->travelTo(CarbonImmutable::parse('2026-11-09 09:00:00'));
    expect($this->subscription->fresh()->accessLevel())->toBe(SubscriptionAccessLevel::Full)
        ->and($this->subscription->fresh()->isInDunning())->toBeTrue();

    // D+3: acesso limitado pela régua.
    $this->travelTo(CarbonImmutable::parse('2026-11-11 09:00:00'));
    expect($this->subscription->fresh()->accessLevel())->toBe(SubscriptionAccessLevel::Limited);

    Notification::assertNothingSentTo($this->clinicAdmin, SubscriptionDunningNotification::class);
});

it('reentrega e o 2º aviso da mesma recorrência (INACTIVATED → DELETED) não duplicam o alerta', function () {
    $first = r5RecurrenceEvent('SUBSCRIPTION_INACTIVATED', 'sub_r5_inativa', 'evt_r5_first');

    r5PostAsaas($first);
    r5PostAsaas($first); // mesma entrega de novo
    r5PostAsaas(r5RecurrenceEvent('SUBSCRIPTION_DELETED', 'sub_r5_inativa', 'evt_r5_deleted'));

    expect(SubscriptionChange::query()->where('change_type', GatewayRecurrenceLossService::CHANGE_TYPE)->count())->toBe(1)
        ->and(r5Outcome('evt_r5_deleted'))->toBe('duplicate')
        ->and($this->subscription->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and(BillingLog::query()->where('message', 'like', 'O gateway desativou a recorrência%')->count())->toBe(1);

    Notification::assertSentToTimes($this->staff, GatewayRecurrenceLostNotification::class, 1);
});

it('cancelamento feito pelo próprio sistema: o aviso do gateway não vira alerta', function () {
    Http::fake(['https://api.asaas.com/v3/subscriptions/sub_r5_inativa' => Http::response(['deleted' => true, 'id' => 'sub_r5_inativa'])]);

    // O sistema pede o cancelamento da recorrência (ex.: troca, cortesia).
    app(BillingCancellationService::class)->cancelGatewayRecurrence($this->subscription->fresh(), (string) Str::uuid());

    r5PostAsaas(r5RecurrenceEvent('SUBSCRIPTION_DELETED', 'sub_r5_inativa', 'evt_r5_ours'));

    $sub = $this->subscription->fresh();

    expect(r5Outcome('evt_r5_ours'))->toBe('recurrence_cancelled_by_us')
        ->and($sub->recurrence_alert_at)->toBeNull()
        ->and($sub->status)->toBe(SubscriptionStatus::Active)
        ->and(BillingLog::query()->where('message', 'like', 'O gateway desativou a recorrência%')->exists())->toBeFalse();

    Notification::assertNothingSent();
});

it('assinatura cancelada pelo manager: o aviso do gateway é ignorado (com log), sem alerta e sem mudar nada', function () {
    $this->subscription->update(['status' => SubscriptionStatus::Cancelled, 'cancelled_at' => now()]);

    r5PostAsaas(r5RecurrenceEvent('SUBSCRIPTION_DELETED', 'sub_r5_inativa', 'evt_r5_cancelled'));

    expect(r5Outcome('evt_r5_cancelled'))->toBe('ignored_not_current')
        ->and($this->subscription->fresh()->gateway_subscription_id)->toBe('sub_r5_inativa')
        ->and($this->subscription->fresh()->recurrence_alert_at)->toBeNull()
        ->and(SubscriptionChange::query()->where('change_type', GatewayRecurrenceLossService::CHANGE_TYPE)->exists())->toBeFalse()
        // O registro do webhook fica no log de billing (nível info, sem alerta).
        ->and(BillingLog::query()->where('message', 'Webhook processado.')->where('level', 'info')->exists())->toBeTrue();

    Notification::assertNothingSent();
});

it('assinatura que não é a vigente (substituída por outra) e recorrência desconhecida: ignorados sem alerta', function () {
    // Nova assinatura (cortesia) substitui a paga sem tocar a linha antiga.
    $this->travel(1)->minutes();
    Subscription::factory()->complimentary()->for($this->clinic)->for($this->plan)->create(['ends_at' => now()->addMonth()]);

    r5PostAsaas(r5RecurrenceEvent('SUBSCRIPTION_INACTIVATED', 'sub_r5_inativa', 'evt_r5_old_row'));
    r5PostAsaas(r5RecurrenceEvent('SUBSCRIPTION_DELETED', 'sub_que_nao_existe', 'evt_r5_unknown'));

    expect(r5Outcome('evt_r5_old_row'))->toBe('ignored_not_current')
        ->and(r5Outcome('evt_r5_unknown'))->toBe('ignored')
        ->and($this->subscription->fresh()->gateway_subscription_id)->toBe('sub_r5_inativa')
        ->and(SubscriptionChange::query()->where('change_type', GatewayRecurrenceLossService::CHANGE_TYPE)->exists())->toBeFalse();

    Notification::assertNothingSent();
});

it('manager vê o aviso (badge, filtro, resumo, detalhe) e marca como visto', function () {
    r5PostAsaas(r5RecurrenceEvent('SUBSCRIPTION_INACTIVATED', 'sub_r5_inativa'));

    $as = fn () => $this->actingAs($this->staff)->withSession([
        'selected_entity_id'        => $this->saas->id,
        'selected_entity_is_client' => false,
        'selected_entity_user_rule' => 'financial',
    ]);

    $as()->get(route('manager.subscriptions.index', ['status' => 'recurrence_alert']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('total', 1)
            ->where('subscriptions.data.0.id', $this->subscription->id)
            ->whereNot('subscriptions.data.0.recurrence_alert', null)
            ->where('summary.recurrence_alert', 1));

    $as()->getJson(route('manager.subscriptions.show', $this->subscription))
        ->assertOk()
        ->assertJsonPath('data.recurrence_lost.gateway', 'asaas')
        ->assertJsonPath('data.recurrence_lost.gateway_event', 'SUBSCRIPTION_INACTIVATED')
        ->assertJsonPath('data.recurrence_lost.next_billing_at', $this->subscription->fresh()->next_billing_at->toIso8601String());

    $as()->getJson(route('manager.subscriptions.history', $this->subscription))
        ->assertOk()
        ->assertJsonFragment(['type' => GatewayRecurrenceLossService::CHANGE_TYPE, 'reason' => __('manager_subscriptions.history_reason_code.gateway_inactivated')]);

    $as()->postJson(route('manager.subscriptions.recurrence-alert.acknowledge', $this->subscription))
        ->assertOk()
        ->assertJsonPath('message', __('manager_subscriptions.recurrence_lost.acknowledged'));

    expect($this->subscription->fresh()->recurrence_alert_at)->toBeNull();
    $this->assertDatabaseHas('audit_logs', ['event' => 'manager.subscription.recurrence_alert.acknowledge']);

    $as()->getJson(route('manager.subscriptions.show', $this->subscription))->assertJsonPath('data.recurrence_lost', null);
});
