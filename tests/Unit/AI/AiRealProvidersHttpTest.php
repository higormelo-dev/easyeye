<?php

declare(strict_types=1);

use App\Domains\AI\Providers\AnthropicProvider;
use App\Domains\AI\Providers\GeminiProvider;
use App\Domains\AI\Providers\OpenAiProvider;
use App\DTOs\AI\AiRequestData;
use App\Enums\AI\AiProvider;
use App\Enums\AI\AiRiskLevel;
use App\Enums\AI\AiRunMode;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

test('openai provider real faz parse de conteúdo e uso', function () {
    config()->set('services.openai.api_key', 'openai-key-test');
    config()->set('ai.providers.openai.model', 'gpt-5-mini');
    config()->set('ai.providers.openai.base_url', 'https://api.openai.com/v1');

    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response([
            'id' => 'resp_123',
            'status' => 'completed',
            'model' => 'gpt-5-mini',
            'usage' => [
                'input_tokens' => 120,
                'output_tokens' => 90,
                'output_tokens_details' => ['reasoning_tokens' => 25],
            ],
            'output' => [
                [
                    'type' => 'message',
                    'content' => [
                        ['type' => 'output_text', 'text' => 'Rascunho de apoio clínico.'],
                    ],
                ],
            ],
        ], 200),
    ]);

    $provider = new OpenAiProvider();

    $result = $provider->generate(new AiRequestData(
        workflow: 'report_drafting',
        mode: AiRunMode::Validated,
        userPrompt: 'Gerar rascunho de laudo.',
        systemPrompt: 'Apoio clínico ao médico.',
        riskLevel: AiRiskLevel::Medium,
        context: ['specialty' => 'ophthalmology'],
        expectsJson: true,
        maxOutputTokens: 500,
    ));

    expect($result->provider)->toBe(AiProvider::OpenAI);
    expect($result->model)->toBe('gpt-5-mini');
    expect($result->content)->toContain('Rascunho de apoio clínico');
    expect($result->usage->inputTokens)->toBe(120);
    expect($result->usage->outputTokens)->toBe(90);
    expect($result->usage->reasoningTokens)->toBe(25);
    expect($result->requestHash)->not->toBeNull();
    expect($result->responseHash)->not->toBeNull();

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://api.openai.com/v1/responses'
            && $request->hasHeader('Authorization', 'Bearer openai-key-test')
            && data_get($request->data(), 'model') === 'gpt-5-mini'
            && data_get($request->data(), 'text.format.type') === 'json_object';
    });
});

test('anthropic provider real faz parse de conteúdo e uso', function () {
    config()->set('services.anthropic.api_key', 'anthropic-key-test');
    config()->set('services.anthropic.version', '2023-06-01');
    config()->set('ai.providers.anthropic.model', 'claude-sonnet-4-5');
    config()->set('ai.providers.anthropic.base_url', 'https://api.anthropic.com');

    Http::fake([
        'https://api.anthropic.com/v1/messages' => Http::response([
            'id' => 'msg_123',
            'type' => 'message',
            'model' => 'claude-sonnet-4-5',
            'content' => [
                ['type' => 'text', 'text' => 'Sugestão revisada para o médico.'],
            ],
            'stop_reason' => 'end_turn',
            'usage' => [
                'input_tokens' => 80,
                'output_tokens' => 60,
            ],
        ], 200),
    ]);

    $provider = new AnthropicProvider();

    $result = $provider->generate(new AiRequestData(
        workflow: 'exam_assistant',
        mode: AiRunMode::Validated,
        userPrompt: 'Revisar hipótese diagnóstica.',
        systemPrompt: 'Apenas apoio técnico ao médico.',
        riskLevel: AiRiskLevel::Medium,
        context: ['exam' => 'retinografia'],
    ));

    expect($result->provider)->toBe(AiProvider::Anthropic);
    expect($result->model)->toBe('claude-sonnet-4-5');
    expect($result->content)->toContain('Sugestão revisada');
    expect($result->usage->inputTokens)->toBe(80);
    expect($result->usage->outputTokens)->toBe(60);
    expect($result->finishReason)->toBe('end_turn');

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://api.anthropic.com/v1/messages'
            && $request->hasHeader('x-api-key', 'anthropic-key-test')
            && $request->hasHeader('anthropic-version', '2023-06-01')
            && data_get($request->data(), 'model') === 'claude-sonnet-4-5';
    });
});

test('gemini provider real faz parse de conteúdo e uso', function () {
    config()->set('services.gemini.api_key', 'gemini-key-test');
    config()->set('ai.providers.gemini.model', 'gemini-2.0-flash');
    config()->set('ai.providers.gemini.base_url', 'https://generativelanguage.googleapis.com');

    Http::fake([
        'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent' => Http::response([
            'responseId' => 'gem_123',
            'modelVersion' => 'gemini-2.0-flash',
            'candidates' => [
                [
                    'finishReason' => 'STOP',
                    'content' => [
                        'parts' => [
                            ['text' => 'Consolidação final segura para revisão médica.'],
                        ],
                    ],
                ],
            ],
            'usageMetadata' => [
                'promptTokenCount' => 70,
                'candidatesTokenCount' => 55,
                'thoughtsTokenCount' => 12,
            ],
        ], 200),
    ]);

    $provider = new GeminiProvider();

    $result = $provider->generate(new AiRequestData(
        workflow: 'consensus_review',
        mode: AiRunMode::Consensus,
        userPrompt: 'Consolidar duas respostas de apoio clínico.',
        riskLevel: AiRiskLevel::High,
        expectsJson: true,
        context: ['risk' => 'high'],
    ));

    expect($result->provider)->toBe(AiProvider::Gemini);
    expect($result->model)->toBe('gemini-2.0-flash');
    expect($result->content)->toContain('Consolidação final segura');
    expect($result->usage->inputTokens)->toBe(70);
    expect($result->usage->outputTokens)->toBe(55);
    expect($result->usage->reasoningTokens)->toBe(12);
    expect($result->finishReason)->toBe('STOP');

    Http::assertSent(function (Request $request): bool {
        // A chave deve viajar via header x-goog-api-key, NUNCA na query string.
        return str_contains($request->url(), '/v1beta/models/gemini-2.0-flash:generateContent')
            && ! str_contains($request->url(), 'key=')
            && $request->hasHeader('x-goog-api-key', 'gemini-key-test')
            && data_get($request->data(), 'generationConfig.response_mime_type') === 'application/json';
    });
});

test('openai provider real lança exceção sanitizada em erro HTTP', function () {
    config()->set('services.openai.api_key', 'openai-key-test');
    config()->set('ai.providers.openai.model', 'gpt-5-mini');
    config()->set('ai.providers.openai.base_url', 'https://api.openai.com/v1');

    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response([
            'error' => [
                'code' => 'invalid_api_key',
                'message' => 'API key inválida.',
            ],
        ], 401),
    ]);

    $provider = new OpenAiProvider();

    expect(fn () => $provider->generate(new AiRequestData(
        workflow: 'report_drafting',
        mode: AiRunMode::Economy,
        userPrompt: 'Conteúdo sensível de prontuário não deve vazar em erro.',
    )))->toThrow(\RuntimeException::class, 'OpenAI request failed [401/invalid_api_key]: API key inválida.');
});
