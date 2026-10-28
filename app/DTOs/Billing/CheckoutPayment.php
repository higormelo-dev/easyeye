<?php

namespace App\DTOs\Billing;

use App\Enums\Billing\CheckoutMethod;

/**
 * Forma de pagamento escolhida na contratação pelo checkout transparente
 * (BillingSubscriptionOrchestrator::activateWithGateway). $outcome é
 * preenchido pelo orquestrador com o que o front precisa depois (3DS,
 * cartão guardado, motivo da recusa).
 */
readonly class CheckoutPayment
{
    public function __construct(
        public CheckoutMethod $method,
        public ?CheckoutCardInput $card = null,
        public CheckoutOutcome $outcome = new CheckoutOutcome(),
    ) {
    }

    public function isCard(): bool
    {
        return $this->method->isCard();
    }
}
