<?php

declare(strict_types=1);

namespace App\Domains\AI\Repositories;

use App\Domains\AI\Contracts\AiRunProviderCallStoreInterface;
use App\Domains\AI\Models\AiRunProviderCall;
use App\DTOs\AI\AiProviderResponseData;
use App\Enums\AI\AiProviderCallRole;

class EloquentAiRunProviderCallStore implements AiRunProviderCallStoreInterface
{
    public function store(
        string $aiRunId,
        AiProviderCallRole $role,
        string $status,
        ?AiProviderResponseData $response = null,
        ?string $errorMessage = null,
        array $metadata = [],
        ?int $normalizedCredits = null,
    ): void {
        $provider = $response?->provider?->value ?? (string) ($metadata['provider'] ?? 'unknown');

        AiRunProviderCall::query()->create([
            'ai_run_id'          => $aiRunId,
            'provider'           => $provider,
            'model'              => $response?->model ?? 'unknown',
            'role'               => $role->value,
            'status'             => $status,
            'input_tokens'       => $response?->usage->inputTokens,
            'output_tokens'      => $response?->usage->outputTokens,
            'reasoning_tokens'   => $response?->usage->reasoningTokens,
            'tool_calls_count'   => $response?->usage->toolCallsCount ?? 0,
            'raw_cost_usd'       => $response?->usage->rawCostUsd,
            'normalized_credits' => $normalizedCredits,
            'latency_ms'         => $response?->latencyMs,
            'request_hash'       => $response?->requestHash,
            'response_hash'      => $response?->responseHash,
            'metadata'           => $metadata,
            'error_message'      => $errorMessage,
        ]);
    }
}
