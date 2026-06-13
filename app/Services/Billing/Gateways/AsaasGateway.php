<?php

namespace App\Services\Billing\Gateways;

use App\DTOs\Billing\{
    CancelSubscriptionDTO,
    CancelSubscriptionResultDTO,
    CreateChargeDTO,
    CreateChargeResultDTO,
    CreateSubscriptionDTO,
    CreateSubscriptionResultDTO,
    CustomerDTO,
    GatewayWebhookInputDTO,
    NormalizedWebhookEventDTO,
};
use App\Exceptions\Billing\GatewayIntegrationException;

/**
 * Integração completa com a API Asaas v3.
 *
 * Documentação: https://docs.asaas.com
 * Autenticação: header "access_token: $TOKEN"
 */
class AsaasGateway extends AbstractHttpGateway
{
    public function code(): string
    {
        return 'asaas';
    }

    // ── Autenticação ─────────────────────────────────────────────────────────

    protected function authHeaders(): array
    {
        return [
            'access_token' => (string) $this->resolveSecret(),
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json',
        ];
    }

    // ── Clientes ─────────────────────────────────────────────────────────────

    /**
     * Upsert: busca cliente por CPF/CNPJ; cria se não encontrar.
     */
    public function upsertCustomer(CustomerDTO $customer): string
    {
        // Tenta localizar cliente existente pelo documento
        $cpfCnpj = preg_replace('/\D/', '', (string) $customer->document);

        if ($cpfCnpj !== '') {
            $search = $this->get('customers', ['cpfCnpj' => $cpfCnpj]);

            if ($search->successful()) {
                $data = $search->json('data', []);

                if (! empty($data)) {
                    return (string) $data[0]['id'];
                }
            }
        }

        // Cria novo cliente
        $response = $this->post('customers', $this->buildCustomerPayload($customer));

        if (! $response->successful()) {
            throw GatewayIntegrationException::fromHttpStatus(
                $this->code(),
                $response->status(),
                $response->body(),
            );
        }

        return (string) $response->json('id');
    }

    protected function buildCustomerPayload(CustomerDTO $customer): array
    {
        $phone = preg_replace('/\D/', '', (string) $customer->phone);

        return array_filter([
            'name'                 => $customer->name,
            'email'                => $customer->email,
            'cpfCnpj'              => preg_replace('/\D/', '', (string) $customer->document),
            'mobilePhone'          => $phone ?: null,
            'externalReference'    => $customer->externalReference,
            'notificationDisabled' => false,
        ]);
    }

    // ── Assinaturas ──────────────────────────────────────────────────────────

    public function createSubscription(CreateSubscriptionDTO $payload): CreateSubscriptionResultDTO
    {
        $response = $this->post('subscriptions', $this->buildSubscriptionPayload($payload));

        if (! $response->successful()) {
            return new CreateSubscriptionResultDTO(
                success: false,
                externalSubscriptionId: null,
                externalCustomerId: null,
                status: null,
                rawResponse: $response->json() ?? [],
                errorMessage: mb_substr($response->body(), 0, 1000),
            );
        }

        $json = $response->json();

        return new CreateSubscriptionResultDTO(
            success: true,
            externalSubscriptionId: (string) ($json['id'] ?? ''),
            externalCustomerId: (string) ($json['customer'] ?? $payload->customerId),
            status: $this->normalizeStatus($json['status'] ?? null),
            rawResponse: $json ?? [],
        );
    }

    protected function buildSubscriptionPayload(CreateSubscriptionDTO $payload): array
    {
        $cycle = $this->resolveBillingCycleFromInterval($payload->interval, $payload->intervalCount);

        return array_filter([
            'customer'                   => $payload->customerId,
            'billingType'                => 'BOLETO',
            'value'                      => $payload->amount,
            'nextDueDate'                => now()->addDays(1)->format('Y-m-d'),
            'cycle'                      => $cycle,
            'description'                => $payload->description ?? "Assinatura {$payload->planId}",
            'externalReference'          => $payload->subscriptionId,
            'sendPaymentByPostalService' => false,
        ]);
    }

    public function cancelSubscription(CancelSubscriptionDTO $payload): CancelSubscriptionResultDTO
    {
        $response = $this->request('DELETE', 'subscription_cancel', [], ['id' => $payload->externalSubscriptionId]);

        if (! $response->successful()) {
            return new CancelSubscriptionResultDTO(
                success: false,
                status: null,
                rawResponse: $response->json() ?? [],
                errorMessage: mb_substr($response->body(), 0, 1000),
            );
        }

        return new CancelSubscriptionResultDTO(
            success: true,
            status: 'cancelled',
            rawResponse: $response->json() ?? [],
        );
    }

    // ── Cobranças ────────────────────────────────────────────────────────────

    public function createCharge(CreateChargeDTO $payload): CreateChargeResultDTO
    {
        $response = $this->request(
            method: 'POST',
            endpointKey: 'charges',
            payload: $this->buildChargePayload($payload),
            idempotencyKey: $payload->idempotencyKey,
        );

        if (! $response->successful()) {
            return new CreateChargeResultDTO(
                success: false,
                externalPaymentId: null,
                status: null,
                amount: null,
                rawResponse: $this->sanitizePayload($response->json() ?? []),
                errorCode: (string) $response->status(),
                errorMessage: mb_substr($response->body(), 0, 1000),
            );
        }

        $json = $response->json();

        return new CreateChargeResultDTO(
            success: true,
            externalPaymentId: (string) ($json['id'] ?? ''),
            status: $this->normalizeAsaasPaymentStatus($json['status'] ?? ''),
            amount: isset($json['value']) ? (float) $json['value'] : null,
            rawResponse: $this->sanitizePayload($json ?? []),
        );
    }

    protected function buildChargePayload(CreateChargeDTO $payload): array
    {
        return array_filter([
            'customer'          => $payload->customerId,
            'billingType'       => $this->mapPaymentMethod($payload->paymentMethod),
            'value'             => $payload->amount,
            'dueDate'           => $payload->dueDate ?? now()->addDays(3)->format('Y-m-d'),
            'description'       => $payload->description,
            'externalReference' => $payload->invoiceId,
        ]);
    }

    // ── Webhooks ─────────────────────────────────────────────────────────────

    /**
     * Asaas valida webhook via header "asaas-access-token" comparando com o token configurado.
     */
    public function validateWebhookSignature(GatewayWebhookInputDTO $payload): bool
    {
        $secret = $this->resolveWebhookSecret();

        if ($secret === null || $secret === '') {
            return true; // sem secret configurado: aceita em dev/sandbox
        }

        $provided = $payload->headers['asaas-access-token']
            ?? $payload->headers['Asaas-Access-Token']
            ?? null;

        if (! is_string($provided) || $provided === '') {
            return false;
        }

        return hash_equals($secret, $provided);
    }

    public function parseWebhook(GatewayWebhookInputDTO $payload): NormalizedWebhookEventDTO
    {
        $event   = (string) ($payload->payload['event'] ?? 'unknown');
        $payment = $payload->payload['payment'] ?? [];

        $normalizedType         = $this->normalizeEventType($event);
        $externalSubscriptionId = $payment['subscription'] ?? null;
        $externalPaymentId      = $payment['id'] ?? null;
        $externalRef            = $payment['externalReference'] ?? null;
        $amount                 = isset($payment['value']) ? (float) $payment['value'] : null;
        $status                 = $this->normalizeAsaasPaymentStatus($payment['status'] ?? '');

        $metadata = [];

        if ($externalRef) {
            $metadata['invoice_id'] = $externalRef; // externalReference = invoice_id interno
        }

        return new NormalizedWebhookEventDTO(
            gatewayCode: $payload->gatewayCode,
            eventType: $normalizedType,
            externalEventId: $payload->externalEventId,
            externalSubscriptionId: is_string($externalSubscriptionId) ? $externalSubscriptionId : null,
            externalPaymentId: is_string($externalPaymentId) ? $externalPaymentId : null,
            externalInvoiceId: null,
            status: $status,
            amount: $amount,
            currency: 'BRL',
            metadata: $metadata,
            rawPayload: $payload->payload,
            occurredAt: now()->toIso8601String(),
        );
    }

    // ── Mapa de eventos ───────────────────────────────────────────────────────

    protected function eventTypeMap(): array
    {
        return [
            'PAYMENT_RECEIVED'                     => 'paid',
            'PAYMENT_CONFIRMED'                    => 'paid',
            'PAYMENT_ANTICIPATED'                  => 'paid',
            'PAYMENT_DUNNING_RECEIVED'             => 'paid',
            'PAYMENT_OVERDUE'                      => 'failed',
            'PAYMENT_REFUSED'                      => 'failed',
            'PAYMENT_DELETED'                      => 'cancelled',
            'PAYMENT_RESTORED'                     => 'unknown',
            'PAYMENT_UPDATED'                      => 'unknown',
            'PAYMENT_CREATED'                      => 'unknown',
            'PAYMENT_BANK_SLIP_VIEWED'             => 'unknown',
            'PAYMENT_CHECKOUT_VIEWED'              => 'unknown',
            'PAYMENT_REFUNDED'                     => 'refunded',
            'PAYMENT_RECEIVED_IN_CASH_UNDONE'      => 'refunded',
            'PAYMENT_CHARGEBACK_REQUESTED'         => 'chargeback',
            'PAYMENT_CHARGEBACK_DISPUTE'           => 'chargeback',
            'PAYMENT_AWAITING_CHARGEBACK_REVERSAL' => 'chargeback',
            'PAYMENT_DUNNING_REQUESTED'            => 'unknown',
            'SUBSCRIPTION_INACTIVATED'             => 'cancelled',
            'SUBSCRIPTION_ACTIVATED'               => 'unknown',
        ];
    }

    // ── Helpers privados ─────────────────────────────────────────────────────

    private function normalizeAsaasPaymentStatus(string $status): string
    {
        return match (strtoupper($status)) {
            'RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH' => 'paid',
            'PENDING', 'AWAITING_RISK_ANALYSIS' => 'pending',
            'OVERDUE', 'DUNNING_REQUESTED' => 'failed',
            'REFUNDED', 'REFUND_REQUESTED' => 'refunded',
            'CHARGEBACK_REQUESTED', 'CHARGEBACK_DISPUTE' => 'chargeback',
            'CANCELLED', 'DELETED' => 'cancelled',
            default => strtolower($status),
        };
    }

    private function mapPaymentMethod(?string $method): string
    {
        return match (strtolower((string) $method)) {
            'pix'         => 'PIX',
            'credit_card' => 'CREDIT_CARD',
            'boleto'      => 'BOLETO',
            default       => 'BOLETO',
        };
    }

    private function resolveBillingCycleFromInterval(string $interval, int $count): string
    {
        if ($interval === 'year') {
            return 'YEARLY';
        }

        return match ($count) {
            1       => 'MONTHLY',
            3       => 'QUARTERLY',
            6       => 'SEMIANNUALLY',
            12      => 'YEARLY',
            default => 'MONTHLY',
        };
    }
}
