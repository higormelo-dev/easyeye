<?php

declare(strict_types=1);

namespace App\Domains\AI\Providers\Fakes;

use App\Domains\AI\Contracts\AiProviderInterface;
use App\DTOs\AI\{AiProviderResponseData, AiRequestData, AiUsageData};
use App\Enums\AI\AiRunMode;

abstract class AbstractFakeAiProvider implements AiProviderInterface
{
    public function __construct(
        protected readonly string $model,
        protected readonly float $inputUsdPerMillion,
        protected readonly float $outputUsdPerMillion,
        protected readonly float $reasoningUsdPerMillion = 0.0,
        protected readonly float $toolCallUsd = 0.0,
        protected readonly int $latencyMs = 120,
    ) {
    }

    public function supportsVision(): bool
    {
        return true;
    }

    public function supportsJsonMode(): bool
    {
        return true;
    }

    public function generate(AiRequestData $request): AiProviderResponseData
    {
        $inputTokens = $this->estimateTokens(
            $request->fullPrompt() . ' ' . json_encode($request->context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        $maxOutputTokens = $request->maxOutputTokens ?? 320;
        $outputTokens    = max(40, min($maxOutputTokens, intdiv($inputTokens, 2) + 64));
        $reasoningTokens = $this->estimateReasoningTokens($request->mode);
        $toolCallsCount  = (int) ($request->metadata['tool_calls_count'] ?? 0);

        $rawCostUsd = round(
            (($inputTokens / 1_000_000) * $this->inputUsdPerMillion)
            + (($outputTokens / 1_000_000) * $this->outputUsdPerMillion)
            + (($reasoningTokens / 1_000_000) * $this->reasoningUsdPerMillion)
            + ($toolCallsCount * $this->toolCallUsd),
            8,
        );

        $content = $this->buildFakeContent($request);

        return new AiProviderResponseData(
            provider: $this->provider(),
            model: $this->model,
            content: $content,
            usage: new AiUsageData(
                inputTokens: $inputTokens,
                outputTokens: $outputTokens,
                reasoningTokens: $reasoningTokens,
                toolCallsCount: $toolCallsCount,
                rawCostUsd: $rawCostUsd,
            ),
            latencyMs: $this->latencyMs,
            requestHash: hash('sha256', $request->fullPrompt()),
            responseHash: hash('sha256', $content),
            rawResponse: [
                'fake'     => true,
                'provider' => $this->provider()->value,
                'model'    => $this->model,
            ],
            finishReason: 'stop',
        );
    }

    protected function estimateTokens(string $text): int
    {
        $chars = mb_strlen($text);

        return max(8, (int) ceil($chars / 4));
    }

    protected function estimateReasoningTokens(AiRunMode $mode): int
    {
        return match ($mode) {
            AiRunMode::Economy   => 16,
            AiRunMode::Validated => 40,
            AiRunMode::Consensus => 80,
        };
    }

    protected function buildFakeContent(AiRequestData $request): string
    {
        $provider = strtoupper($this->provider()->value);

        return "[{$provider} FAKE] Sugestão de apoio para workflow {$request->workflow}: "
            . 'revisar informações clínicas antes de aprovação médica final.';
    }
}
