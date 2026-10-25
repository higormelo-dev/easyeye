<?php

declare(strict_types=1);

namespace App\DTOs\AI;

use App\Enums\AI\AiRunMode;

/**
 * Preço de uma execução de IA em CENTAVOS de BRL, com a política (câmbio/markup/piso) usada para chegar nele.
 *
 * `chargeCents` é o que a clínica paga; o resto (custo USD, câmbio, markup, bruto, breakdown) é interno do SaaS e NUNCA
 * vai para rota de clínica — a clínica recebe só `charge_cents`/`charge_formatted` (AiRunEstimateService::publicEstimate).
 */
readonly class AiCreditEstimateData
{
    /**
     * @param array<int, array<string, mixed>> $breakdown
     */
    public function __construct(
        public string $workflow,
        public AiRunMode $mode,
        public float $rawCostUsd,
        public int $grossChargeCents,
        public int $minimumChargeCents,
        public bool $minimumApplied,
        public int $chargeCents,
        public AiPricingPolicyData $policy,
        public array $breakdown = [],
    ) {
    }

    /**
     * Colunas de `ai_runs` que congelam o preço da execução ANTES da reserva (estimativa em centavos + política usada).
     *
     * @return array<string, mixed>
     */
    public function runPricingAttributes(): array
    {
        return [
            'estimated_charge_cents' => $this->chargeCents,
            'reserved_charge_cents'  => 0,
            'consumed_charge_cents'  => 0,
            'estimated_cost_usd'     => number_format($this->rawCostUsd, 8, '.', ''),
            'exchange_rate_brl_usd'  => number_format($this->policy->exchangeRateBrlUsd, 6, '.', ''),
            'markup_multiplier'      => number_format($this->policy->markupFor($this->workflow), 3, '.', ''), // efetivo da ação
            'minimum_charge_cents'   => $this->minimumChargeCents,
            'pricing_source'         => $this->policy->source,
            'priced_at'              => now(),
        ];
    }

    /** Visão interna (manager/auditoria). @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'workflow'              => $this->workflow,
            'mode'                  => $this->mode->value,
            'raw_cost_usd'          => $this->rawCostUsd,
            'exchange_rate_brl_usd' => $this->policy->exchangeRateBrlUsd,
            'markup_multiplier'     => $this->policy->markupFor($this->workflow),
            'gross_charge_cents'    => $this->grossChargeCents,
            'minimum_charge_cents'  => $this->minimumChargeCents,
            'minimum_applied'       => $this->minimumApplied,
            'charge_cents'          => $this->chargeCents,
            'pricing_source'        => $this->policy->source,
            'breakdown'             => $this->breakdown,
        ];
    }
}
