<?php

namespace App\DTOs\Billing;

use App\Enums\{BillingCycle, SubscriptionBillingMode};
use Carbon\CarbonImmutable;

/**
 * Condições de uma assinatura sem gateway definidas no manager: cortesia
 * com data de término. Sem cobrança — a única receita é a cobrança
 * automática do gateway.
 */
final readonly class SubscriptionTerms
{
    private function __construct(
        public SubscriptionBillingMode $mode,
        public ?BillingCycle $cycle,
        public ?float $amount,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
    ) {
    }

    public static function complimentary(CarbonImmutable $startsAt, CarbonImmutable $endsAt): self
    {
        return new self(SubscriptionBillingMode::Complimentary, null, null, $startsAt, $endsAt);
    }

    /** @return array<string, mixed> */
    public function attributes(): array
    {
        return [
            'billing_mode'  => $this->mode,
            'billing_cycle' => $this->cycle,
            'amount'        => $this->amount,
            'starts_at'     => $this->startsAt,
            'ends_at'       => $this->endsAt,
        ];
    }
}
