<?php

declare(strict_types=1);

namespace App\Domains\AI\Repositories;

use App\Domains\AI\Contracts\AiRunProviderCallStoreInterface;
use App\Domains\AI\Models\AiRunProviderCall;
use App\Domains\AI\Services\AiPricingService;
use App\DTOs\AI\AiProviderResponseData;
use App\Enums\AI\AiProviderCallRole;

class EloquentAiRunProviderCallStore implements AiRunProviderCallStoreInterface
{
    public function __construct(private readonly AiPricingService $pricingService)
    {
    }

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
            'ai_run_id'        => $aiRunId,
            'provider'         => $provider,
            'model'            => $response?->model ?? 'unknown',
            'role'             => $role->value,
            'status'           => $status,
            'input_tokens'     => $response?->usage->inputTokens,
            'output_tokens'    => $response?->usage->outputTokens,
            'reasoning_tokens' => $response?->usage->reasoningTokens,
            'tool_calls_count' => $response?->usage->toolCallsCount ?? 0,
            // Providers reais não reportam custo — resolve pela tabela de preços
            // (null somente se o modelo não tiver preço cadastrado).
            'raw_cost_usd' => $status === 'success' && $response !== null
                ? $this->pricingService->resolveCostUsdForResponse($response)
                : $response?->usage->rawCostUsd,
            'normalized_credits' => $normalizedCredits,
            'latency_ms'         => $response?->latencyMs,
            'request_hash'       => $response?->requestHash,
            'response_hash'      => $response?->responseHash,
            'metadata'           => $metadata,
            'error_message'      => $errorMessage,
        ]);
    }
}
