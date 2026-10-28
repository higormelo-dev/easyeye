<?php

namespace App\Services\Billing;

use App\Contracts\Billing\PaymentGatewayInterface;
use App\Exceptions\Billing\GatewayResolutionException;

class GatewayRegistry
{
    /**
     * @param array<string, PaymentGatewayInterface> $gateways
     */
    public function __construct(
        private readonly array $gateways,
    ) {
    }

    public function get(string $code): PaymentGatewayInterface
    {
        if (! array_key_exists($code, $this->gateways)) {
            throw new GatewayResolutionException("Gateway [{$code}] não está registrado.");
        }

        return $this->gateways[$code];
    }

    public function has(string $code): bool
    {
        return array_key_exists($code, $this->gateways);
    }
}
