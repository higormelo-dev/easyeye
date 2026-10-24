<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\DTOs\AI\AiPricingPolicyData;
use Carbon\CarbonImmutable;

/**
 * Monta a política de preço vigente: câmbio (AiExchangeRateService::current) + markup e pisos (AiPricingSettings).
 * É quem a precificação consulta UMA vez por execução; o resultado é congelado em `ai_runs` antes da reserva.
 */
class AiPricingPolicyResolver
{
    public function __construct(
        private readonly AiExchangeRateService $rates,
        private readonly AiPricingSettings $settings,
    ) {
    }

    public function current(): AiPricingPolicyData
    {
        $rate = $this->rates->current();

        return new AiPricingPolicyData(
            exchangeRateBrlUsd: $rate['rate'],
            markupMultiplier: $this->settings->markupMultiplier(),
            minimumChargeCentsDefault: $this->settings->minimumChargeCentsDefault(),
            minimumChargeCentsByWorkflow: $this->settings->minimumChargeCentsByWorkflow(),
            source: $rate['source'],
            effectiveAt: $rate['effective_at'] !== null ? CarbonImmutable::instance($rate['effective_at']) : null,
            exchangeRateId: $rate['exchange_rate_id'],
        );
    }
}
