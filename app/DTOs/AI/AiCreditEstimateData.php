<?php

declare(strict_types=1);

namespace App\DTOs\AI;

use App\Enums\AI\AiRunMode;

readonly class AiCreditEstimateData
{
    /**
     * @param array<int, array<string, mixed>> $breakdown
     */
    public function __construct(
        public string $workflow,
        public AiRunMode $mode,
        public float $rawCostUsd,
        public float $costUsdWithMargin,
        public float $marginMultiplier,
        public float $usdPerCredit,
        public int $creditsBeforeMinimum,
        public int $minimumCredits,
        public bool $minimumApplied,
        public int $normalizedCredits,
        public array $breakdown = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'workflow'               => $this->workflow,
            'mode'                   => $this->mode->value,
            'raw_cost_usd'           => $this->rawCostUsd,
            'cost_usd_with_margin'   => $this->costUsdWithMargin,
            'margin_multiplier'      => $this->marginMultiplier,
            'usd_per_credit'         => $this->usdPerCredit,
            'credits_before_minimum' => $this->creditsBeforeMinimum,
            'minimum_credits'        => $this->minimumCredits,
            'minimum_applied'        => $this->minimumApplied,
            'normalized_credits'     => $this->normalizedCredits,
            'breakdown'              => $this->breakdown,
        ];
    }
}
