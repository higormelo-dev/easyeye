<?php

namespace App\DTOs\Billing;

readonly class CreateChargeResultDTO
{
    public function __construct(
        public bool $success,
        public ?string $externalPaymentId,
        public ?string $status,
        public ?float $amount,
        public ?array $rawResponse,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
    ) {
    }
}
