<?php

namespace App\DTOs\Billing;

/**
 * Pedido de checkout hospedado no gateway (Asaas Checkout — POST
 * /v3/checkouts, https://docs.asaas.com/reference/criar-novo-checkout): o
 * cartão é digitado na página do gateway; a volta é pelas URLs de callback.
 *
 * $cycle preenchido = recorrência no cartão (chargeTypes RECURRENT, com a 1ª
 * cobrança em $nextDueDate); nulo = cobrança avulsa (DETACHED).
 */
readonly class HostedCheckoutDTO
{
    public function __construct(
        public string $entityId,
        public string $invoiceId,
        public ?string $subscriptionId,
        public ?string $customerId,
        public float $amount,
        public string $itemName,
        public string $description,
        public string $successUrl,
        public string $cancelUrl,
        public string $expiredUrl,
        public string $externalReference,
        public ?string $cycle = null,
        public ?string $nextDueDate = null,
        public ?int $minutesToExpire = null,
    ) {
    }

    public function isRecurrent(): bool
    {
        return $this->cycle !== null;
    }
}
