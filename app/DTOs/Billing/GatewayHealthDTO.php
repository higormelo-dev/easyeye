<?php

namespace App\DTOs\Billing;

readonly class GatewayHealthDTO
{
    public function __construct(
        public bool $healthy,
        public ?int $httpStatus = null,
        public ?string $message = null,
        public ?int $latencyMs = null,
    ) {
    }
}
