<?php

use App\Domains\AI\Models\AiModelPrice;
use App\Enums\AI\AiProvider;
use Database\Seeders\AiModelPriceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('seeder popula preços para os 3 provedores e é idempotente', function () {
    $this->seed(AiModelPriceSeeder::class);

    $byProvider = AiModelPrice::query()
        ->selectRaw('provider, count(*) as total')
        ->groupBy('provider')
        ->pluck('total', 'provider')
        ->all();

    expect($byProvider[AiProvider::OpenAI->value] ?? 0)->toBeGreaterThanOrEqual(2);
    expect($byProvider[AiProvider::Anthropic->value] ?? 0)->toBeGreaterThanOrEqual(2);
    expect($byProvider[AiProvider::Gemini->value] ?? 0)->toBeGreaterThanOrEqual(2);

    $first = AiModelPrice::query()->count();

    // Reexecuta. Não deve duplicar (updateOrCreate por provider+model+effective_from).
    $this->seed(AiModelPriceSeeder::class);

    expect(AiModelPrice::query()->count())->toBe($first);
});

test('seeder marca todos os preços como ativos com effective_until null', function () {
    $this->seed(AiModelPriceSeeder::class);

    $active = AiModelPrice::query()->where('active', true)->whereNull('effective_until')->count();
    $total  = AiModelPrice::query()->count();

    expect($active)->toBe($total);
});
