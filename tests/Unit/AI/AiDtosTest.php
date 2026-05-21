<?php

use App\DTOs\AI\AiProviderResponseData;
use App\DTOs\AI\AiRequestData;
use App\DTOs\AI\AiUsageData;
use App\DTOs\AI\AiWorkflowResultData;
use App\Enums\AI\AiProvider;
use App\Enums\AI\AiRunMode;

test('AiRequestData::fullPrompt concatena system + user com duas quebras', function () {
    $req = new AiRequestData(
        workflow: 'report_drafting',
        mode: AiRunMode::Validated,
        userPrompt: 'Liste achados.',
        systemPrompt: 'Você é um assistente clínico.',
    );

    expect($req->fullPrompt())->toBe("Você é um assistente clínico.\n\nListe achados.");
});

test('AiRequestData::fullPrompt funciona sem systemPrompt', function () {
    $req = new AiRequestData(
        workflow: 'exam_assistant',
        mode: AiRunMode::Economy,
        userPrompt: 'Resumir.',
    );

    expect($req->fullPrompt())->toBe('Resumir.');
});

test('AiRequestData::toArray expõe chaves snake_case esperadas pela UI', function () {
    $req = new AiRequestData(
        workflow: 'report_drafting',
        mode: AiRunMode::Validated,
        userPrompt: 'x',
        expectsJson: true,
        maxOutputTokens: 1024,
    );

    $arr = $req->toArray();

    expect($arr['workflow'])->toBe('report_drafting');
    expect($arr['mode'])->toBe('validated');
    expect($arr['user_prompt'])->toBe('x');
    expect($arr['expects_json'])->toBeTrue();
    expect($arr['max_output_tokens'])->toBe(1024);
});

test('AiUsageData::totalTokens soma input + output + reasoning', function () {
    $usage = new AiUsageData(
        inputTokens: 100,
        outputTokens: 50,
        reasoningTokens: 25,
        toolCallsCount: 3,
    );

    expect($usage->totalTokens())->toBe(175);
});

test('AiUsageData::toArray inclui total_tokens calculado', function () {
    $usage = new AiUsageData(inputTokens: 200, outputTokens: 100);

    $arr = $usage->toArray();

    expect($arr['total_tokens'])->toBe(300);
    expect($arr['raw_cost_usd'])->toBeNull();
});

test('AiWorkflowResultData::totalRawCostUsd soma custos de várias chamadas', function () {
    $call1 = new AiProviderResponseData(
        provider: AiProvider::OpenAI,
        model: 'gpt-fake-5',
        content: 'a',
        usage: new AiUsageData(rawCostUsd: 0.12),
        latencyMs: 50,
    );
    $call2 = new AiProviderResponseData(
        provider: AiProvider::Anthropic,
        model: 'claude-fake-sonnet',
        content: 'b',
        usage: new AiUsageData(rawCostUsd: 0.08),
        latencyMs: 50,
    );
    $call3 = new AiProviderResponseData(
        provider: AiProvider::Gemini,
        model: 'gemini-fake-pro',
        content: 'c',
        usage: new AiUsageData(rawCostUsd: null), // ignorado como 0
        latencyMs: 50,
    );

    $result = new AiWorkflowResultData(
        workflow: 'consensus_review',
        mode: AiRunMode::Consensus,
        finalOutput: 'final',
        providerCalls: [$call1, $call2, $call3],
    );

    expect($result->totalRawCostUsd())->toBe(0.20);
});

test('AiWorkflowResultData::toArray serializa providerCalls', function () {
    $result = new AiWorkflowResultData(
        workflow: 'exam_assistant',
        mode: AiRunMode::Economy,
        finalOutput: 'OK',
        providerCalls: [
            new AiProviderResponseData(
                provider: AiProvider::OpenAI,
                model: 'gpt-fake-5',
                content: 'a',
                usage: new AiUsageData(inputTokens: 10, outputTokens: 5, rawCostUsd: 0.01),
                latencyMs: 50,
            ),
        ],
        safetyNotes: ['nota 1'],
    );

    $arr = $result->toArray();

    expect($arr['workflow'])->toBe('exam_assistant');
    expect($arr['mode'])->toBe('economy');
    expect($arr['provider_calls'])->toHaveCount(1);
    expect($arr['provider_calls'][0]['provider'])->toBe('openai');
    expect($arr['safety_notes'])->toBe(['nota 1']);
    expect($arr['total_raw_cost_usd'])->toBe(0.01);
});
