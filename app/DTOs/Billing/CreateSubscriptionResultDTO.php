<?php

namespace App\DTOs\Billing;

readonly class CreateSubscriptionResultDTO
{
    public function __construct(
        public bool $success,
        public ?string $externalSubscriptionId,
        public ?string $externalCustomerId,
        public ?string $status,
        public ?array $rawResponse,
        public ?string $errorMessage = null,
    ) {
    }
}
