<?php

namespace App\DTOs\Billing;

readonly class CancelSubscriptionResultDTO
{
    public function __construct(
        public bool $success,
        public ?string $status,
        public ?array $rawResponse,
        public ?string $errorMessage = null,
    ) {
    }
}
