<?php

namespace App\DTOs\Billing;

readonly class CreateSubscriptionDTO
{
    public function __construct(
        public string $entityId,
        public string $subscriptionId,
        public string $planId,
        public string $customerId,
        public float $amount,
        public string $currency,
        public string $interval,
        public int $intervalCount = 1,
        public ?string $description = null,
        public array $metadata = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'customer_id'    => $this->customerId,
            'amount'         => $this->amount,
            'currency'       => $this->currency,
            'interval'       => $this->interval,
            'interval_count' => $this->intervalCount,
            'description'    => $this->description,
            'metadata'       => array_merge($this->metadata, [
                'entity_id'       => $this->entityId,
                'subscription_id' => $this->subscriptionId,
                'plan_id'         => $this->planId,
            ]),
        ];
    }
}
