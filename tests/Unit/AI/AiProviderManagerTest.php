<?php

declare(strict_types=1);

use App\Domains\AI\Providers\Fakes\{AnthropicFakeProvider, GeminiFakeProvider, OpenAiFakeProvider};
use App\Domains\AI\Services\{AiProviderManager, AiProviderSettings};
use App\Enums\AI\{AiProvider, AiProviderCallRole, AiRunMode};
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

/**
 * Define os provedores habilitados (ordem = prioridade) via cache do setting,
 * evitando acesso ao banco em teste unitário.
 *
 * @param list<string> $codes
 */
function setEnabledProviders(array $codes): void
{
    Cache::put('subscription_setting:' . AiProviderSettings::SETTING_KEY, json_encode($codes), 600);
}

function makeManagerWithFakes(): AiProviderManager
{
    return new AiProviderManager([
        'openai'    => new OpenAiFakeProvider(),
        'anthropic' => new AnthropicFakeProvider(),
        'gemini'    => new GeminiFakeProvider(),
    ], new AiProviderSettings());
}

beforeEach(function () {
    config()->set('ai.enable_consensus', true);
    setEnabledProviders(['openai', 'anthropic', 'gemini']); // 3 provedores por padrão
});

test('get retorna o provider quando registrado', function () {
    expect(makeManagerWithFakes()->get(AiProvider::OpenAI)->provider())->toBe(AiProvider::OpenAI);
});

test('get lança exception quando provider não está registrado', function () {
    $manager = new AiProviderManager(['openai' => new OpenAiFakeProvider()], new AiProviderSettings());

    expect(fn () => $manager->get('anthropic'))
        ->toThrow(RuntimeException::class, 'Provider IA [anthropic] não está registrado.');
});

test('providersForMode economy retorna apenas Generator', function () {
    // Economia exige só 1 provedor — resolve mesmo com vários habilitados.
    $steps = makeManagerWithFakes()->providersForMode(AiRunMode::Economy);

    expect($steps)->toHaveCount(1)
        ->and($steps[0]['role'])->toBe(AiProviderCallRole::Generator);
});

test('providersForMode validated retorna Generator + Reviewer', function () {
    $steps = makeManagerWithFakes()->providersForMode(AiRunMode::Validated);

    expect($steps)->toHaveCount(2)
        ->and($steps[0]['role'])->toBe(AiProviderCallRole::Generator)
        ->and($steps[1]['role'])->toBe(AiProviderCallRole::Reviewer);
});

test('providersForMode consensus retorna Generator + Reviewer + Adjudicator', function () {
    $steps = makeManagerWithFakes()->providersForMode(AiRunMode::Consensus);

    expect($steps)->toHaveCount(3)
        ->and($steps[2]['role'])->toBe(AiProviderCallRole::Adjudicator);
});

test('providersForMode consensus exige 3 provedores habilitados', function () {
    setEnabledProviders(['gemini', 'openai']); // só 2 ativos

    expect(fn () => makeManagerWithFakes()->providersForMode(AiRunMode::Consensus))
        ->toThrow(RuntimeException::class);
});

test('a ordem de prioridade habilitada define os papéis', function () {
    setEnabledProviders(['gemini', 'openai', 'anthropic']);

    $steps = makeManagerWithFakes()->providersForMode(AiRunMode::Validated);

    expect($steps[0]['provider']->provider())->toBe(AiProvider::Gemini)
        ->and($steps[1]['provider']->provider())->toBe(AiProvider::OpenAI);
});

test('com 1 provedor habilitado só Economia é resolvível', function () {
    setEnabledProviders(['gemini']);

    $manager = makeManagerWithFakes();

    $steps = $manager->providersForMode(AiRunMode::Economy);
    expect($steps)->toHaveCount(1)
        ->and($steps[0]['provider']->provider())->toBe(AiProvider::Gemini);

    expect(fn () => $manager->providersForMode(AiRunMode::Validated))
        ->toThrow(RuntimeException::class);
});

test('fallbackChainForRole restringe-se aos provedores habilitados', function () {
    setEnabledProviders(['gemini']);

    $chain = makeManagerWithFakes()->fallbackChainForRole(AiProviderCallRole::Generator);

    expect($chain)->toHaveCount(1)
        ->and($chain[0]->provider())->toBe(AiProvider::Gemini);
});
