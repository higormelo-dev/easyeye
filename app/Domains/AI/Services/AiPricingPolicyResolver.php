<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\DTOs\AI\AiPricingPolicyData;
use Carbon\{CarbonImmutable, CarbonInterface};

/**
 * Monta a política de preço vigente: câmbio (AiExchangeRateService::current) + markup global/por ação e pisos
 * (AiPricingSettings). É quem a precificação consulta UMA vez por execução; o resultado é congelado em `ai_runs` antes
 * da reserva. `ensureFresh=true` (só nos pontos de precificação) busca antes a cotação automática do dia; o manager e
 * os testes leem só o banco.
 */
class AiPricingPolicyResolver
{
    public function __construct(
        private readonly AiExchangeRateService $rates,
        private readonly AiPricingSettings $settings,
    ) {
    }

    public function current(bool $ensureFresh = false): AiPricingPolicyData
    {
        if ($ensureFresh) {
            $this->rates->ensureToday();
        }

        return $this->fromRate($this->rates->current());
    }

    /** @param array{rate: float, source: string, effective_at: ?CarbonInterface, exchange_rate_id: ?string} $rate */
    public function fromRate(array $rate): AiPricingPolicyData
    {
        return new AiPricingPolicyData(
            exchangeRateBrlUsd: $rate['rate'],
            markupMultiplier: $this->settings->markupMultiplier(),
            minimumChargeCentsDefault: $this->settings->minimumChargeCentsDefault(),
            minimumChargeCentsByWorkflow: $this->settings->minimumChargeCentsByWorkflow(),
            source: $rate['source'],
            effectiveAt: $rate['effective_at'] !== null ? CarbonImmutable::instance($rate['effective_at']) : null,
            exchangeRateId: $rate['exchange_rate_id'],
            markupByWorkflow: $this->settings->markupByWorkflow(),
        );
    }
}
