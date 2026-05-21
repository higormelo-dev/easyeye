<?php

use App\Domains\AI\Models\AiModelPrice;
use App\Domains\AI\Repositories\EloquentAiModelPriceRepository;
use App\Enums\AI\AiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('findActive ignora preço com effective_until vencido', function () {
    AiModelPrice::factory()->create([
        'provider'        => AiProvider::OpenAI->value,
        'model'           => 'gpt-archived',
        'effective_from'  => now()->subYear(),
        'effective_until' => now()->subDay(),
        'active'          => true,
    ]);

    $repo = new EloquentAiModelPriceRepository();
    $price = $repo->findActive(AiProvider::OpenAI, 'gpt-archived');

    expect($price)->toBeNull();
});

test('findActive ignora preço com active=false mesmo na janela', function () {
    AiModelPrice::factory()->create([
        'provider'        => AiProvider::Anthropic->value,
        'model'           => 'claude-disabled',
        'effective_from'  => now()->subMonth(),
        'effective_until' => null,
        'active'          => false,
    ]);

    $repo = new EloquentAiModelPriceRepository();
    $price = $repo->findActive(AiProvider::Anthropic, 'claude-disabled');

    expect($price)->toBeNull();
});

test('findActive retorna o preço mais recente quando há histórico', function () {
    // Antigo: vigência fechada
    AiModelPrice::factory()->create([
        'provider'              => AiProvider::Gemini->value,
        'model'                 => 'gemini-pro',
        'input_usd_per_million' => 0.50,
        'output_usd_per_million' => 1.50,
        'effective_from'        => now()->subYear(),
        'effective_until'       => now()->subMonth(),
        'active'                => true,
    ]);

    // Atual: vigência aberta com preço novo
    AiModelPrice::factory()->create([
        'provider'              => AiProvider::Gemini->value,
        'model'                 => 'gemini-pro',
        'input_usd_per_million' => 1.25,
        'output_usd_per_million' => 5.00,
        'effective_from'        => now()->subDay(),
        'effective_until'       => null,
        'active'                => true,
    ]);

    $repo  = new EloquentAiModelPriceRepository();
    $price = $repo->findActive(AiProvider::Gemini, 'gemini-pro');

    expect($price)->not->toBeNull();
    expect((float) $price->input_usd_per_million)->toBe(1.25);
});

test('findActive ignora preço cujo effective_from ainda não chegou', function () {
    AiModelPrice::factory()->create([
        'provider'        => AiProvider::OpenAI->value,
        'model'           => 'gpt-future',
        'effective_from'  => now()->addWeek(),
        'effective_until' => null,
        'active'          => true,
    ]);

    $repo  = new EloquentAiModelPriceRepository();
    $price = $repo->findActive(AiProvider::OpenAI, 'gpt-future');

    expect($price)->toBeNull();
});
