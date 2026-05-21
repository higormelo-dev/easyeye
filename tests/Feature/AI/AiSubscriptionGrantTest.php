<?php

use App\Domains\AI\Models\AiCreditLedgerEntry;
use App\Domains\AI\Models\AiCreditWallet;
use App\Domains\AI\Services\AiCreditWalletService;
use App\Enums\AI\AiLedgerEntryType;
use App\Enums\FeatureKey;
use App\Enums\SubscriptionStatus;
use App\Models\Entity;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makePlanWithAiCredits(int $credits): Plan
{
    $plan = Plan::factory()->create(['active' => true]);

    PlanFeature::create([
        'plan_id' => $plan->id,
        'feature' => FeatureKey::AiMonthlyCredits->value,
        'value'   => (string) $credits,
    ]);

    return $plan;
}

function makeActiveSubscription(Plan $plan, ?Entity $entity = null): Subscription
{
    $entity ??= Entity::factory()->create(['is_client' => false]);

    return Subscription::factory()->create([
        'entity_id' => $entity->id,
        'plan_id'   => $plan->id,
        'status'    => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(),
        'ends_at'   => now()->addMonth()->startOfDay(),
    ]);
}

test('grantForSubscription concede créditos quando o plano define AiMonthlyCredits > 0', function () {
    $plan         = makePlanWithAiCredits(80);
    $subscription = makeActiveSubscription($plan);

    // O observer já disparou o grant em created(). Verifica resultado direto.
    $wallet = AiCreditWallet::query()->where('entity_id', $subscription->entity_id)->firstOrFail();

    expect($wallet->balance)->toBe(80);

    $entries = AiCreditLedgerEntry::query()
        ->where('entity_id', $subscription->entity_id)
        ->where('type', AiLedgerEntryType::Grant->value)
        ->get();

    expect($entries)->toHaveCount(1);
    expect($entries->first()->amount)->toBe(80);
    expect($entries->first()->subscription_id)->toBe($subscription->id);
    expect($entries->first()->metadata['source'])->toBe('subscription_cycle');
    expect($entries->first()->metadata['plan_id'])->toBe($plan->id);
});

test('grantForSubscription não cria entry quando AiMonthlyCredits = 0', function () {
    $plan         = makePlanWithAiCredits(0);
    $subscription = makeActiveSubscription($plan);

    $result = app(AiCreditWalletService::class)->grantMonthlyCreditsForSubscription($subscription);

    expect($result)->toBeNull();

    $entries = AiCreditLedgerEntry::query()
        ->where('entity_id', $subscription->entity_id)
        ->where('type', AiLedgerEntryType::Grant->value)
        ->count();

    expect($entries)->toBe(0);
});

test('grantForSubscription é idempotente para o mesmo ciclo (ends_at)', function () {
    $plan         = makePlanWithAiCredits(50);
    $subscription = makeActiveSubscription($plan);
    $service      = app(AiCreditWalletService::class);

    // Já chamado pelo observer em created(). Chamar de novo manualmente.
    $second = $service->grantMonthlyCreditsForSubscription($subscription->fresh());
    $third  = $service->grantMonthlyCreditsForSubscription($subscription->fresh());

    expect($second)->not->toBeNull();
    expect($third->id)->toBe($second->id);

    $wallet = AiCreditWallet::query()->where('entity_id', $subscription->entity_id)->firstOrFail();
    expect($wallet->balance)->toBe(50);

    $count = AiCreditLedgerEntry::query()
        ->where('entity_id', $subscription->entity_id)
        ->where('type', AiLedgerEntryType::Grant->value)
        ->count();

    expect($count)->toBe(1);
});

test('renovação avança ends_at e dispara novo grant', function () {
    $plan         = makePlanWithAiCredits(40);
    $subscription = makeActiveSubscription($plan);

    // Renova movendo ends_at para frente — observer deve disparar novo grant.
    $subscription->update([
        'ends_at' => $subscription->ends_at->copy()->addMonth(),
    ]);

    $wallet = AiCreditWallet::query()->where('entity_id', $subscription->entity_id)->firstOrFail();

    expect($wallet->balance)->toBe(80);

    $grantCount = AiCreditLedgerEntry::query()
        ->where('entity_id', $subscription->entity_id)
        ->where('type', AiLedgerEntryType::Grant->value)
        ->count();

    expect($grantCount)->toBe(2);
});

test('updates que apenas mudam status mas mantém ends_at não duplicam grant', function () {
    $plan         = makePlanWithAiCredits(30);
    $subscription = makeActiveSubscription($plan);

    // Toca um campo qualquer sem mexer em status nem ends_at.
    $subscription->update(['last_billing_error' => 'noise']);
    $subscription->update(['last_billing_error' => null]);

    $count = AiCreditLedgerEntry::query()
        ->where('entity_id', $subscription->entity_id)
        ->where('type', AiLedgerEntryType::Grant->value)
        ->count();

    expect($count)->toBe(1);
});

test('subscription criada em trial não dispara grant', function () {
    $plan = makePlanWithAiCredits(60);

    Subscription::factory()->trial()->create([
        'entity_id' => Entity::factory()->create(['is_client' => false])->id,
        'plan_id'   => $plan->id,
    ]);

    $grants = AiCreditLedgerEntry::query()
        ->where('type', AiLedgerEntryType::Grant->value)
        ->count();

    expect($grants)->toBe(0);
});

test('conversão de trial para Active dispara grant uma única vez', function () {
    $plan   = makePlanWithAiCredits(25);
    $entity = Entity::factory()->create(['is_client' => false]);

    $subscription = Subscription::factory()->trial()->create([
        'entity_id' => $entity->id,
        'plan_id'   => $plan->id,
    ]);

    $subscription->update([
        'status'  => SubscriptionStatus::Active,
        'ends_at' => now()->addMonth()->startOfDay(),
    ]);

    $wallet = AiCreditWallet::query()->where('entity_id', $entity->id)->firstOrFail();
    expect($wallet->balance)->toBe(25);

    expect(AiCreditLedgerEntry::query()
        ->where('entity_id', $entity->id)
        ->where('type', AiLedgerEntryType::Grant->value)
        ->count())->toBe(1);
});
