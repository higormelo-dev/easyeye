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
 * Integração completa com InfinitePay.
 *
 * Documentação: https://developers.infinitepay.io
 * Autenticação: Authorization: Bearer TOKEN
 * Nota: InfinitePay não tem suporte nativo a assinaturas recorrentes.
 *       A recorrência é gerenciada localmente — apenas cobranças avulsas via API.
 */
class InfinitePayGateway extends AbstractHttpGateway
{
    public function code(): string
    {
        return 'infinitepay';
    }

    // ── Clientes ─────────────────────────────────────────────────────────────

    /**
     * InfinitePay não tem endpoint de customers separado.
     * O customer é embutido na cobrança.
     * Retorna entityId como identificador local.
     */
    public function upsertCustomer(CustomerDTO $customer): string
    {
        return $customer->entityId;
    }

    // ── Assinaturas (gerenciadas localmente) ─────────────────────────────────

    /**
     * InfinitePay não suporta assinaturas recorrentes via API.
     * O sistema gerencia o ciclo localmente e emite cobranças avulsas no vencimento.
     */
    public function createSubscription(CreateSubscriptionDTO $payload): CreateSubscriptionResultDTO
    {
        return new CreateSubscriptionResultDTO(
            success: true,
            externalSubscriptionId: null,
            externalCustomerId: $payload->customerId,
            status: 'active',
            rawResponse: ['note' => 'InfinitePay does not support subscriptions. Renewal managed locally.'],
        );
    }

    /**
     * Sem assinatura no gateway para cancelar.
     */
    public function cancelSubscription(CancelSubscriptionDTO $payload): CancelSubscriptionResultDTO
    {
        return new CancelSubscriptionResultDTO(
            success: true,
            status: 'cancelled',
            rawResponse: ['note' => 'InfinitePay has no subscription to cancel.'],
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

        $json   = $response->json();
        $status = $this->normalizeInfinitePayStatus($json['status'] ?? '');

        return new CreateChargeResultDTO(
            success: true,
            externalPaymentId: (string) ($json['id'] ?? ''),
            status: $status,
            amount: isset($json['amount']) ? (float) $json['amount'] : null,
            rawResponse: $this->sanitizePayload($json ?? []),
        );
    }

    protected function buildChargePayload(CreateChargeDTO $payload): array
    {
        return array_filter([
            'amount'        => $payload->amount,
            'description'   => $payload->description,
            'payment_type'  => $this->mapPaymentType($payload->paymentMethod),
            'customer'      => array_filter([
                'name'     => $payload->metadata['customer_name'] ?? null,
                'email'    => $payload->metadata['email'] ?? null,
                'document' => preg_replace('/\D/', '', (string) ($payload->metadata['document'] ?? '')) ?: null,
            ]),
            'due_date'      => $payload->dueDate ?? now()->addDays(3)->format('Y-m-d'),
            'external_id'   => $payload->invoiceId,
            'metadata'      => [
                'invoice_id'      => $payload->invoiceId,
                'subscription_id' => $payload->subscriptionId,
            ],
        ]);
    }

    // ── Webhooks ─────────────────────────────────────────────────────────────

    public function validateWebhookSignature(GatewayWebhookInputDTO $payload): bool
    {
        $secret = $this->resolveWebhookSecret();

        if ($secret === null || $secret === '') {
            return true;
        }

        $provided = $payload->headers['x-infinitepay-signature']
            ?? $payload->headers['X-InfinitePay-Signature']
            ?? $payload->headers['x-signature']
            ?? '';

        if (! is_string($provided) || $provided === '') {
            return false;
        }

        $computed = hash_hmac('sha256', $payload->body, $secret);

        return hash_equals($computed, trim($provided));
    }

    public function parseWebhook(GatewayWebhookInputDTO $payload): NormalizedWebhookEventDTO
    {
        $eventType = (string) ($payload->payload['event'] ?? $payload->payload['type'] ?? 'unknown');
        $data      = $payload->payload['data'] ?? $payload->payload;

        $normalizedType    = $this->normalizeEventType($eventType);
        $externalPaymentId = $data['id'] ?? null;
        $status            = $this->normalizeInfinitePayStatus($data['status'] ?? '');
        $amount            = isset($data['amount']) ? (float) $data['amount'] : null;
        $externalRef       = $data['external_id'] ?? null;

        $metadata = [];
        if ($externalRef) {
            $metadata['invoice_id'] = $externalRef;
        }

        return new NormalizedWebhookEventDTO(
            gatewayCode: $payload->gatewayCode,
            eventType: $normalizedType,
            externalEventId: $payload->externalEventId,
            externalSubscriptionId: null,
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

    protected function eventTypeMap(): array
    {
        return [
            'charge.approved'         => 'paid',
            'charge.declined'         => 'failed',
            'charge.refunded'         => 'refunded',
            'charge.chargeback'       => 'chargeback',
            'charge.cancelled'        => 'cancelled',
            'payment.approved'        => 'paid',
            'payment.declined'        => 'failed',
            'payment.refunded'        => 'refunded',
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function normalizeInfinitePayStatus(string $status): string
    {
        return match (strtolower($status)) {
            'approved', 'paid', 'captured'  => 'paid',
            'pending', 'processing'         => 'pending',
            'declined', 'failed', 'refused' => 'failed',
            'refunded', 'reversed'          => 'refunded',
            'chargeback'                    => 'chargeback',
            'cancelled', 'canceled'         => 'cancelled',
            default                         => strtolower($status),
        };
    }

    private function mapPaymentType(?string $method): string
    {
        return match (strtolower((string) $method)) {
            'pix'         => 'pix',
            'credit_card' => 'credit',
            default       => 'pix',
        };
    }
}
