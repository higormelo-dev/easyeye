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
    public const MARKUP_MIN = 1.0;

    public const MARKUP_MAX = 20.0;

    /**
     * @param array<string, int>   $minimumChargeCentsByWorkflow
     * @param string               $source                       manual|frankfurter|legacy_topup|fallback (origem da cotação)
     * @param array<string, mixed> $markupByWorkflow             overrides de markup por workflow (só os válidos contam)
     */
    public function __construct(
        public float $exchangeRateBrlUsd,
        public float $markupMultiplier,
        public int $minimumChargeCentsDefault,
        public array $minimumChargeCentsByWorkflow = [],
        public string $source = 'manual',
        public ?CarbonImmutable $effectiveAt = null,
        public ?string $exchangeRateId = null,
        public array $markupByWorkflow = [],
    ) {
    }

    /** Markup efetivo da ação: override numérico dentro de 1–20, senão o global. */
    public function markupFor(string $workflow): float
    {
        $override = $this->markupByWorkflow[$workflow] ?? null;

        if (is_numeric($override) && is_finite((float) $override) && (float) $override >= self::MARKUP_MIN && (float) $override <= self::MARKUP_MAX) {
            return (float) $override;
        }

        return $this->markupMultiplier;
    }

    /** Piso em centavos do workflow: o maior entre o global e o do workflow, nunca abaixo de 1 centavo. */
    public function minimumChargeCentsFor(string $workflow): int
    {
        return max(1, $this->minimumChargeCentsDefault, (int) ($this->minimumChargeCentsByWorkflow[$workflow] ?? 0));
    }
}
