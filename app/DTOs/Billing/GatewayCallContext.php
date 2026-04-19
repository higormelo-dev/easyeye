<?php

namespace App\DTOs\Billing;

final readonly class GatewayCallContext
{
    public function __construct(
        public string $correlationId,
        public string $entityId,
    ) {
    }
}
