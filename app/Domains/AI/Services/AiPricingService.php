<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\AI\Contracts\AiModelPriceRepositoryInterface;
use App\Domains\AI\Exceptions\AiModelPriceNotFoundException;
use App\Domains\AI\Models\AiModelPrice;
use App\DTOs\AI\{AiCreditEstimateData, AiPricingPolicyData, AiProviderResponseData};
use App\Enums\AI\{AiProvider, AiRunMode};
use Brick\Math\{BigDecimal, RoundingMode};

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
    public function estimateCharge(string $workflow, AiRunMode $mode, array $providerEstimates, AiPricingPolicyData $policy): AiCreditEstimateData
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
            policy: $policy,
            breakdown: $breakdown,
        );
    }

    /**
     * @param list<AiProviderResponseData> $providerResponses
     */
    public function calculateActualCharge(string $workflow, AiRunMode $mode, array $providerResponses, AiPricingPolicyData $policy): AiCreditEstimateData
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
            policy: $policy,
            breakdown: $breakdown,
        );
    }

    /**
     * Valor DIAGNÓSTICO de uma única chamada de provedor, em centavos (arredondado para CIMA por chamada, sem piso).
     *
     * Usado pelo AiOrchestrator para registrar `normalized_charge_cents` em `ai_run_provider_calls`. NÃO é o que se
     * debita: a cobrança da execução usa a soma do custo real das chamadas e a fórmula única de `chargeCentsFromUsd`
     * (HALF_UP, com piso) — somar estes centavos por chamada inflaria a conta.
     */
    public function normalizeChargeForResponse(AiProviderResponseData $response, AiPricingPolicyData $policy, string $workflow): int
    {
        $rawCostUsd = (float) ($this->resolveCostUsdForResponse($response) ?? 0.0);

        if ($rawCostUsd <= 0.0) {
            return 0;
        }

        return $this->grossCents($rawCostUsd, $policy, $workflow, RoundingMode::CEILING);
    }

    /**
     * Fórmula única do preço: `bruto = round(custo_usd × câmbio × markup_da_ação × 100, HALF_UP)`;
     * `preço = max(1, piso, bruto)`. Aritmética decimal exata (sem float) para a borda de 0,5 centavo não oscilar.
     */
    public function chargeCentsFromUsd(float $rawCostUsd, string $workflow, AiPricingPolicyData $policy): int
    {
        return max(1, $policy->minimumChargeCentsFor($workflow), $this->grossCents($rawCostUsd, $policy, $workflow));
    }

    /** Preço mínimo quando o modelo não tem preço cadastrado: piso do workflow × modo (Economy 1, Validated 2, Consensus 3). */
    public function fallbackMinimumCharge(string $workflow, AiRunMode $mode, AiPricingPolicyData $policy): AiCreditEstimateData
    {
        $multiplier = match ($mode) {
            AiRunMode::Economy   => 1,
            AiRunMode::Validated => 2,
            AiRunMode::Consensus => 3,
        };

        $minimum = $policy->minimumChargeCentsFor($workflow) * $multiplier;

        return new AiCreditEstimateData(
            workflow: $workflow,
            mode: $mode,
            rawCostUsd: 0.0,
            grossChargeCents: 0,
            minimumChargeCents: $minimum,
            minimumApplied: true,
            chargeCents: $minimum,
            policy: $policy,
            breakdown: [[
                'source' => 'fallback_minimum_only',
                'reason' => 'model_price_not_configured',
            ]],
        );
    }

    /**
     * Custo USD de UMA chamada: usa o valor reportado pelo provider ou cai na
     * tabela de preços. Providers reais nunca reportam custo (rawCostUsd null),
     * então sem este fallback ai_run_provider_calls.raw_cost_usd ficava null e
     * o dashboard de custo/finanças da plataforma somava sempre US$ 0.
     * Retorna null quando o modelo não tem preço cadastrado (não lança).
     */
    public function resolveCostUsdForResponse(AiProviderResponseData $response): ?float
    {
        if ($response->usage->rawCostUsd !== null) {
            return (float) $response->usage->rawCostUsd;
        }

        try {
            $price = $this->resolvePrice($response->provider, $response->model);
        } catch (AiModelPriceNotFoundException) {
            return null;
        }

        return $this->calculateCostFromPriceRow(
            inputTokens: $response->usage->inputTokens,
            outputTokens: $response->usage->outputTokens,
            reasoningTokens: $response->usage->reasoningTokens,
            toolCallsCount: $response->usage->toolCallsCount,
            price: $price,
        );
    }

    public function hasPriceFor(AiProvider $provider, string $model): bool
    {
        return $this->priceRepository->findActive($provider, $model) !== null;
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
        $inputCost  = ($inputTokens / 1_000_000) * (float) $price->input_usd_per_million;
        $outputCost = ($outputTokens / 1_000_000) * (float) $price->output_usd_per_million;
        // Sem preço próprio de raciocínio, vale o de saída — todo provedor
        // cobra o raciocínio como saída (0 explícito = raciocínio gratuito).
        $reasoningCost = ($reasoningTokens / 1_000_000) * (float) ($price->reasoning_usd_per_million ?? $price->output_usd_per_million);
        $toolCost      = $toolCallsCount * (float) ($price->tool_call_usd ?? 0);

        return round($inputCost + $outputCost + $reasoningCost + $toolCost, 8);
    }

    /**
     * @param array<int, array<string, mixed>> $breakdown
     */
    private function buildEstimate(string $workflow, AiRunMode $mode, float $rawCostUsd, AiPricingPolicyData $policy, array $breakdown): AiCreditEstimateData
    {
        $rawCostUsd  = round($rawCostUsd, 8);
        $gross       = $this->grossCents($rawCostUsd, $policy, $workflow);
        $minimum     = max(1, $policy->minimumChargeCentsFor($workflow));
        $chargeCents = max($minimum, $gross);

        return new AiCreditEstimateData(
            workflow: $workflow,
            mode: $mode,
            rawCostUsd: $rawCostUsd,
            grossChargeCents: $gross,
            minimumChargeCents: $minimum,
            minimumApplied: $chargeCents > $gross,
            chargeCents: $chargeCents,
            policy: $policy,
            breakdown: $breakdown,
        );
    }

    private function grossCents(float $rawCostUsd, AiPricingPolicyData $policy, string $workflow, RoundingMode $rounding = RoundingMode::HALF_UP): int
    {
        return BigDecimal::of(number_format(max(0.0, $rawCostUsd), 8, '.', ''))
            ->multipliedBy(number_format(max(0.0, $policy->exchangeRateBrlUsd), 6, '.', ''))
            ->multipliedBy(number_format(max(0.0, $policy->markupFor($workflow)), 3, '.', ''))
            ->multipliedBy('100')
            ->toScale(0, $rounding)
            ->toInt();
    }
}
