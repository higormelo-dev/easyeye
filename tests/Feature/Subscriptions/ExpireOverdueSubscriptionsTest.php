<?php

use App\Enums\Billing\BillingEventType;
use App\Enums\{SubscriptionBillingMode, SubscriptionStatus};
use App\Jobs\Billing\ExpireOverdueSubscriptionsJob;
use App\Models\Billing\FinancialEvent;
use App\Models\{Entity, Plan, Subscription};
use App\Services\SubscriptionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * Expiração diária: cortesia vencida expira; a cobrança automática não
 * expira — sem o pagamento da renovação, entra em atraso desde o fim do
 * período (a régua de cobrança decide o resto).
 */
beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-11-09 00:10:00'));
    $this->plan = Plan::factory()->create(['active' => true]);
});

afterEach(fn () => Carbon::setTestNow());

function expireSubscription(array $attributes): Subscription
{
    $entity                = Entity::factory()->make(['is_client' => true, 'active' => true]);
    $entity->skipAutoTrial = true;
    $entity->save();

    return Subscription::factory()->for($entity)->for(test()->plan)->create($attributes);
}

it('expira só a cortesia vencida; a cobrança automática vencida não expira', function () {
    $courtesy = expireSubscription(['billing_mode' => SubscriptionBillingMode::Complimentary, 'ends_at' => '2026-11-08 23:59:59']);
    $gateway  = expireSubscription([
        'billing_mode'            => SubscriptionBillingMode::Gateway,
        'gateway'                 => 'asaas',
        'gateway_subscription_id' => 'sub_mensal_123',
        'billing_state'           => 'paid',
        'ends_at'                 => '2026-11-08 23:59:59',
        'next_billing_at'         => '2026-11-08 23:59:59',
        'last_payment_at'         => '2026-10-07 14:30:00',
    ]);

    expect(app(SubscriptionService::class)->expireOverdue())->toBe(1)
        ->and($courtesy->fresh()->status)->toBe(SubscriptionStatus::Expired)
        ->and($gateway->fresh()->status)->toBe(SubscriptionStatus::Active);
});

it('cobrança automática com o período pago vencido e sem pagamento entra em atraso desde ends_at', function () {
    $lapsed = expireSubscription([
        'billing_mode'            => SubscriptionBillingMode::Gateway,
        'gateway'                 => 'asaas',
        'gateway_subscription_id' => 'sub_mensal_456',
        'billing_state'           => 'paid',
        'ends_at'                 => '2026-11-08 23:59:59',
        'next_billing_at'         => '2026-11-08 23:59:59',
        'last_payment_at'         => '2026-10-07 14:30:00',
    ]);
    $current = expireSubscription([
        'billing_mode'    => SubscriptionBillingMode::Gateway,
        'gateway'         => 'asaas',
        'ends_at'         => '2026-11-20 23:59:59',
        'last_payment_at' => '2026-10-20 10:00:00',
    ]);
    $courtesy = expireSubscription(['billing_mode' => SubscriptionBillingMode::Complimentary, 'ends_at' => '2026-11-08 23:59:59']);

    (new ExpireOverdueSubscriptionsJob())->handle(app(SubscriptionService::class));

    $lapsed->refresh();

    expect($lapsed->status)->toBe(SubscriptionStatus::PastDue)
        ->and($lapsed->billing_state)->toBe('past_due')
        ->and($lapsed->past_due_at->toDateTimeString())->toBe('2026-11-08 23:59:59')
        ->and($lapsed->last_billing_error)->toBe(__('manager_subscriptions.billing_errors.period_lapsed'))
        ->and($lapsed->gateway_subscription_id)->toBe('sub_mensal_456')
        ->and(FinancialEvent::where('subscription_id', $lapsed->id)->where('event_type', BillingEventType::SubscriptionPastDue->value)->count())->toBe(1)
        ->and($current->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($courtesy->fresh()->status)->toBe(SubscriptionStatus::Expired);

    // Rodar de novo não duplica nada.
    expect(app(SubscriptionService::class)->markLapsedAsPastDue())->toBe(0);
});
