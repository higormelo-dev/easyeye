<?php

use App\Domains\AI\Exceptions\InsufficientAiCreditsException;
use App\Domains\AI\Models\AiCreditLedgerEntry;
use App\Domains\AI\Models\AiCreditWallet;
use App\Domains\AI\Services\AiCreditWalletService;
use App\Enums\AI\AiLedgerEntryType;
use App\Enums\AI\AiProvider;
use App\Enums\SubscriptionStatus;
use App\Models\Entity;
use App\Models\Plan;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => false]);
    $this->plan = Plan::factory()->create(['active' => true]);
    $this->subscription = Subscription::factory()->create([
        'entity_id' => $this->entity->id,
        'plan_id'   => $this->plan->id,
        'status'    => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(),
        'ends_at'   => now()->addMonth(),
    ]);

    $this->service = app(AiCreditWalletService::class);
});

test('grantMonthlyQuota cria carteira, popula cota e reseta ciclo', function () {
    $periodEnds = CarbonImmutable::now()->addMonth();

    $entry = $this->service->grantMonthlyQuota(
        entityId: $this->entity->id,
        amount: 120,
        periodEndsAt: $periodEnds,
        subscriptionId: $this->subscription->id,
        description: 'Cota do plano',
    );

    expect($entry->type)->toBe(AiLedgerEntryType::Grant);
    expect($entry->amount)->toBe(120);

    $wallet = AiCreditWallet::query()->where('entity_id', $this->entity->id)->firstOrFail();

    expect($wallet->balance)->toBe(0);                  // cota não vai para balance
    expect($wallet->monthly_quota)->toBe(120);
    expect($wallet->monthly_quota_used)->toBe(0);
    expect($wallet->quota_period_ends_at->toDateString())->toBe($periodEnds->toDateString());
    expect($wallet->monthly_quota_lifetime_granted)->toBe(120);
});

test('purchaseCredits incrementa balance comprado (não cota)', function () {
    $entry = $this->service->purchaseCredits(
        entityId: $this->entity->id,
        amount: 300,
        subscriptionId: $this->subscription->id,
        description: 'Top-up',
    );

    expect($entry->type)->toBe(AiLedgerEntryType::Purchase);
    expect($entry->amount)->toBe(300);
    expect($entry->balance_after)->toBe(300);

    $wallet = AiCreditWallet::query()->where('entity_id', $this->entity->id)->firstOrFail();

    expect($wallet->balance)->toBe(300);
    expect($wallet->lifetime_purchased)->toBe(300);
    expect($wallet->monthly_quota)->toBe(0);            // compra não mexe na cota
});

test('reserve consome cota primeiro, depois balance', function () {
    // Cota: 60 | Balance comprado: 100 → total 160
    $this->service->grantMonthlyQuota(
        entityId: $this->entity->id,
        amount: 60,
        periodEndsAt: CarbonImmutable::now()->addMonth(),
    );
    $this->service->purchaseCredits(entityId: $this->entity->id, amount: 100);

    // Reserva 80: 60 da cota + 20 do balance
    $reserve = $this->service->reserve(entityId: $this->entity->id, amount: 80);

    expect($reserve->type)->toBe(AiLedgerEntryType::Reserve);
    expect($reserve->amount)->toBe(-80);
    expect($reserve->metadata['from_quota'])->toBe(60);
    expect($reserve->metadata['from_balance'])->toBe(20);

    $wallet = AiCreditWallet::query()->where('entity_id', $this->entity->id)->firstOrFail();
    expect($wallet->monthly_quota_used)->toBe(60);      // cota esgotada
    expect($wallet->balance)->toBe(80);                 // 100 - 20
    expect($wallet->reserved_balance)->toBe(20);
});

test('reserve lança InsufficientAiCreditsException quando saldo total é insuficiente', function () {
    $this->service->purchaseCredits(entityId: $this->entity->id, amount: 30);

    expect(fn () => $this->service->reserve(entityId: $this->entity->id, amount: 50))
        ->toThrow(InsufficientAiCreditsException::class);
});

test('consumeReservation grava provider e incrementa lifetime_consumed_<provider>', function () {
    $this->service->purchaseCredits(entityId: $this->entity->id, amount: 100);
    $this->service->reserve(entityId: $this->entity->id, amount: 60);

    $consume = $this->service->consumeReservation(
        entityId: $this->entity->id,
        amount: 60,
        provider: AiProvider::Anthropic,
    );

    expect($consume->type)->toBe(AiLedgerEntryType::Consume);
    expect($consume->provider)->toBe(AiProvider::Anthropic);

    $wallet = AiCreditWallet::query()->where('entity_id', $this->entity->id)->firstOrFail();
    expect($wallet->reserved_balance)->toBe(0);
    expect($wallet->lifetime_consumed)->toBe(60);
    expect($wallet->lifetime_consumed_anthropic)->toBe(60);
    expect($wallet->lifetime_consumed_openai)->toBe(0);
});

test('releaseReservation devolve primeiro à cota, depois ao balance', function () {
    $this->service->grantMonthlyQuota(
        entityId: $this->entity->id,
        amount: 40,
        periodEndsAt: CarbonImmutable::now()->addMonth(),
    );
    $this->service->purchaseCredits(entityId: $this->entity->id, amount: 100);

    // Reserva 60: 40 da cota + 20 do balance
    $this->service->reserve(entityId: $this->entity->id, amount: 60);

    // Libera 50: 40 volta para cota + 10 volta para balance
    $release = $this->service->releaseReservation(entityId: $this->entity->id, amount: 50);

    expect($release->type)->toBe(AiLedgerEntryType::Release);
    expect($release->metadata['to_quota'])->toBe(40);
    expect($release->metadata['to_balance'])->toBe(10);

    $wallet = AiCreditWallet::query()->where('entity_id', $this->entity->id)->firstOrFail();
    expect($wallet->monthly_quota_used)->toBe(0);
    expect($wallet->balance)->toBe(90);                 // 80 + 10
    expect($wallet->reserved_balance)->toBe(10);
});

test('idempotency key evita duplicidade de grant da cota', function () {
    $key = 'ai-grant-key-001';
    $end = CarbonImmutable::now()->addMonth();

    $first = $this->service->grantMonthlyQuota(
        entityId: $this->entity->id,
        amount: 80,
        periodEndsAt: $end,
        idempotencyKey: $key,
    );

    $second = $this->service->grantMonthlyQuota(
        entityId: $this->entity->id,
        amount: 80,
        periodEndsAt: $end,
        idempotencyKey: $key,
    );

    expect($first->id)->toBe($second->id);
    expect(AiCreditLedgerEntry::query()->where('entity_id', $this->entity->id)->count())->toBe(1);
});

test('refund estorna créditos para o balance comprado', function () {
    $this->service->purchaseCredits(entityId: $this->entity->id, amount: 200);
    $this->service->reserve(entityId: $this->entity->id, amount: 90);
    $this->service->consumeReservation(entityId: $this->entity->id, amount: 90);

    $wallet = AiCreditWallet::query()->where('entity_id', $this->entity->id)->firstOrFail();
    expect($wallet->balance)->toBe(110);
    expect($wallet->lifetime_consumed)->toBe(90);

    $refund = $this->service->refund(
        entityId: $this->entity->id,
        amount: 90,
        description: 'Estorno por falha no provider.',
    );

    expect($refund->type)->toBe(AiLedgerEntryType::Refund);
    expect($refund->amount)->toBe(90);

    $wallet = $wallet->fresh();
    expect($wallet->balance)->toBe(200);                // 110 + 90 refund
    expect($wallet->lifetime_consumed)->toBe(90);       // não mexe no histórico
});

test('refund é idempotente com mesma idempotencyKey', function () {
    $this->service->purchaseCredits(entityId: $this->entity->id, amount: 100);

    $key = 'refund-key-001';
    $first  = $this->service->refund(entityId: $this->entity->id, amount: 40, idempotencyKey: $key);
    $second = $this->service->refund(entityId: $this->entity->id, amount: 40, idempotencyKey: $key);

    expect($first->id)->toBe($second->id);

    $wallet = AiCreditWallet::query()->where('entity_id', $this->entity->id)->firstOrFail();
    expect($wallet->balance)->toBe(140);                // 100 + 40 (uma única vez)
});

test('balance() retorna saldo unificado + breakdown por provedor', function () {
    $this->service->grantMonthlyQuota(
        entityId: $this->entity->id,
        amount: 50,
        periodEndsAt: CarbonImmutable::now()->addMonth(),
    );
    $this->service->purchaseCredits(entityId: $this->entity->id, amount: 100);
    $this->service->reserve(entityId: $this->entity->id, amount: 30); // 30 da cota
    $this->service->consumeReservation(entityId: $this->entity->id, amount: 30, provider: AiProvider::OpenAI);

    $balance = $this->service->balance($this->entity->id);

    expect($balance['available'])->toBe(120);            // 20 cota restante + 100 balance
    expect($balance['quota_remaining'])->toBe(20);
    expect($balance['quota_total'])->toBe(50);
    expect($balance['quota_used'])->toBe(30);
    expect($balance['balance'])->toBe(100);
    expect($balance['consumed_by_provider']['openai'])->toBe(30);
    expect($balance['consumed_by_provider']['anthropic'])->toBe(0);
    expect($balance['consumed_by_provider']['gemini'])->toBe(0);
});
