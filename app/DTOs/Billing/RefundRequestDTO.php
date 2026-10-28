<?php

namespace App\DTOs\Billing;

/**
 * Estorno de um pagamento no gateway. $amount nulo = estorno total; com
 * valor = parcial (só se o gateway aceitar — supportsPartialRefund).
 */
readonly class RefundRequestDTO
{
    public function __construct(
        public string $externalPaymentId,
        public ?float $amount,
        public string $description,
        public string $idempotencyKey,
    ) {
    }

    public function isPartial(): bool
    {
        return $this->amount !== null;
    }
}
