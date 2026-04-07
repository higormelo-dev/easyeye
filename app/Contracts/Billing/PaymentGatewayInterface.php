<?php

namespace App\Contracts\Billing;

use App\DTOs\Billing\{CancelSubscriptionDTO, CancelSubscriptionResultDTO, CreateChargeDTO, CreateChargeResultDTO, CreateSubscriptionDTO, CreateSubscriptionResultDTO, CustomerDTO, GatewayHealthDTO, GatewayWebhookInputDTO, NormalizedWebhookEventDTO};

interface PaymentGatewayInterface
{
    public function code(): string;

    public function upsertCustomer(CustomerDTO $customer): string;

    public function createSubscription(CreateSubscriptionDTO $payload): CreateSubscriptionResultDTO;

    public function cancelSubscription(CancelSubscriptionDTO $payload): CancelSubscriptionResultDTO;

    public function createCharge(CreateChargeDTO $payload): CreateChargeResultDTO;

    public function fetchPayment(string $externalPaymentId): array;

    public function parseWebhook(GatewayWebhookInputDTO $payload): NormalizedWebhookEventDTO;

    public function validateWebhookSignature(GatewayWebhookInputDTO $payload): bool;

    public function healthCheck(): GatewayHealthDTO;
}
