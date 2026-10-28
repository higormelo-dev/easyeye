<?php

use App\Domains\AI\Models\{AiCreditWallet, AiRun};
use App\Domains\AI\Services\{AiCreditWalletService, AiQuotaService};
use App\Enums\AI\AiRunStatus;
use App\Enums\{FeatureKey, SubscriptionAccessLevel, SubscriptionBillingMode, SubscriptionStatus};
use App\Models\{Entity, Plan, PlanFeature, Subscription};
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/*
 * O medidor X/Y lê a carteira — a mesma fonte que a reserva usa para
 * bloquear — e não mais a soma de ai_runs no mês civil (bug B3/COTA-4).
 */

beforeEach(function () {
    $this->service = app(AiQuotaService::class);
    $this->wallet  = app(AiCreditWalletService::class);

    $this->entity                = Entity::factory()->make(['is_client' => true, 'active' => true]);
    $this->entity->skipAutoTrial = true;
    $this->entity->save();

    $this->plan = Plan::factory()->create(['active' => true]);
    PlanFeature::factory()->limit(FeatureKey::AiMonthlyCredits, 80)->for($this->plan)->create();
});

afterEach(fn () => Carbon::setTestNow());

it('mostra franquia, usado, renovação e saldo avulso da carteira', function () {
    Carbon::setTestNow('2026-10-04 10:00:00');

    // Cobrança automática paga: a ativação concede a janela 04/10–04/11.
    Subscription::factory()->for($this->entity)->for($this->plan)->create([
        'billing_mode'    => SubscriptionBillingMode::Gateway,
        'status'          => SubscriptionStatus::Active,
        'gateway'         => 'asaas',
        'ends_at'         => now()->addMonth(),
        'last_payment_at' => now(),
    ]);
    $this->wallet->purchaseCredits($this->entity->id, 15);
    $this->wallet->reserve($this->entity->id, 20);

    $snap = $this->service->snapshot($this->entity->id);

    expect($snap)->toMatchArray([
        'monthly_quota_cents'           => 80,
        'monthly_quota_used_cents'      => 20,
        'monthly_quota_remaining_cents' => 60,
        'usage_percent'                 => 25.0,
        'renews_on'                     => '2026-11-04',
        'expires_on'                    => null,
        'purchased_balance_cents'       => 15,
        'available_cents'               => 75,
    ]);
});

it('assinatura que não ganha franquia não promete renovação: a cota que sobrou mostra até quando vale', function (Closure $subscription) {
    Carbon::setTestNow('2026-10-04 10:00:00');

    $current = $subscription($this->entity, $this->plan);

    // Concessão antiga que ficou na carteira (período = fim da assinatura, 31/12 23:59:59).
    $this->wallet->grantMonthlyQuota($this->entity->id, 80, CarbonImmutable::parse('2026-12-31 23:59:59'), subscriptionId: $current->id);
    $this->wallet->reserve($this->entity->id, 30);

    expect($current->fresh()->earnsMonthlyAiQuota())->toBeFalse()
        ->and($this->service->snapshot($this->entity->id))->toMatchArray([
            'monthly_quota_cents'           => 80,
            'monthly_quota_used_cents'      => 30,
            'monthly_quota_remaining_cents' => 50,
            'renews_on'                     => null,
            'expires_on'                    => '2026-12-31',
        ]);
})->with([
    // Convertida em cortesia pelas migrações, com a cota antiga ainda em vigor.
    'cortesia convertida' => fn (Entity $entity, Plan $plan) => Subscription::factory()->complimentary()->for($entity)->for($plan)->create([
        'ends_at' => '2026-12-31 23:59:59',
    ]),
    // Pagante no acesso limitado (D+4): a janela seguinte só vem com o pagamento.
    'pagante com acesso limitado' => function (Entity $entity, Plan $plan) {
        $subscription = Subscription::factory()->for($entity)->for($plan)->create([
            'billing_mode'    => SubscriptionBillingMode::Gateway,
            'status'          => SubscriptionStatus::PastDue,
            'gateway'         => 'asaas',
            'last_payment_at' => now()->subMonth(),
            'ends_at'         => now()->subDays(4)->endOfDay(),
            'past_due_at'     => now()->subDays(4)->endOfDay(),
        ]);

        expect($subscription->accessLevel())->toBe(SubscriptionAccessLevel::Limited);

        return $subscription;
    },
]);

it('é igual ao que a carteira libera para a reserva', function () {
    $this->wallet->grantMonthlyQuota($this->entity->id, 30, CarbonImmutable::now()->addMonth());
    $this->wallet->purchaseCredits($this->entity->id, 5);
    $this->wallet->reserve($this->entity->id, 32);

    $snap    = $this->service->snapshot($this->entity->id);
    $balance = $this->wallet->balance($this->entity->id);

    expect($snap['available_cents'])->toBe($balance['available_cents'])
        ->and($snap['monthly_quota_remaining_cents'])->toBe($balance['quota_remaining_cents'])
        ->and($snap['monthly_quota_used_cents'])->toBe($balance['quota_used_cents'])
        ->and($snap['monthly_quota_cents'])->toBe($balance['quota_total_cents'])
        ->and($snap['available_cents'])->toBe(3);
});

it('rascunho rejeitado continua contando (a reserva consumida não volta)', function () {
    $this->wallet->grantMonthlyQuota($this->entity->id, 30, CarbonImmutable::now()->addMonth());

    $run = AiRun::factory()->create(['entity_id' => $this->entity->id, 'status' => AiRunStatus::Reserved->value]);
    $this->wallet->reserve($this->entity->id, 30, aiRunId: $run->id);
    $this->wallet->consumeReservation($this->entity->id, 30, aiRunId: $run->id);
    $run->update(['status' => AiRunStatus::Rejected->value, 'consumed_charge_cents' => 30]);

    $snap = $this->service->snapshot($this->entity->id);

    // O medidor antigo (ai_runs aprovados do mês) mostraria 0/30 e o botão liberado.
    expect($snap['monthly_quota_used_cents'])->toBe(30)
        ->and($snap['available_cents'])->toBe(0);
});

it('não zera na virada do mês civil, só na virada da janela', function () {
    Carbon::setTestNow('2026-01-20 09:00:00');
    $this->wallet->grantMonthlyQuota($this->entity->id, 80, CarbonImmutable::parse('2026-02-20 00:00:00'));
    $this->wallet->reserve($this->entity->id, 80);

    Carbon::setTestNow('2026-02-02 09:00:00');
    expect($this->service->snapshot($this->entity->id))
        ->toMatchArray(['monthly_quota_used_cents' => 80, 'monthly_quota_remaining_cents' => 0, 'usage_percent' => 100.0]);

    // Janela vencida (sem nova concessão): não há franquia em vigor.
    Carbon::setTestNow('2026-02-20 00:00:01');
    expect($this->service->snapshot($this->entity->id))
        ->toMatchArray(['monthly_quota_cents' => 0, 'monthly_quota_used_cents' => 0, 'usage_percent' => null, 'renews_on' => null]);
});

it('empresa sem carteira tem medidor zerado', function () {
    $snap = $this->service->snapshot($this->entity->id);

    expect($snap['monthly_quota_cents'])->toBe(0)
        ->and($snap['available_cents'])->toBe(0)
        ->and($snap['usage_percent'])->toBeNull()
        ->and(AiCreditWallet::query()->where('entity_id', $this->entity->id)->exists())->toBeFalse();
});
