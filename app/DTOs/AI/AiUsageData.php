<?php

declare(strict_types=1);

namespace App\DTOs\AI;

readonly class AiUsageData
{
    public function __construct(
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $reasoningTokens = 0,
        public int $toolCallsCount = 0,
        public ?float $rawCostUsd = null,
    ) {
    }

    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens + $this->reasoningTokens;
    }

    public function toArray(): array
    {
        return [
            'input_tokens'     => $this->inputTokens,
            'output_tokens'    => $this->outputTokens,
            'reasoning_tokens' => $this->reasoningTokens,
            'tool_calls_count' => $this->toolCallsCount,
            'raw_cost_usd'     => $this->rawCostUsd,
            'total_tokens'     => $this->totalTokens(),
        ];
    }
}
