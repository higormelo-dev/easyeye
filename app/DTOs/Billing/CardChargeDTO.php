<?php

namespace App\DTOs\Billing;

/**
 * Cobrança no cartão pelo checkout transparente (ou pela renovação com o
 * cartão salvo). O cartão chega SÓ como token do SDK JS oficial do gateway
 * ($cardToken: token do MercadoPago.js/tokenizecard.js do Pagar.me,
 * ConfirmationToken/PaymentMethod do Stripe.js, cartão criptografado do SDK
 * do PagBank) ou como o cartão já guardado no gateway ($savedCardId).
 *
 * $offSession: cobrança iniciada pelo EasyEye sem o cliente presente
 * (renovação). $saveCard: guardar o cartão para os próximos ciclos.
 */
readonly class CardChargeDTO
{
    public function __construct(
        public string $entityId,
        public string $invoiceId,
        public string $subscriptionId,
        public string $customerId,
        public float $amount,
        public string $currency,
        public string $description,
        public CustomerDTO $payer,
        public ?string $cardToken = null,
        public ?string $savedCardId = null,
        public int $installments = 1,
        public bool $saveCard = false,
        public bool $offSession = false,
        public ?string $idempotencyKey = null,
        public array $metadata = [],
        // Dados que o próprio SDK devolve junto do token e o gateway exige na
        // cobrança (Mercado Pago: payment_method_id e issuer_id). Nunca dado
        // do cartão em si.
        public ?string $paymentMethodId = null,
        public ?string $issuerId = null,
    ) {
    }
}
