<?php

namespace App\DTOs\Billing;

/**
 * Cartão vindo do front do checkout: SÓ o token do SDK JS oficial do gateway
 * (nunca número, CVV ou validade) e as parcelas escolhidas. paymentMethodId
 * e issuerId são os que o MercadoPago.js devolve junto do token (bandeira e
 * emissor) — o Mercado Pago exige os dois no POST /v1/payments.
 */
readonly class CheckoutCardInput
{
    public function __construct(
        public string $token,
        public int $installments = 1,
        public ?string $paymentMethodId = null,
        public ?string $issuerId = null,
    ) {
    }
}
