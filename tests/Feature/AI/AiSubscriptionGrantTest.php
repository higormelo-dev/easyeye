<?php

use App\Domains\AI\Models\{AiCreditLedgerEntry, AiCreditWallet};
use App\Domains\AI\Services\AiCreditWalletService;
use App\Enums\AI\AiLedgerEntryType;
use App\Enums\{FeatureKey, SubscriptionBillingMode, SubscriptionStatus};
use App\Models\{Entity, Plan, PlanFeature, Subscription};
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

test('grantForSubscription cria cota mensal (não balance comprado)', function () {
    $plan         = makePlanWithAiCredits(80);
    $subscription = makeActiveSubscription($plan);

    // O observer já disparou o grant em created(). Verifica resultado direto.
    $wallet = AiCreditWallet::query()->where('entity_id', $subscription->entity_id)->firstOrFail();

    expect($wallet->balance)->toBe(0);                // cota não vai para balance comprado
    expect($wallet->monthly_quota)->toBe(80);
    expect($wallet->monthly_quota_used)->toBe(0);
    expect($wallet->monthly_quota_lifetime_granted)->toBe(80);
    expect($wallet->quota_period_ends_at)->not->toBeNull();

    $entries = AiCreditLedgerEntry::query()
        ->where('entity_id', $subscription->entity_id)
        ->where('type', AiLedgerEntryType::Grant->value)
        ->get();

    expect($entries)->toHaveCount(1);
    expect($entries->first()->amount)->toBe(80);
    expect($entries->first()->subscription_id)->toBe($subscription->id);
    expect($entries->first()->metadata['source'])->toBe('subscription_window');
    expect($entries->first()->metadata['plan_id'])->toBe($plan->id);
    expect($entries->first()->metadata['kind'])->toBe('monthly_quota');
    // Janela de 1 mês a partir do dia da ativação (não o ends_at da assinatura).
    expect($entries->first()->metadata['window_start'])->toBe(today()->toDateString());
    expect($entries->first()->metadata['window_end'])->toBe(today()->addMonthNoOverflow()->toDateString());
    expect($wallet->quota_period_ends_at->toDateTimeString())->toBe(today()->addMonthNoOverflow()->toDateTimeString());
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

test('grantForSubscription é idempotente para a mesma janela', function () {
    $plan         = makePlanWithAiCredits(50);
    $subscription = makeActiveSubscription($plan);
    $service      = app(AiCreditWalletService::class);

    // Já chamado pelo observer em created(). Chamar de novo manualmente — não deve duplicar.
    $service->grantMonthlyCreditsForSubscription($subscription->fresh());
    $service->grantMonthlyCreditsForSubscription($subscription->fresh());

    $wallet = AiCreditWallet::query()->where('entity_id', $subscription->entity_id)->firstOrFail();
    expect($wallet->monthly_quota)->toBe(50);
    expect($wallet->balance)->toBe(0);

    $count = AiCreditLedgerEntry::query()
        ->where('entity_id', $subscription->entity_id)
        ->where('type', AiLedgerEntryType::Grant->value)
        ->count();

    expect($count)->toBe(1);
});

test('ends_at avançando (renovação/Adicionar período) não reinicia a cota da janela', function () {
    $plan         = makePlanWithAiCredits(40);
    $subscription = makeActiveSubscription($plan);

    app(AiCreditWalletService::class)->reserve($subscription->entity_id, 25);

    $subscription->update([
        'ends_at' => $subscription->ends_at->copy()->addMonth(),
    ]);

    $wallet = AiCreditWallet::query()->where('entity_id', $subscription->entity_id)->firstOrFail();

    // A janela atual já foi concedida: nada de cota nova nem consumo zerado.
    expect($wallet->monthly_quota)->toBe(40);
    expect($wallet->monthly_quota_used)->toBe(25);
    expect($wallet->monthly_quota_lifetime_granted)->toBe(40);

    $grantCount = AiCreditLedgerEntry::query()
        ->where('entity_id', $subscription->entity_id)
        ->where('type', AiLedgerEntryType::Grant->value)
        ->count();

    expect($grantCount)->toBe(1);
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

test('conversão de trial para cobrança automática paga dispara grant uma única vez', function () {
    $plan   = makePlanWithAiCredits(25);
    $entity = Entity::factory()->create(['is_client' => false]);

    $subscription = Subscription::factory()->trial()->create([
        'entity_id' => $entity->id,
        'plan_id'   => $plan->id,
    ]);

    $subscription->update([
        'status'       => SubscriptionStatus::Active,
        'billing_mode' => SubscriptionBillingMode::Gateway,
        'ends_at'      => now()->addMonth()->startOfDay(),
    ]);
    $subscription->update(['ends_at' => now()->addMonths(2)->startOfDay()]);

    $wallet = AiCreditWallet::query()->where('entity_id', $entity->id)->firstOrFail();
    expect($wallet->balance)->toBe(0);          // cota não vai para balance comprado
    expect($wallet->monthly_quota)->toBe(25);

    expect(AiCreditLedgerEntry::query()
        ->where('entity_id', $entity->id)
        ->where('type', AiLedgerEntryType::Grant->value)
        ->count())->toBe(1);
});

test('trial ativado sem cobrança automática (cortesia) não recebe franquia', function () {
    $plan   = makePlanWithAiCredits(25);
    $entity = Entity::factory()->create(['is_client' => false]);

    $subscription = Subscription::factory()->trial()->create([
        'entity_id' => $entity->id,
        'plan_id'   => $plan->id,
    ]);

    $subscription->update([
        'status'       => SubscriptionStatus::Active,
        'billing_mode' => SubscriptionBillingMode::Complimentary,
        'ends_at'      => now()->addMonths(3),
    ]);

    expect(AiCreditLedgerEntry::query()
        ->where('entity_id', $entity->id)
        ->where('type', AiLedgerEntryType::Grant->value)
        ->count())->toBe(0);
});
