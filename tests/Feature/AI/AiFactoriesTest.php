<?php

use App\Domains\AI\Models\AiCreditLedgerEntry;
use App\Domains\AI\Models\AiCreditWallet;
use App\Domains\AI\Models\AiModelPrice;
use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Models\AiRunProviderCall;
use App\Enums\AI\AiLedgerEntryType;
use App\Enums\AI\AiProvider;
use App\Enums\AI\AiProviderCallRole;
use App\Enums\AI\AiRunMode;
use App\Enums\AI\AiRunStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('AiCreditWalletFactory persiste carteira zerada', function () {
    $wallet = AiCreditWallet::factory()->create();

    expect($wallet->balance)->toBe(0);
    expect($wallet->reserved_balance)->toBe(0);
    expect($wallet->entity_id)->not->toBeNull();
});

test('AiCreditWalletFactory state withBalance ajusta saldo', function () {
    $wallet = AiCreditWallet::factory()->withBalance(123)->create();

    expect($wallet->balance)->toBe(123);
});

test('AiCreditLedgerEntryFactory cria entry vinculada a carteira', function () {
    $entry = AiCreditLedgerEntry::factory()->create();

    expect($entry->type)->toBe(AiLedgerEntryType::Grant);
    expect($entry->wallet_id)->not->toBeNull();
    expect($entry->entity_id)->not->toBeNull();
});

test('AiRunFactory cria run em modo validated/low/pending por default', function () {
    $run = AiRun::factory()->create();

    expect($run->mode)->toBe(AiRunMode::Validated);
    expect($run->status)->toBe(AiRunStatus::Pending);
});

test('AiRunFactory state approved seta approver e timestamp', function () {
    $run = AiRun::factory()->approved()->create();

    expect($run->status)->toBe(AiRunStatus::Approved);
    expect($run->approved_at)->not->toBeNull();
    expect($run->approved_by)->not->toBeNull();
});

test('AiRunProviderCallFactory cria call vinculada a run', function () {
    $call = AiRunProviderCall::factory()->create();

    expect($call->provider)->toBe(AiProvider::OpenAI);
    expect($call->role)->toBe(AiProviderCallRole::Generator);
    expect($call->ai_run_id)->not->toBeNull();
});

test('AiModelPriceFactory cria preço ativo para OpenAI por default', function () {
    $price = AiModelPrice::factory()->create();

    expect($price->provider)->toBe(AiProvider::OpenAI);
    expect($price->active)->toBeTrue();
    expect((float) $price->input_usd_per_million)->toBeGreaterThan(0);
});
