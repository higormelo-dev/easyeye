<?php

declare(strict_types=1);

use App\Domains\AI\Providers\Fakes\AnthropicFakeProvider;
use App\Domains\AI\Providers\Fakes\GeminiFakeProvider;
use App\Domains\AI\Providers\Fakes\OpenAiFakeProvider;
use App\Domains\AI\Services\AiProviderManager;
use App\Enums\AI\AiProvider;
use App\Enums\AI\AiProviderCallRole;
use App\Enums\AI\AiRunMode;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

function makeManagerWithFakes(): AiProviderManager
{
    return new AiProviderManager([
        'openai'    => new OpenAiFakeProvider(),
        'anthropic' => new AnthropicFakeProvider(),
        'gemini'    => new GeminiFakeProvider(),
    ]);
}

test('get retorna o provider quando registrado', function () {
    $manager = makeManagerWithFakes();

    $openai = $manager->get(AiProvider::OpenAI);

    expect($openai->provider())->toBe(AiProvider::OpenAI);
});

test('get lança exception quando provider não está registrado', function () {
    $manager = new AiProviderManager([
        'openai' => new OpenAiFakeProvider(),
    ]);

    expect(fn () => $manager->get('anthropic'))
        ->toThrow(\RuntimeException::class, 'Provider IA [anthropic] não está registrado.');
});

test('providersForMode economy retorna apenas Generator', function () {
    $manager = makeManagerWithFakes();

    $steps = $manager->providersForMode(AiRunMode::Economy);

    expect($steps)->toHaveCount(1);
    expect($steps[0]['role'])->toBe(AiProviderCallRole::Generator);
});

test('providersForMode validated retorna Generator + Reviewer', function () {
    $manager = makeManagerWithFakes();

    $steps = $manager->providersForMode(AiRunMode::Validated);

    expect($steps)->toHaveCount(2);
    expect($steps[0]['role'])->toBe(AiProviderCallRole::Generator);
    expect($steps[1]['role'])->toBe(AiProviderCallRole::Reviewer);
});

test('providersForMode consensus retorna Generator + Reviewer + Adjudicator', function () {
    $manager = makeManagerWithFakes();

    $steps = $manager->providersForMode(AiRunMode::Consensus);

    expect($steps)->toHaveCount(3);
    expect($steps[2]['role'])->toBe(AiProviderCallRole::Adjudicator);
});

test('providersForMode consensus lança exception quando desabilitado por config', function () {
    config()->set('ai.enable_consensus', false);

    $manager = makeManagerWithFakes();

    expect(fn () => $manager->providersForMode(AiRunMode::Consensus))
        ->toThrow(\RuntimeException::class, 'Modo consensus está desabilitado por configuração.');
});

test('providersForMode usa providers configurados em ai.providers', function () {
    config()->set('ai.providers.primary', 'gemini');
    config()->set('ai.providers.reviewer', 'openai');

    $manager = makeManagerWithFakes();

    $steps = $manager->providersForMode(AiRunMode::Validated);

    expect($steps[0]['provider']->provider())->toBe(AiProvider::Gemini);
    expect($steps[1]['provider']->provider())->toBe(AiProvider::OpenAI);
});
