<?php

use App\Enums\{BillingCycle, SubscriptionBillingMode, SubscriptionStatus};
use App\Models\{Entity, Plan, Subscription};
use App\Services\Billing\PlatformFinanceService;
use App\Services\ManagerDashboardService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * MRR (dashboard do manager e financeiro do SaaS) só conta cobrança
 * automática com pagamento já confirmado: contratação aguardando o 1º
 * pagamento ou com erro na emissão não é receita.
 */
beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-20 10:00:00'));
    $this->plan = Plan::factory()->create(['active' => true, 'billing_cycle' => 'monthly', 'price' => 299.90]);
});

afterEach(fn () => Carbon::setTestNow());

function mrrSubscription(array $attributes): Subscription
{
    $entity                = Entity::factory()->make(['is_client' => true, 'active' => true]);
    $entity->skipAutoTrial = true;
    $entity->save();

    return Subscription::factory()->for($entity)->for(test()->plan)->create([
        'billing_mode'  => SubscriptionBillingMode::Gateway,
        'gateway'       => 'asaas',
        'billing_cycle' => BillingCycle::Monthly,
        'amount'        => 299.90,
        'starts_at'     => '2026-09-01 10:00:00',
        ...$attributes,
    ]);
}

it('não conta contratação que nunca pagou (pendente, em atraso ou com erro) no MRR', function () {
    // Pagante: anual com valor contratado → 2.878,99 / 12.
    mrrSubscription([
        'status'          => SubscriptionStatus::Active,
        'billing_cycle'   => BillingCycle::Yearly,
        'amount'          => 2878.99,
        'ends_at'         => '2027-09-08 23:59:59',
        'last_payment_at' => '2026-09-07 10:00:00',
    ]);

    // Aguardando o 1º pagamento (com acesso até o vencimento).
    mrrSubscription([
        'status'          => SubscriptionStatus::PastDue,
        'billing_state'   => 'pending_activation',
        'next_billing_at' => '2026-10-23 23:59:59',
        'ends_at'         => '2026-10-23 23:59:59',
        'last_payment_at' => null,
    ]);

    // Ativa sem pagamento confirmado (estado inconsistente/antigo) e tentativa com erro.
    mrrSubscription(['status' => SubscriptionStatus::Active, 'ends_at' => '2026-11-08 23:59:59', 'last_payment_at' => null]);
    mrrSubscription([
        'status'           => SubscriptionStatus::Cancelled,
        'cancelled_reason' => 'activation_failed',
        'billing_state'    => 'error',
        'cancelled_at'     => '2026-10-20 09:00:00',
        'ends_at'          => '2026-10-20 09:00:00',
        'last_payment_at'  => null,
    ]);

    $dashboard = app(ManagerDashboardService::class);
    $kpis      = $dashboard->getFinancialKpis();
    $trend     = $dashboard->getMrrTrend()['values'];
    $expected  = round(2878.99 / 12, 2);

    expect(round($kpis['mrr'], 2))->toBe($expected)
        ->and($kpis['arpu'])->toBe($expected)
        ->and((float) $kpis['revenueAtRisk'])->toBe(0.0)
        ->and(round(end($trend), 2))->toBe($expected);

    $summary = app(PlatformFinanceService::class)->summary(now()->startOfMonth(), now());

    expect($summary['mrr']['amount'])->toBe($expected)
        ->and($summary['paying_clinics'])->toBe(1)
        ->and($summary['delinquency']['count'])->toBe(0);
});

it('assinatura em atraso que já pagou continua na inadimplência', function () {
    mrrSubscription([
        'status'          => SubscriptionStatus::PastDue,
        'billing_state'   => 'past_due',
        'ends_at'         => '2026-10-08 23:59:59',
        'next_billing_at' => '2026-10-08 23:59:59',
        'past_due_at'     => '2026-10-08 23:59:59',
        'last_payment_at' => '2026-09-07 10:00:00',
    ]);

    $summary = app(PlatformFinanceService::class)->summary(now()->startOfMonth(), now());

    expect($summary['delinquency']['count'])->toBe(1)
        ->and($summary['delinquency']['amount_at_risk'])->toBe(299.90)
        ->and((float) app(ManagerDashboardService::class)->getFinancialKpis()['revenueAtRisk'])->toBe(299.90);
});
