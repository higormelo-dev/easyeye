<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\AI\Contracts\AiModelPriceRepositoryInterface;
use App\Domains\AI\Exceptions\AiModelPriceNotFoundException;
use App\Domains\AI\Models\AiModelPrice;
use App\DTOs\AI\{AiCreditEstimateData, AiProviderResponseData};
use App\Enums\AI\{AiProvider, AiRunMode};

class AiPricingService
{
    public function __construct(
        private readonly AiModelPriceRepositoryInterface $priceRepository,
    ) {
    }

    /**
     * @param array<int, array{
     *   provider: AiProvider|string,
     *   model: string,
     *   input_tokens?: int,
     *   output_tokens?: int,
     *   reasoning_tokens?: int,
     *   tool_calls_count?: int
     * }> $providerEstimates
     */
    public function estimateCredits(string $workflow, AiRunMode $mode, array $providerEstimates): AiCreditEstimateData
    {
        $rawCostUsd = 0.0;
        $breakdown  = [];

        foreach ($providerEstimates as $estimate) {
            $provider = $estimate['provider'] instanceof AiProvider
                ? $estimate['provider']
                : AiProvider::from((string) $estimate['provider']);

            $model = (string) $estimate['model'];

            $inputTokens     = (int) ($estimate['input_tokens'] ?? 0);
            $outputTokens    = (int) ($estimate['output_tokens'] ?? 0);
            $reasoningTokens = (int) ($estimate['reasoning_tokens'] ?? 0);
            $toolCallsCount  = (int) ($estimate['tool_calls_count'] ?? 0);

            $price = $this->resolvePrice($provider, $model);
            $cost  = $this->calculateCostFromPriceRow(
                inputTokens: $inputTokens,
                outputTokens: $outputTokens,
                reasoningTokens: $reasoningTokens,
                toolCallsCount: $toolCallsCount,
                price: $price,
            );

            $rawCostUsd += $cost;

            $breakdown[] = [
                'source'           => 'price_table_estimate',
                'provider'         => $provider->value,
                'model'            => $model,
                'input_tokens'     => $inputTokens,
                'output_tokens'    => $outputTokens,
                'reasoning_tokens' => $reasoningTokens,
                'tool_calls_count' => $toolCallsCount,
                'cost_usd'         => round($cost, 8),
            ];
        }

        return $this->buildEstimate(
            workflow: $workflow,
            mode: $mode,
            rawCostUsd: $rawCostUsd,
            breakdown: $breakdown,
        );
    }

    /**
     * @param list<AiProviderResponseData> $providerResponses
     */
    public function calculateActualCredits(string $workflow, AiRunMode $mode, array $providerResponses): AiCreditEstimateData
    {
        $rawCostUsd = 0.0;
        $breakdown  = [];

        foreach ($providerResponses as $response) {
            $usage  = $response->usage;
            $cost   = $usage->rawCostUsd;
            $source = 'provider_reported';

            if ($cost === null) {
                $price = $this->resolvePrice($response->provider, $response->model);

                $cost = $this->calculateCostFromPriceRow(
                    inputTokens: $usage->inputTokens,
                    outputTokens: $usage->outputTokens,
                    reasoningTokens: $usage->reasoningTokens,
                    toolCallsCount: $usage->toolCallsCount,
                    price: $price,
                );

                $source = 'price_table_fallback';
            }

            $rawCostUsd += $cost;

            $breakdown[] = [
                'source'           => $source,
                'provider'         => $response->provider->value,
                'model'            => $response->model,
                'input_tokens'     => $usage->inputTokens,
                'output_tokens'    => $usage->outputTokens,
                'reasoning_tokens' => $usage->reasoningTokens,
                'tool_calls_count' => $usage->toolCallsCount,
                'cost_usd'         => round((float) $cost, 8),
            ];
        }

        return $this->buildEstimate(
            workflow: $workflow,
            mode: $mode,
            rawCostUsd: $rawCostUsd,
            breakdown: $breakdown,
        );
    }

    /**
     * Normaliza uma única chamada de provider em créditos EasyEye (sem aplicar piso de workflow).
     *
     * Usado pelo AiOrchestrator para registrar `normalized_credits` granular por chamada em
     * `ai_run_provider_calls`. Mantém a fórmula em um só lugar (margem + usd_per_credit + ceil)
     * para evitar divergir do cálculo de `calculateActualCredits`.
     */
    public function normalizeCreditsForResponse(AiProviderResponseData $response): int
    {
        $rawCostUsd = (float) ($response->usage->rawCostUsd ?? 0.0);

        if ($rawCostUsd <= 0.0) {
            return 0;
        }

        $marginMultiplier = max(0.0, (float) config('ai.pricing.margin_multiplier', 2.0));
        $usdPerCredit     = max(0.000001, (float) config('ai.pricing.usd_per_credit', 0.01));

        return (int) ceil(($rawCostUsd * $marginMultiplier) / $usdPerCredit);
    }

    private function resolvePrice(AiProvider $provider, string $model): AiModelPrice
    {
        $price = $this->priceRepository->findActive($provider, $model);

        if (! $price) {
            throw new AiModelPriceNotFoundException($provider, $model);
        }

        return $price;
    }

    private function calculateCostFromPriceRow(
        int $inputTokens,
        int $outputTokens,
        int $reasoningTokens,
        int $toolCallsCount,
        AiModelPrice $price,
    ): float {
        $inputCost     = ($inputTokens / 1_000_000) * (float) $price->input_usd_per_million;
        $outputCost    = ($outputTokens / 1_000_000) * (float) $price->output_usd_per_million;
        $reasoningCost = ($reasoningTokens / 1_000_000) * (float) ($price->reasoning_usd_per_million ?? 0);
        $toolCost      = $toolCallsCount * (float) ($price->tool_call_usd ?? 0);

        return round($inputCost + $outputCost + $reasoningCost + $toolCost, 8);
    }

    /**
     * @param array<int, array<string, mixed>> $breakdown
     */
    private function buildEstimate(string $workflow, AiRunMode $mode, float $rawCostUsd, array $breakdown): AiCreditEstimateData
    {
        $rawCostUsd           = round($rawCostUsd, 8);
        $marginMultiplier     = max(0.0, (float) config('ai.pricing.margin_multiplier', 2.0));
        $usdPerCredit         = max(0.000001, (float) config('ai.pricing.usd_per_credit', 0.01));
        $costUsdWithMargin    = round($rawCostUsd * $marginMultiplier, 8);
        $creditsBeforeMinimum = (int) ceil($costUsdWithMargin / $usdPerCredit);
        $minimumCredits       = $this->minimumCreditsForWorkflow($workflow);
        $normalizedCredits    = max($minimumCredits, $creditsBeforeMinimum);

        return new AiCreditEstimateData(
            workflow: $workflow,
            mode: $mode,
            rawCostUsd: $rawCostUsd,
            costUsdWithMargin: $costUsdWithMargin,
            marginMultiplier: $marginMultiplier,
            usdPerCredit: $usdPerCredit,
            creditsBeforeMinimum: $creditsBeforeMinimum,
            minimumCredits: $minimumCredits,
            minimumApplied: $normalizedCredits > $creditsBeforeMinimum,
            normalizedCredits: $normalizedCredits,
            breakdown: $breakdown,
        );
    }

    private function minimumCreditsForWorkflow(string $workflow): int
    {
        $workflowMinimum = (int) data_get(config('ai.pricing.minimum_credits_by_workflow', []), $workflow, 0);
        $defaultMinimum  = (int) config('ai.pricing.minimum_credits_default', 5);

        return max(0, $workflowMinimum, $defaultMinimum);
    }
}
