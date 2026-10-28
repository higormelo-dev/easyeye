<?php

declare(strict_types=1);

use App\Enums\Billing\DunningStep;
use App\Enums\{SubscriptionAccessLevel, SubscriptionBillingMode, SubscriptionStatus};
use App\Models\{Entity, Plan, PlanPrice, Subscription};
use App\Services\Billing\SubscriptionNoticeService;
use Carbon\CarbonImmutable;
use Database\Seeders\DataFakersSeeder;
use Illuminate\Support\Carbon;

/**
 * Massa do ambiente de teste (DataFakersSeeder): cada cenário de assinatura
 * é um estado possível pelas regras de cobrança — o QA vê o aviso de atraso,
 * o acesso limitado e a contratação pendente, e nunca uma cortesia "em
 * atraso" sem régua.
 */
beforeEach(fn () => Carbon::setTestNow(CarbonImmutable::parse('2026-10-05 10:00:00')));

afterEach(fn () => Carbon::setTestNow());

function seededScenario(string $scenario): Subscription
{
    $plan = Plan::query()->firstOrCreate(['slug' => 'pro-seed'], Plan::factory()->make(['slug' => 'pro-seed', 'price' => 299.90])->toArray());
    PlanPrice::query()->firstOrCreate(['plan_id' => $plan->id, 'billing_cycle' => 'monthly'], ['price' => 299.90]);

    // Empresa com o trial automático, como as do seeder.
    $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);

    $seed = (new ReflectionClass(DataFakersSeeder::class))->getMethod('seedSubscription');
    $seed->invoke(new DataFakersSeeder(), $entity, $plan->fresh('prices'), $scenario);

    return Subscription::bestAccessibleFor((string) $entity->id)
        ?? Subscription::query()->forEntity((string) $entity->id)->currentFirst()->firstOrFail();
}

it('cada cenário é coerente com as regras de cobrança e substitui o trial automático', function (string $scenario, Closure $check) {
    $subscription = seededScenario($scenario);

    expect(Subscription::query()->forEntity((string) $subscription->entity_id)->inForce()->count())->toBe($scenario === 'expired' ? 0 : 1)
        ->and($subscription->status === SubscriptionStatus::PastDue && $subscription->isComplimentary())->toBeFalse();

    $check($subscription, app(SubscriptionNoticeService::class)->banner($subscription));
})->with([
    'pagante em dia' => ['gateway_paid', function (Subscription $s, ?array $banner) {
        expect($s->billing_mode)->toBe(SubscriptionBillingMode::Gateway)
            ->and($s->hasBeenPaid())->toBeTrue()
            ->and($s->accessLevel())->toBe(SubscriptionAccessLevel::Full)
            ->and($s->next_billing_at->isFuture())->toBeTrue()
            ->and($banner)->toBeNull();
    }],
    'em atraso D+1 (aviso com acesso total)' => ['gateway_overdue', function (Subscription $s, ?array $banner) {
        expect($s->isInDunning())->toBeTrue()
            ->and($s->daysOverdue())->toBe(1)
            ->and($s->dunningStage())->toBe(DunningStep::Overdue)
            ->and($s->accessLevel())->toBe(SubscriptionAccessLevel::Full)
            ->and($banner['kind'])->toBe('overdue');
    }],
    'em atraso D+4 (acesso limitado)' => ['gateway_limited', function (Subscription $s, ?array $banner) {
        expect($s->dunningStage())->toBe(DunningStep::Limited)
            ->and($s->accessLevel())->toBe(SubscriptionAccessLevel::Limited)
            ->and($banner['kind'])->toBe('limited');
    }],
    'contratação aguardando o 1º pagamento' => ['awaiting_first_payment', function (Subscription $s, ?array $banner) {
        expect($s->isAwaitingFirstPayment())->toBeTrue()
            ->and($s->accessLevel())->toBe(SubscriptionAccessLevel::Full)
            ->and($banner['kind'])->toBe('first_payment_pending');
    }],
    'cortesia' => ['complimentary', function (Subscription $s, ?array $banner) {
        expect($s->isComplimentary())->toBeTrue()
            ->and($s->accessLevel())->toBe(SubscriptionAccessLevel::Full);
    }],
    'trial' => ['trial', function (Subscription $s, ?array $banner) {
        expect($s->isOnTrial())->toBeTrue();
    }],
    'cortesia vencida' => ['expired', function (Subscription $s, ?array $banner) {
        expect($s->status)->toBe(SubscriptionStatus::Expired)
            ->and($s->isComplimentary())->toBeTrue()
            ->and($s->hasAccess())->toBeFalse();
    }],
]);
