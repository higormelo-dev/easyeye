<?php

use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Models\AiRunProviderCall;
use App\Domains\AI\Repositories\EloquentAiRunProviderCallStore;
use App\DTOs\AI\AiProviderResponseData;
use App\DTOs\AI\AiUsageData;
use App\Enums\AI\AiProvider;
use App\Enums\AI\AiProviderCallRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('store persiste call com response completa (success path)', function () {
    $run = AiRun::factory()->create();

    $response = new AiProviderResponseData(
        provider: AiProvider::OpenAI,
        model: 'gpt-fake-5',
        content: 'resposta',
        usage: new AiUsageData(
            inputTokens: 100,
            outputTokens: 50,
            reasoningTokens: 10,
            toolCallsCount: 2,
            rawCostUsd: 0.0123,
        ),
        latencyMs: 250,
        requestHash: hash('sha256', 'req'),
        responseHash: hash('sha256', 'res'),
    );

    (new EloquentAiRunProviderCallStore())->store(
        aiRunId: (string) $run->id,
        role: AiProviderCallRole::Generator,
        status: 'success',
        response: $response,
        metadata: ['workflow' => 'report_drafting', 'provider' => 'openai'],
        normalizedCredits: 5,
    );

    $call = AiRunProviderCall::query()->where('ai_run_id', $run->id)->firstOrFail();

    expect($call->provider)->toBe(AiProvider::OpenAI);
    expect($call->model)->toBe('gpt-fake-5');
    expect($call->role)->toBe(AiProviderCallRole::Generator);
    expect($call->status)->toBe('success');
    expect($call->input_tokens)->toBe(100);
    expect($call->output_tokens)->toBe(50);
    expect($call->reasoning_tokens)->toBe(10);
    expect($call->tool_calls_count)->toBe(2);
    expect((float) $call->raw_cost_usd)->toBe(0.0123);
    expect($call->normalized_credits)->toBe(5);
    expect($call->latency_ms)->toBe(250);
    expect($call->request_hash)->not->toBeNull();
    expect($call->response_hash)->not->toBeNull();
    expect($call->error_message)->toBeNull();
});

test('store persiste call com response=null (provider falhou antes de responder)', function () {
    $run = AiRun::factory()->create();

    (new EloquentAiRunProviderCallStore())->store(
        aiRunId: (string) $run->id,
        role: AiProviderCallRole::Reviewer,
        status: 'failed',
        response: null,
        errorMessage: 'Timeout na chamada ao provider Anthropic.',
        metadata: ['workflow' => 'report_drafting', 'provider' => 'anthropic'],
        normalizedCredits: null,
    );

    $call = AiRunProviderCall::query()->where('ai_run_id', $run->id)->firstOrFail();

    expect($call->provider)->toBe(AiProvider::Anthropic);
    expect($call->model)->toBe('unknown');
    expect($call->role)->toBe(AiProviderCallRole::Reviewer);
    expect($call->status)->toBe('failed');
    expect($call->input_tokens)->toBeNull();
    expect($call->output_tokens)->toBeNull();
    expect($call->tool_calls_count)->toBe(0); // default DB
    expect($call->normalized_credits)->toBeNull();
    expect($call->error_message)->toBe('Timeout na chamada ao provider Anthropic.');
});

