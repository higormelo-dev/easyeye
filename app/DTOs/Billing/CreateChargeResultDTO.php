<?php

namespace App\DTOs\Billing;

readonly class CreateChargeResultDTO
{
    public function __construct(
        public bool $success,
        public ?string $externalPaymentId,
        public ?string $status,
        public ?float $amount,
        public ?array $rawResponse,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        // Link de pagamento (página da fatura, boleto ou Pix) quando a
        // resposta do gateway já o traz — gravado em invoices.payment_url na
        // emissão, sem esperar o webhook.
        public ?string $paymentUrl = null,
        // Cartão: o cartão guardado no gateway (cobrança com saveCard) e a
        // ação pendente do banco (3DS do Stripe: client_secret para o
        // Stripe.js concluir no navegador).
        public ?SavedCardDTO $savedCard = null,
        public ?array $nextAction = null,
    ) {
    }
}
