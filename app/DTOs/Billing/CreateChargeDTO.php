<?php

namespace App\DTOs\Billing;

readonly class CreateChargeDTO
{
    public function __construct(
        public string $entityId,
        public string $invoiceId,
        public string $subscriptionId,
        public string $customerId,
        public float $amount,
        public string $currency,
        public string $description,
        public ?string $dueDate = null,
        public ?string $paymentMethod = null,
        public array $metadata = [],
        public ?string $idempotencyKey = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'customer_id'    => $this->customerId,
            'amount'         => $this->amount,
            'currency'       => $this->currency,
            'description'    => $this->description,
            'due_date'       => $this->dueDate,
            'payment_method' => $this->paymentMethod,
            'metadata'       => array_merge($this->metadata, [
                'entity_id'       => $this->entityId,
                'invoice_id'      => $this->invoiceId,
                'subscription_id' => $this->subscriptionId,
            ]),
        ];
    }
}
