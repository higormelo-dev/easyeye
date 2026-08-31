<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\AI\Exceptions\AiModelPriceNotFoundException;
use App\DTOs\AI\AiCreditEstimateData;
use App\Enums\AI\{AiProvider, AiRunMode};

/**
 * Estimativa de créditos de uma execução de IA (extraído de AiRunsController).
 *
 * Sem mudança de comportamento: lógica de pricing/provedores movida verbatim.
 */
class AiRunEstimateService
{
    public function __construct(
        private readonly AiProviderSettings $providerSettings,
        private readonly AiPricingService $pricingService,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function estimate(array $payload): AiCreditEstimateData
    {
        $mode          = AiRunMode::from((string) $payload['mode']);
        $workflow      = (string) $payload['workflow'];
        $providerCodes = $this->providerCodesForMode($mode);

        $prompt      = (string) ($payload['system_prompt'] ?? '') . "\n\n" . (string) $payload['user_prompt'];
        $context     = $payload['context'] ?? [];
        $contextJson = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $chars       = mb_strlen($prompt) + mb_strlen($contextJson !== false ? $contextJson : '');

        $baseInput = max(120, (int) ceil($chars / 4));

        // Sobretaxa de tokens por imagem (visão) — reflete o custo das imagens
        // do módulo Eye Image na estimativa de créditos.
        $imageCount = (int) ($payload['_image_count'] ?? 0);
        $baseInput += $imageCount * (int) config('ai.eye_image.tokens_per_image', 300);

        $baseOutput = max(100, min((int) ($payload['max_output_tokens'] ?? 700), intdiv($baseInput, 2) + 120));

        $providerEstimates = [];

        foreach ($providerCodes as $index => $providerCode) {
            $factor          = 1 + ($index * 0.35);
            $inputTokens     = (int) ceil($baseInput * $factor);
            $outputTokens    = (int) ceil($baseOutput * ($index === 0 ? 1.0 : 0.85));
            $reasoningTokens = match ($mode) {
                AiRunMode::Economy   => 12,
                AiRunMode::Validated => 36 + ($index * 8),
                AiRunMode::Consensus => 72 + ($index * 10),
            };

            $providerEstimates[] = [
                'provider'         => AiProvider::from($providerCode),
                'model'            => $this->providerModel($providerCode),
                'input_tokens'     => $inputTokens,
                'output_tokens'    => $outputTokens,
                'reasoning_tokens' => $reasoningTokens,
                'tool_calls_count' => 0,
            ];
        }

        // Um único modelo sem preço cadastrado não pode colapsar a estimativa
        // inteira para "só mínimo" — remove apenas os sem preço e estima com
        // os demais; o catch abaixo segue cobrindo o caso de NENHUM ter preço.
        $priced = array_values(array_filter(
            $providerEstimates,
            fn (array $e) => $this->pricingService->hasPriceFor($e['provider'], (string) $e['model']),
        ));

        if ($priced !== [] && count($priced) < count($providerEstimates)) {
            $providerEstimates = $priced;
        }

        try {
            return $this->pricingService->estimateCredits(
                workflow: $workflow,
                mode: $mode,
                providerEstimates: $providerEstimates,
            );
        } catch (AiModelPriceNotFoundException) {
            $minimumBase = max(
                (int) config('ai.pricing.minimum_credits_default', 5),
                (int) data_get(config('ai.pricing.minimum_credits_by_workflow', []), $workflow, 0),
            );

            $multiplier = match ($mode) {
                AiRunMode::Economy   => 1,
                AiRunMode::Validated => 2,
                AiRunMode::Consensus => 3,
            };

            $minimum = $minimumBase * $multiplier;

            return new AiCreditEstimateData(
                workflow: $workflow,
                mode: $mode,
                rawCostUsd: 0.0,
                costUsdWithMargin: 0.0,
                marginMultiplier: (float) config('ai.pricing.margin_multiplier', 2.0),
                usdPerCredit: (float) config('ai.pricing.usd_per_credit', 0.01),
                creditsBeforeMinimum: 0,
                minimumCredits: $minimum,
                minimumApplied: true,
                normalizedCredits: $minimum,
                breakdown: [[
                    'source' => 'fallback_minimum_only',
                    'reason' => 'model_price_not_configured',
                ]],
            );
        }
    }

    /**
     * @return list<string>
     */
    private function providerCodesForMode(AiRunMode $mode): array
    {
        return $this->providerSettings->providerCodesForMode($mode);
    }

    private function providerModel(string $provider): string
    {
        return match ($provider) {
            'openai'    => (string) config('ai.providers.openai.model', 'gpt-5-mini'),
            'anthropic' => (string) config('ai.providers.anthropic.model', 'claude-sonnet-4-5'),
            'gemini'    => (string) config('ai.providers.gemini.model', 'gemini-2.0-flash'),
            default     => 'unknown-model',
        };
    }

    /**
     * @param array<string, mixed> $guardrails
     *
     * @return list<string>
     */
    public function guardrailSafetyNotes(array $guardrails): array
    {
        if (! (bool) ($guardrails['pii_redacted'] ?? false)) {
            return [];
        }

        return [__('ai.safety.pii_redacted')];
    }

    /**
     * @return array<string, mixed>
     */
    public function publicEstimate(AiCreditEstimateData $estimate): array
    {
        return [
            'workflow'           => $estimate->workflow,
            'mode'               => $estimate->mode->value,
            'minimum_applied'    => $estimate->minimumApplied,
            'minimum_credits'    => $estimate->minimumCredits,
            'normalized_credits' => $estimate->normalizedCredits,
        ];
    }
}
