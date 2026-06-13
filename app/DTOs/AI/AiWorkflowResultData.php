<?php

declare(strict_types=1);

namespace App\DTOs\AI;

use App\Enums\AI\AiRunMode;

readonly class AiWorkflowResultData
{
    /**
     * @param list<AiProviderResponseData> $providerCalls
     * @param list<string>                 $safetyNotes
     * @param array<string, mixed>         $metadata
     */
    public function __construct(
        public string $workflow,
        public AiRunMode $mode,
        public string $finalOutput,
        public array $providerCalls = [],
        public array $safetyNotes = [],
        public array $metadata = [],
    ) {
    }

    public function totalRawCostUsd(): float
    {
        $total = 0.0;

        foreach ($this->providerCalls as $call) {
            $total += (float) ($call->usage->rawCostUsd ?? 0.0);
        }

        return round($total, 8);
    }

    public function toArray(): array
    {
        return [
            'workflow'           => $this->workflow,
            'mode'               => $this->mode->value,
            'final_output'       => $this->finalOutput,
            'provider_calls'     => array_map(fn (AiProviderResponseData $c): array => $c->toArray(), $this->providerCalls),
            'safety_notes'       => $this->safetyNotes,
            'metadata'           => $this->metadata,
            'total_raw_cost_usd' => $this->totalRawCostUsd(),
        ];
    }
}
