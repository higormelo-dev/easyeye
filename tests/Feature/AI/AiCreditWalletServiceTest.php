<?php

use App\Domains\AI\Exceptions\InsufficientAiCreditsException;
use App\Domains\AI\Models\AiCreditLedgerEntry;
use App\Domains\AI\Models\AiCreditWallet;
use App\Domains\AI\Services\AiCreditWalletService;
use App\Enums\AI\AiLedgerEntryType;
use App\Enums\SubscriptionStatus;
use App\Models\Entity;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => false]);
    $this->plan = Plan::factory()->create(['active' => true]);
    $this->subscription = Subscription::factory()->create([
        'entity_id' => $this->entity->id,
        'plan_id' => $this->plan->id,
        'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addMonth(),
    ]);

    $this->service = app(AiCreditWalletService::class);
});

test('grant monthly credits cria carteira e ledger', function () {
    $entry = $this->service->grantMonthlyCredits(
        entityId: $this->entity->id,
        amount: 120,
        subscriptionId: $this->subscription->id,
        description: 'Créditos do plano',
    );

    expect($entry->type)->toBe(AiLedgerEntryType::Grant);
    expect($entry->amount)->toBe(120);
    expect($entry->balance_after)->toBe(120);

    $wallet = AiCreditWallet::query()->where('entity_id', $this->entity->id)->first();

    expect($wallet)->not->toBeNull();
    expect($wallet->balance)->toBe(120);
    expect($wallet->reserved_balance)->toBe(0);
    expect($wallet->lifetime_purchased)->toBe(0);
    expect($wallet->lifetime_consumed)->toBe(0);
});

test('purchase credits incrementa saldo e lifetime_purchased', function () {
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
});

test('reserve bloqueia saldo e impede overspending', function () {
    $this->service->grantMonthlyCredits($this->entity->id, 100, $this->subscription->id);
    $reserve = $this->service->reserve($this->entity->id, 60, null, $this->subscription->id);

    expect($reserve->type)->toBe(AiLedgerEntryType::Reserve);
    expect($reserve->amount)->toBe(-60);
    expect($reserve->balance_after)->toBe(40);

    $wallet = AiCreditWallet::query()->where('entity_id', $this->entity->id)->firstOrFail();
    expect($wallet->balance)->toBe(40);
    expect($wallet->reserved_balance)->toBe(60);

    expect(fn () => $this->service->reserve($this->entity->id, 41, null, $this->subscription->id))
        ->toThrow(InsufficientAiCreditsException::class);

    expect(AiCreditLedgerEntry::query()->where('entity_id', $this->entity->id)->count())->toBe(2);
});

test('consume reservation baixa reservado e incrementa lifetime_consumed', function () {
    $this->service->grantMonthlyCredits($this->entity->id, 100, $this->subscription->id);
    $this->service->reserve($this->entity->id, 70, null, $this->subscription->id);
    $consume = $this->service->consumeReservation($this->entity->id, 65, null, $this->subscription->id);

    expect($consume->type)->toBe(AiLedgerEntryType::Consume);
    expect($consume->amount)->toBe(0);
    expect($consume->balance_after)->toBe(30);

    $wallet = AiCreditWallet::query()->where('entity_id', $this->entity->id)->firstOrFail();

    expect($wallet->balance)->toBe(30);
    expect($wallet->reserved_balance)->toBe(5);
    expect($wallet->lifetime_consumed)->toBe(65);
});

test('release reservation devolve saldo sem consumir créditos', function () {
    $this->service->grantMonthlyCredits($this->entity->id, 150, $this->subscription->id);
    $this->service->reserve($this->entity->id, 80, null, $this->subscription->id);
    $release = $this->service->releaseReservation($this->entity->id, 30, null, $this->subscription->id);

    expect($release->type)->toBe(AiLedgerEntryType::Release);
    expect($release->amount)->toBe(30);
    expect($release->balance_after)->toBe(100);

    $wallet = AiCreditWallet::query()->where('entity_id', $this->entity->id)->firstOrFail();

    expect($wallet->balance)->toBe(100);
    expect($wallet->reserved_balance)->toBe(50);
    expect($wallet->lifetime_consumed)->toBe(0);
});

test('idempotency key evita duplicidade de grant', function () {
    $key = 'ai-grant-key-001';

    $first = $this->service->grantMonthlyCredits(
        entityId: $this->entity->id,
        amount: 80,
        subscriptionId: $this->subscription->id,
        idempotencyKey: $key,
    );

    $second = $this->service->grantMonthlyCredits(
        entityId: $this->entity->id,
        amount: 80,
        subscriptionId: $this->subscription->id,
        idempotencyKey: $key,
    );

    expect($first->id)->toBe($second->id);

    $wallet = AiCreditWallet::query()->where('entity_id', $this->entity->id)->firstOrFail();
    expect($wallet->balance)->toBe(80);

    expect(AiCreditLedgerEntry::query()->where('entity_id', $this->entity->id)->count())->toBe(1);
});

test('idempotency key em tipos diferentes gera conflito', function () {
    $key = 'ai-conflict-key-001';

    $this->service->grantMonthlyCredits(
        entityId: $this->entity->id,
        amount: 50,
        subscriptionId: $this->subscription->id,
        idempotencyKey: $key,
    );

    expect(function () use ($key) {
        $this->service->purchaseCredits(
            entityId: $this->entity->id,
            amount: 50,
            subscriptionId: $this->subscription->id,
            idempotencyKey: $key,
        );
    })->toThrow(\RuntimeException::class);
});

test('refund estorna créditos sem alterar lifetime_consumed', function () {
    $this->service->grantMonthlyCredits($this->entity->id, 200, $this->subscription->id);
    $this->service->reserve($this->entity->id, 90, null, $this->subscription->id);
    $this->service->consumeReservation($this->entity->id, 90, null, $this->subscription->id);

    $wallet = AiCreditWallet::query()->where('entity_id', $this->entity->id)->firstOrFail();
    expect($wallet->balance)->toBe(110);
    expect($wallet->lifetime_consumed)->toBe(90);

    $refund = $this->service->refund(
        entityId: $this->entity->id,
        amount: 90,
        subscriptionId: $this->subscription->id,
        description: 'Estorno por falha no provider.',
    );

    expect($refund->type)->toBe(AiLedgerEntryType::Refund);
    expect($refund->amount)->toBe(90);
    expect($refund->balance_after)->toBe(200);

    $wallet = $wallet->fresh();
    expect($wallet->balance)->toBe(200);
    expect($wallet->reserved_balance)->toBe(0);
    expect($wallet->lifetime_consumed)->toBe(90); // refund NÃO mexe no histórico de consumo
    expect($wallet->lifetime_purchased)->toBe(0);
});

test('refund é idempotente com mesma idempotencyKey', function () {
    $this->service->grantMonthlyCredits($this->entity->id, 100, $this->subscription->id);

    $key = 'refund-key-001';

    $first  = $this->service->refund($this->entity->id, 40, null, $this->subscription->id, null, $key);
    $second = $this->service->refund($this->entity->id, 40, null, $this->subscription->id, null, $key);

    expect($first->id)->toBe($second->id);

    $wallet = AiCreditWallet::query()->where('entity_id', $this->entity->id)->firstOrFail();
    expect($wallet->balance)->toBe(140); // 100 grant + 40 refund (uma única vez)
});

test('consumeReservation lança exceção quando excede reservado', function () {
    $this->service->grantMonthlyCredits($this->entity->id, 100, $this->subscription->id);
    $this->service->reserve($this->entity->id, 40, null, $this->subscription->id);

    expect(fn () => $this->service->consumeReservation($this->entity->id, 50, null, $this->subscription->id))
        ->toThrow(\InvalidArgumentException::class);

    $wallet = AiCreditWallet::query()->where('entity_id', $this->entity->id)->firstOrFail();
    expect($wallet->balance)->toBe(60);
    expect($wallet->reserved_balance)->toBe(40);
    expect($wallet->lifetime_consumed)->toBe(0);
});

test('releaseReservation lança exceção quando excede reservado', function () {
    $this->service->grantMonthlyCredits($this->entity->id, 100, $this->subscription->id);
    $this->service->reserve($this->entity->id, 30, null, $this->subscription->id);

    expect(fn () => $this->service->releaseReservation($this->entity->id, 31, null, $this->subscription->id))
        ->toThrow(\InvalidArgumentException::class);

    $wallet = AiCreditWallet::query()->where('entity_id', $this->entity->id)->firstOrFail();
    expect($wallet->balance)->toBe(70);
    expect($wallet->reserved_balance)->toBe(30);
});
