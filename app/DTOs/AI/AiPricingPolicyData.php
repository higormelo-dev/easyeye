<?php

declare(strict_types=1);

namespace App\DTOs\AI;

use Carbon\CarbonImmutable;

/**
 * Política de preço vigente (ou congelada numa execução): câmbio, markup e pisos. Imutável e sem banco — os testes
 * montam o DTO na mão. NUNCA vai para rota de clínica: câmbio e markup são segredo comercial do SaaS.
 */
readonly class AiPricingPolicyData
{
    /**
     * @param array<string, int> $minimumChargeCentsByWorkflow
     * @param string             $source                       manual|legacy_topup|fallback (origem da cotação)
     */
    public function __construct(
        public float $exchangeRateBrlUsd,
        public float $markupMultiplier,
        public int $minimumChargeCentsDefault,
        public array $minimumChargeCentsByWorkflow = [],
        public string $source = 'manual',
        public ?CarbonImmutable $effectiveAt = null,
        public ?string $exchangeRateId = null,
    ) {
    }

    /** Piso em centavos do workflow: o maior entre o global e o do workflow, nunca abaixo de 1 centavo. */
    public function minimumChargeCentsFor(string $workflow): int
    {
        return max(1, $this->minimumChargeCentsDefault, (int) ($this->minimumChargeCentsByWorkflow[$workflow] ?? 0));
    }
}
