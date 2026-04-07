<?php

namespace App\DTOs\Billing;

readonly class CancelSubscriptionDTO
{
    public function __construct(
        public string $entityId,
        public string $subscriptionId,
        public string $externalSubscriptionId,
        public ?string $reason = null,
        public array $metadata = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'reason'   => $this->reason,
            'metadata' => array_merge($this->metadata, [
                'entity_id'       => $this->entityId,
                'subscription_id' => $this->subscriptionId,
            ]),
        ];
    }
}
