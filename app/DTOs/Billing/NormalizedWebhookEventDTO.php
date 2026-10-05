<?php

namespace App\DTOs\Billing;

readonly class NormalizedWebhookEventDTO
{
    public function __construct(
        public string $gatewayCode,
        public string $eventType,
        public ?string $externalEventId,
        public ?string $externalSubscriptionId,
        public ?string $externalPaymentId,
        public ?string $externalInvoiceId,
        public ?string $status,
        public ?float $amount,
        public ?string $currency,
        public array $metadata,
        public array $rawPayload,
        public string $occurredAt,
        // Vencimento da cobrança (Y-m-d) e link de pagamento (página da
        // fatura, boleto ou Pix), quando o gateway informa.
        public ?string $dueDate = null,
        public ?string $paymentUrl = null,
    ) {
    }
}
