<?php

use App\Domains\AI\Exceptions\InsufficientAiCreditsException;
use App\Domains\AI\Models\{AiCreditLedgerEntry, AiCreditWallet};
use App\Domains\AI\Services\{AiCreditWalletService, AiQuotaService};
use App\Domains\AI\Support\AiQuotaWindow;
use App\DTOs\Billing\SubscriptionTerms;
use App\Enums\AI\AiLedgerEntryType;
use App\Enums\{BillingCycle, FeatureKey, SubscriptionAccessLevel, SubscriptionBillingMode, SubscriptionStatus};
use App\Models\{Entity, Plan, PlanFeature, Subscription};
use App\Services\Billing\SubscriptionManagementService;
use Carbon\{CarbonImmutable, CarbonInterface};
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/*
 * Franquia de IA (D6/D7): só cobrança automática paga recebe; a cota vale
 * por janelas de 1 mês ancoradas na ativação (anual/semestral/trimestral
 * recebem todo mês, sem acumular); "Adicionar período" e a renovação paga
 * não reiniciam o consumo; troca de plano mantém o consumido; cortesia não
 * tem franquia e a conversão para cortesia zera o que restava.
 */

beforeEach(function () {
    $this->entity                = Entity::factory()->make(['is_client' => true, 'active' => true]);
    $this->entity->skipAutoTrial = true;
    $this->entity->save();

    $this->wallet = app(AiCreditWalletService::class);
});

afterEach(fn () => Carbon::setTestNow());

function quotaPlan(int $credits): Plan
{
    $plan = Plan::factory()->create(['active' => true]);

    PlanFeature::create(['plan_id' => $plan->id, 'feature' => FeatureKey::AiMonthlyCredits->value, 'value' => (string) $credits]);
    PlanFeature::create(['plan_id' => $plan->id, 'feature' => FeatureKey::HasAiReportDrafting->value, 'value' => '1']);

    return $plan;
}

/** Cobrança automática paga (ativa até `$endsAt`). */
function paidSubscription(Entity $entity, Plan $plan, BillingCycle $cycle, CarbonInterface $endsAt): Subscription
{
    return Subscription::factory()->create([
        'entity_id'       => $entity->id,
        'plan_id'         => $plan->id,
        'billing_mode'    => SubscriptionBillingMode::Gateway,
        'billing_cycle'   => $cycle,
        'status'          => SubscriptionStatus::Active,
        'gateway'         => 'asaas',
        'starts_at'       => now(),
        'ends_at'         => $endsAt,
        'next_billing_at' => $endsAt,
        'last_payment_at' => now(),
    ]);
}

function quotaWallet(Entity $entity): AiCreditWallet
{
    return AiCreditWallet::query()->where('entity_id', $entity->id)->firstOrFail();
}

function quotaGrants(Entity $entity): int
{
    return AiCreditLedgerEntry::query()
        ->where('entity_id', $entity->id)
        ->where('type', AiLedgerEntryType::Grant->value)
        ->count();
}

// ── Cortesia (D6) ────────────────────────────────────────────────────────────

test('cortesia criada pelo manager não recebe franquia de IA', function () {
    app(SubscriptionManagementService::class)->create(
        $this->entity,
        quotaPlan(80),
        SubscriptionTerms::complimentary(CarbonImmutable::now(), CarbonImmutable::now()->addMonths(3)),
        'Parceiro',
    );

    $this->artisan('ai:grant-monthly-quotas')->assertSuccessful();

    expect(quotaGrants($this->entity))->toBe(0)
        ->and(app(AiQuotaService::class)->snapshot($this->entity->id)['monthly_quota'])->toBe(0);
});

test('converter a assinatura paga em cortesia zera a cota que restava, mas não o saldo comprado', function () {
    $plan         = quotaPlan(80);
    $subscription = paidSubscription($this->entity, $plan, BillingCycle::Monthly, now()->addMonth());

    $this->wallet->purchaseCredits($this->entity->id, 25); // compra ou cortesia de créditos do manager
    $this->wallet->reserve($this->entity->id, 30);         // 30 da franquia

    app(SubscriptionManagementService::class)->updateTerms(
        $subscription,
        $plan,
        SubscriptionTerms::complimentary(CarbonImmutable::now(), CarbonImmutable::now()->addMonths(2)),
        'Virou cortesia',
    );

    $wallet = quotaWallet($this->entity);
    expect($wallet->quotaRemaining())->toBe(0)
        ->and($wallet->monthly_quota)->toBe(0)
        ->and($wallet->balance)->toBe(25)
        ->and($wallet->totalAvailable())->toBe(25);

    $expire = AiCreditLedgerEntry::query()
        ->where('entity_id', $this->entity->id)
        ->where('type', AiLedgerEntryType::Expire->value)
        ->sole();

    expect($expire->amount)->toBe(-50)
        ->and($expire->subscription_id)->toBe($subscription->id)
        ->and($expire->metadata['reason'])->toBe('became_complimentary');

    // A cortesia segue sem franquia nos meses seguintes.
    Carbon::setTestNow(now()->addMonthNoOverflow()->addDay());
    $this->artisan('ai:grant-monthly-quotas')->assertSuccessful();

    expect(quotaGrants($this->entity))->toBe(1);
});

test('cortesia ou trial no lugar da assinatura paga também zeram a cota restante', function (string $replacement) {
    $plan = quotaPlan(80);
    paidSubscription($this->entity, $plan, BillingCycle::Monthly, now()->addMonth());

    $service = app(SubscriptionManagementService::class);

    $replacement === 'trial'
        ? $service->startTrial($this->entity, $plan, 7, BillingCycle::Monthly, 'Teste')
        : $service->create($this->entity, $plan, SubscriptionTerms::complimentary(CarbonImmutable::now(), CarbonImmutable::now()->addMonth()), 'Cortesia');

    expect(quotaWallet($this->entity)->quotaRemaining())->toBe(0);

    $expire = AiCreditLedgerEntry::query()->where('entity_id', $this->entity->id)->where('type', AiLedgerEntryType::Expire->value)->sole();
    expect($expire->metadata['reason'])->toBe($replacement === 'trial' ? 'replaced_by_trial' : 'replaced_by_complimentary');
})->with(['trial', 'complimentary']);

// ── Janelas mensais (D7) ─────────────────────────────────────────────────────

test('plano anual recebe a franquia todo mês por 12 meses, sem acumular', function () {
    // Âncora no dia 31: as janelas não transbordam (28/02, 31/03, 30/04…).
    Carbon::setTestNow('2026-01-31 10:00:00');

    $subscription = paidSubscription($this->entity, quotaPlan(80), BillingCycle::Yearly, now()->addYearNoOverflow());

    $anchor = CarbonImmutable::parse('2026-01-31');

    for ($month = 0; $month < 12; $month++) {
        $window = AiQuotaWindow::at($anchor, $month);

        if ($month > 0) {
            // Virou a janela: antes do agendador, a leitura da carteira já
            // concede a janela vigente — cota nova, sem a sobra da anterior.
            Carbon::setTestNow($window->start->setTime(0, 5));
            expect(app(AiQuotaService::class)->snapshot($this->entity->id))
                ->toMatchArray(['monthly_quota' => 80, 'consumed_credits' => 0, 'renews_on' => $window->end->toDateString()]);

            // O agendador das 00:20 (garantia) não concede de novo.
            Carbon::setTestNow($window->start->setTime(0, 20));
            $this->artisan('ai:grant-monthly-quotas')->assertSuccessful();
            expect(quotaGrants($this->entity))->toBe($month + 1);
        }

        $wallet = quotaWallet($this->entity);
        expect($wallet->monthly_quota)->toBe(80)
            ->and($wallet->monthly_quota_used)->toBe(0)
            ->and($wallet->quotaRemaining())->toBe(80)
            ->and($wallet->quota_period_ends_at->toDateString())->toBe($window->end->toDateString());

        // Gasta tudo no mês — no próximo volta a 80, não 80 + sobra.
        $this->wallet->reserve($this->entity->id, 80);

        // Rodar o agendador de novo no mesmo mês não concede outra vez.
        $this->artisan('ai:grant-monthly-quotas')->assertSuccessful();
        expect(quotaWallet($this->entity)->quotaRemaining())->toBe(0);
    }

    expect(quotaGrants($this->entity))->toBe(12)
        ->and(quotaWallet($this->entity)->monthly_quota_lifetime_granted)->toBe(12 * 80)
        ->and(AiCreditLedgerEntry::query()->where('subscription_id', $subscription->id)->pluck('metadata')->pluck('window_start')->filter()->values()->all())
        ->toBe(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31', '2026-06-30', '2026-07-31', '2026-08-31', '2026-09-30', '2026-10-31', '2026-11-30', '2026-12-31']);
});

test('"Adicionar período" não mexe na janela: a cobrança automática nem recebe período (ele acompanha os pagamentos)', function () {
    Carbon::setTestNow('2026-10-04 10:00:00');
    $subscription = paidSubscription($this->entity, quotaPlan(80), BillingCycle::Monthly, now()->addMonth());

    $this->wallet->reserve($this->entity->id, 70);

    expect(fn () => app(SubscriptionManagementService::class)->extend($subscription, 'days', 1, 'Cortesia comercial'))
        ->toThrow(ValidationException::class, __('manager_subscriptions.errors.extend_gateway'));

    $wallet = quotaWallet($this->entity);
    expect($subscription->fresh()->ends_at->toDateString())->toBe('2026-11-04')
        ->and($wallet->monthly_quota_used)->toBe(70)
        ->and($wallet->quotaRemaining())->toBe(10)
        ->and(quotaGrants($this->entity))->toBe(1);
});

test('renovação paga não concede de novo a janela já concedida pelo agendador', function () {
    Carbon::setTestNow('2026-03-01 10:00:00');
    $subscription = paidSubscription($this->entity, quotaPlan(40), BillingCycle::Monthly, CarbonImmutable::parse('2026-04-01 23:59:59'));

    Carbon::setTestNow('2026-04-01 00:20:00');
    $this->artisan('ai:grant-monthly-quotas')->assertSuccessful();
    $this->wallet->reserve($this->entity->id, 10);

    // Pagamento da renovação (webhook, repetido): ends_at avança.
    Carbon::setTestNow('2026-04-01 10:00:00');
    $subscription->update(['ends_at' => '2026-05-01 23:59:59', 'next_billing_at' => '2026-05-01 23:59:59', 'last_payment_at' => now()]);
    $subscription->fresh()->update(['last_payment_at' => now()->addMinute()]);

    $wallet = quotaWallet($this->entity);
    expect(quotaGrants($this->entity))->toBe(2)
        ->and($wallet->monthly_quota_used)->toBe(10)
        ->and($wallet->quota_period_ends_at->toDateString())->toBe('2026-05-01');
});

test('troca de plano no meio da janela aplica a cota do novo plano mantendo o consumido', function () {
    Carbon::setTestNow('2026-10-04 10:00:00');
    $subscription = paidSubscription($this->entity, quotaPlan(30), BillingCycle::Monthly, now()->addMonth());
    $this->wallet->reserve($this->entity->id, 20);

    Carbon::setTestNow('2026-10-15 10:00:00');
    $subscription->update(['plan_id' => quotaPlan(80)->id]);

    $wallet = quotaWallet($this->entity);
    expect($wallet->monthly_quota)->toBe(80)
        ->and($wallet->monthly_quota_used)->toBe(20)
        ->and($wallet->quotaRemaining())->toBe(60)
        ->and($wallet->quota_period_ends_at->toDateString())->toBe('2026-11-04')
        ->and(quotaGrants($this->entity))->toBe(1);

    $adjustment = AiCreditLedgerEntry::query()->where('entity_id', $this->entity->id)->where('type', AiLedgerEntryType::Adjustment->value)->sole();
    expect($adjustment->amount)->toBe(50)
        ->and($adjustment->metadata['adjustment_reason'])->toBe('quota_plan_change');

    // Rebaixar para um plano menor não devolve o que já foi usado.
    $subscription->fresh()->update(['plan_id' => quotaPlan(10)->id]);
    expect(quotaWallet($this->entity)->monthly_quota)->toBe(10)
        ->and(quotaWallet($this->entity)->quotaRemaining())->toBe(0);
});

// ── Concessão sob demanda (sem esperar o agendador) ──────────────────────────

test('virada da janela antes do agendador: a reserva recebe a janela vigente, uma vez só', function () {
    Carbon::setTestNow('2026-03-01 10:00:00');
    $subscription = paidSubscription($this->entity, quotaPlan(40), BillingCycle::Monthly, CarbonImmutable::parse('2026-04-01 23:59:59'));
    $this->wallet->reserve($this->entity->id, 40); // esgota a janela 01/03–01/04

    // 00:05 do dia da virada (cron das 00:20 ainda não rodou — ou falhou).
    Carbon::setTestNow('2026-04-01 00:05:00');
    $this->wallet->reserve($this->entity->id, 10);

    $wallet = quotaWallet($this->entity);
    expect($wallet->monthly_quota)->toBe(40)
        ->and($wallet->monthly_quota_used)->toBe(10)
        ->and($wallet->quota_period_ends_at->toDateString())->toBe('2026-05-01')
        ->and(quotaGrants($this->entity))->toBe(2)
        ->and(AiCreditLedgerEntry::query()->where('idempotency_key', "ai-quota-window:{$subscription->id}:2026-04-01")->exists())->toBeTrue();

    // Medidor, saldo e o agendador (mesma chave da janela) não concedem de novo.
    app(AiQuotaService::class)->snapshot($this->entity->id);
    $this->wallet->balance($this->entity->id);
    Carbon::setTestNow('2026-04-01 00:20:00');
    $this->artisan('ai:grant-monthly-quotas')->assertSuccessful();

    expect(quotaGrants($this->entity))->toBe(2)
        ->and(quotaWallet($this->entity)->monthly_quota_used)->toBe(10);
});

test('sem franquia, a leitura da carteira com a janela vencida não concede nada', function (Closure $setup) {
    $setup($this->entity, quotaPlan(40));
    $grants = quotaGrants($this->entity);

    expect(fn () => $this->wallet->reserve($this->entity->id, 10))->toThrow(InsufficientAiCreditsException::class)
        ->and(app(AiQuotaService::class)->snapshot($this->entity->id)['monthly_quota'])->toBe(0)
        ->and($this->wallet->balance($this->entity->id)['quota_remaining'])->toBe(0)
        ->and(quotaGrants($this->entity))->toBe($grants);
})->with([
    // Cortesia com a cota antiga já vencida.
    'cortesia' => function (Entity $entity, Plan $plan) {
        Carbon::setTestNow('2026-03-01 10:00:00');
        $courtesy = Subscription::factory()->complimentary()->for($entity)->for($plan)->create(['ends_at' => now()->addMonths(6)]);
        app(AiCreditWalletService::class)->grantMonthlyQuota($entity->id, 40, CarbonImmutable::parse('2026-04-01'), subscriptionId: $courtesy->id);
        Carbon::setTestNow('2026-04-01 00:05:00');
    },
    // Pagante com a renovação de 01/04 vencida há 4 dias (acesso limitado).
    'acesso limitado' => function (Entity $entity, Plan $plan) {
        Carbon::setTestNow('2026-03-01 10:00:00');
        $subscription = paidSubscription($entity, $plan, BillingCycle::Monthly, CarbonImmutable::parse('2026-04-01 23:59:59'));
        Carbon::setTestNow('2026-04-05 00:05:00');
        $subscription->update(['status' => SubscriptionStatus::PastDue, 'past_due_at' => '2026-04-01 23:59:59']);
        expect($subscription->fresh()->accessLevel())->toBe(SubscriptionAccessLevel::Limited);
    },
]);

// ── Acesso limitado / bloqueado (D4) ─────────────────────────────────────────

test('agendador não concede a janela nova para quem está com acesso limitado ou bloqueado', function (int $daysOverdue, SubscriptionAccessLevel $level, bool $granted) {
    Carbon::setTestNow('2026-03-01 10:00:00');
    $subscription = paidSubscription($this->entity, quotaPlan(40), BillingCycle::Monthly, CarbonImmutable::parse('2026-04-01 23:59:59'));

    // Renovação de 01/04 não paga: em atraso desde o vencimento.
    Carbon::setTestNow(CarbonImmutable::parse('2026-04-01 00:20:00')->addDays($daysOverdue));
    $subscription->update(['status' => SubscriptionStatus::PastDue, 'past_due_at' => '2026-04-01 23:59:59']);

    expect($subscription->fresh()->accessLevel())->toBe($level);

    $this->artisan('ai:grant-monthly-quotas')->assertSuccessful();

    expect(quotaGrants($this->entity))->toBe($granted ? 2 : 1);
})->with([
    'D+1 (acesso total)'    => [1, SubscriptionAccessLevel::Full, true],
    'D+4 (acesso limitado)' => [4, SubscriptionAccessLevel::Limited, false],
    'D+8 (sem acesso)'      => [8, SubscriptionAccessLevel::None, false],
]);

test('ao regularizar o pagamento, recebe a janela atual sem esperar o agendador', function () {
    Carbon::setTestNow('2026-03-01 10:00:00');
    $subscription = paidSubscription($this->entity, quotaPlan(40), BillingCycle::Monthly, CarbonImmutable::parse('2026-04-01 23:59:59'));

    Carbon::setTestNow('2026-04-05 09:00:00');
    $subscription->update(['status' => SubscriptionStatus::PastDue, 'past_due_at' => '2026-04-01 23:59:59']);
    $this->artisan('ai:grant-monthly-quotas')->assertSuccessful();
    expect(quotaGrants($this->entity))->toBe(1);

    // Pagou: volta a ficar ativa e ganha a janela 01/04–01/05.
    $subscription->fresh()->update([
        'status'          => SubscriptionStatus::Active,
        'past_due_at'     => null,
        'ends_at'         => '2026-05-01 23:59:59',
        'last_payment_at' => now(),
    ]);

    $wallet = quotaWallet($this->entity);
    expect(quotaGrants($this->entity))->toBe(2)
        ->and($wallet->quotaRemaining())->toBe(40)
        ->and($wallet->quota_period_ends_at->toDateString())->toBe('2026-05-01');
});

test('contratação aguardando o 1º pagamento não recebe franquia', function () {
    Subscription::factory()->create([
        'entity_id'       => $this->entity->id,
        'plan_id'         => quotaPlan(80)->id,
        'billing_mode'    => SubscriptionBillingMode::Gateway,
        'status'          => SubscriptionStatus::PastDue,
        'gateway'         => 'asaas',
        'ends_at'         => now()->addDays(3)->endOfDay(),
        'next_billing_at' => now()->addDays(3)->endOfDay(),
    ]);

    $this->artisan('ai:grant-monthly-quotas')->assertSuccessful();

    expect(quotaGrants($this->entity))->toBe(0);
});

test('janela mensal não transborda no fim do mês', function () {
    $anchor = CarbonImmutable::parse('2026-01-31');

    expect(AiQuotaWindow::containing($anchor, CarbonImmutable::parse('2026-02-27 23:59:59'))->start->toDateString())->toBe('2026-01-31')
        ->and(AiQuotaWindow::containing($anchor, CarbonImmutable::parse('2026-02-28 00:00:00'))->start->toDateString())->toBe('2026-02-28')
        ->and(AiQuotaWindow::containing($anchor, CarbonImmutable::parse('2026-02-28 00:00:00'))->end->toDateString())->toBe('2026-03-31')
        ->and(AiQuotaWindow::containing($anchor, CarbonImmutable::parse('2027-01-31 00:00:00'))->index)->toBe(12);
});
