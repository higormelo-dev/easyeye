<?php

use App\Domains\AI\Providers\Fakes\AnthropicFakeProvider;
use App\Domains\AI\Providers\Fakes\GeminiFakeProvider;
use App\Domains\AI\Providers\Fakes\OpenAiFakeProvider;
use App\DTOs\AI\AiRequestData;
use App\Enums\AI\AiProvider;
use App\Enums\AI\AiRiskLevel;
use App\Enums\AI\AiRunMode;

test('openai fake provider retorna provider, tokens e custo', function () {
    $provider = new OpenAiFakeProvider();

    $response = $provider->generate(new AiRequestData(
        workflow: 'report_drafting',
        mode: AiRunMode::Validated,
        userPrompt: 'Gerar rascunho de laudo para revisão médica.',
        systemPrompt: 'Você é um assistente de apoio clínico.',
        riskLevel: AiRiskLevel::Medium,
        context: ['age' => 45, 'complaint' => 'visão borrada'],
        expectsJson: true,
        metadata: ['tool_calls_count' => 1],
    ));

    expect($provider->provider())->toBe(AiProvider::OpenAI);
    expect($provider->supportsVision())->toBeTrue();
    expect($provider->supportsJsonMode())->toBeTrue();

    expect($response->provider)->toBe(AiProvider::OpenAI);
    expect($response->usage->inputTokens)->toBeGreaterThan(0);
    expect($response->usage->outputTokens)->toBeGreaterThan(0);
    expect($response->usage->rawCostUsd)->toBeGreaterThan(0);
    expect($response->requestHash)->not->toBeNull();
    expect($response->responseHash)->not->toBeNull();
});

test('anthropic e gemini fake providers retornam dados coerentes', function () {
    $request = new AiRequestData(
        workflow: 'exam_assistant',
        mode: AiRunMode::Economy,
        userPrompt: 'Resuma os achados principais do exame.',
        riskLevel: AiRiskLevel::Low,
    );

    $anthropic = (new AnthropicFakeProvider())->generate($request);
    $gemini = (new GeminiFakeProvider())->generate($request);

    expect($anthropic->provider)->toBe(AiProvider::Anthropic);
    expect($gemini->provider)->toBe(AiProvider::Gemini);
    expect($anthropic->usage->rawCostUsd)->toBeGreaterThan(0);
    expect($gemini->usage->rawCostUsd)->toBeGreaterThan(0);
    expect($anthropic->content)->toContain('FAKE');
    expect($gemini->content)->toContain('FAKE');
});
