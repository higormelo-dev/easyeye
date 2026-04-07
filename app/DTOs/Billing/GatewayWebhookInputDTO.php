<?php

namespace App\DTOs\Billing;

readonly class GatewayWebhookInputDTO
{
    public function __construct(
        public string $gatewayCode,
        public array $headers,
        public string $body,
        public array $payload,
        public ?string $externalEventId = null,
        public ?string $signature = null,
        public ?string $receivedAt = null,
    ) {
    }
}
