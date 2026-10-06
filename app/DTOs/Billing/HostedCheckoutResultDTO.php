<?php

namespace App\DTOs\Billing;

readonly class HostedCheckoutResultDTO
{
    public function __construct(
        public bool $success,
        public ?string $externalCheckoutId,
        public ?string $url,
        public ?string $status = null,
        public ?int $minutesToExpire = null,
        public array $rawResponse = [],
        public ?string $errorMessage = null,
        public ?int $httpStatus = null,
    ) {
    }
}
