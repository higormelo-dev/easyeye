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
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Integração completa com Pagar.me v5.
 *
 * Documentação: https://docs.pagar.me/reference
 * Autenticação: Basic Auth (api_key: sem senha)
 * Webhook: x-pagarme-signature: sha256=HASH
 */
class PagarmeGateway extends AbstractHttpGateway
{
    public function code(): string
    {
        return 'pagarme';
    }

    // ── Autenticação (Basic Auth) ─────────────────────────────────────────────

    protected function authHeaders(): array
    {
        $apiKey = (string) $this->resolveSecret();
        $encoded = base64_encode($apiKey . ':');

        return [
            'Authorization' => 'Basic ' . $encoded,
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
        ];
    }

    // ── Clientes ─────────────────────────────────────────────────────────────

    public function upsertCustomer(CustomerDTO $customer): string
    {
        $document = preg_replace('/\D/', '', (string) $customer->document);
        $docType  = strlen($document) === 11 ? 'CPF' : 'CNPJ';

        $response = $this->post('customers', $this->buildCustomerPayload($customer));

        if (! $response->successful()) {
            throw GatewayIntegrationException::fromHttpStatus($this->code(), $response->status(), $response->body());
        }

        return (string) $response->json('id');
    }

    protected function buildCustomerPayload(CustomerDTO $customer): array
    {
        $document = preg_replace('/\D/', '', (string) $customer->document);
        $docType  = strlen($document) === 11 ? 'CPF' : 'CNPJ';
        $phone    = preg_replace('/\D/', '', (string) $customer->phone);

        return array_filter([
            'name'     => $customer->name,
            'email'    => $customer->email,
            'type'     => 'individual',
            'document' => $document ?: null,
            'document_type' => $document ? $docType : null,
            'phones'   => $phone ? [
                'mobile_phone' => [
                    'country_code' => '55',
                    'area_code'    => substr($phone, 0, 2),
                    'number'       => substr($phone, 2),
                ],
            ] : null,
            'metadata' => ['entity_id' => $customer->entityId],
        ]);
    }

    // ── Assinaturas ──────────────────────────────────────────────────────────

    public function createSubscription(CreateSubscriptionDTO $payload): CreateSubscriptionResultDTO
    {
        $planId = $payload->metadata['pagarme_plan_id'] ?? null;

        if (! $planId) {
            // Sem plano configurado no Pagar.me: gerencia localmente
            return new CreateSubscriptionResultDTO(
                success: true,
                externalSubscriptionId: null,
                externalCustomerId: $payload->customerId,
                status: 'active',
                rawResponse: ['note' => 'managed_locally'],
            );
        }

        $response = $this->post('subscriptions', [
            'customer_id'    => $payload->customerId,
            'plan_id'        => $planId,
            'payment_method' => 'boleto',
            'metadata'       => [
                'subscription_id' => $payload->subscriptionId,
                'entity_id'       => $payload->entityId,
            ],
        ]);

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
            externalCustomerId: (string) ($json['customer']['id'] ?? $payload->customerId),
            status: $this->normalizePagarmeStatus($json['status'] ?? ''),
            rawResponse: $json ?? [],
        );
    }

    public function cancelSubscription(CancelSubscriptionDTO $payload): CancelSubscriptionResultDTO
    {
        if (! $payload->externalSubscriptionId) {
            return new CancelSubscriptionResultDTO(success: true, status: 'cancelled', rawResponse: []);
        }

        $response = $this->request('POST', 'subscription_cancel', [], ['id' => $payload->externalSubscriptionId]);

        if (! $response->successful()) {
            return new CancelSubscriptionResultDTO(
                success: false,
                status: null,
                rawResponse: $response->json() ?? [],
                errorMessage: mb_substr($response->body(), 0, 1000),
            );
        }

        return new CancelSubscriptionResultDTO(success: true, status: 'cancelled', rawResponse: $response->json() ?? []);
    }

    protected function cancelMethod(): string
    {
        return 'POST';
    }

    // ── Cobranças (Orders) ───────────────────────────────────────────────────

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

        $json    = $response->json();
        $charges = $json['charges'] ?? [$json]; // order pode ter array de charges
        $first   = $charges[0] ?? [];

        return new CreateChargeResultDTO(
            success: true,
            externalPaymentId: (string) ($json['id'] ?? ''),
            status: $this->normalizePagarmeChargeStatus($first['status'] ?? ''),
            amount: isset($json['amount']) ? ((float) $json['amount']) / 100 : null,
            rawResponse: $this->sanitizePayload($json ?? []),
        );
    }

    protected function buildChargePayload(CreateChargeDTO $payload): array
    {
        return [
            'customer_id'     => $payload->customerId,
            'items'           => [[
                'amount'      => (int) round($payload->amount * 100),
                'description' => $payload->description,
                'quantity'    => 1,
                'code'        => $payload->invoiceId,
            ]],
            'payments'        => [[
                'payment_method' => $this->mapPaymentMethod($payload->paymentMethod),
                'boleto'         => $payload->paymentMethod === 'boleto' ? [
                    'due_at'      => $payload->dueDate
                        ? \Carbon\Carbon::parse($payload->dueDate)->endOfDay()->toIso8601String()
                        : now()->addDays(3)->endOfDay()->toIso8601String(),
                    'instructions' => "Pagamento referente a {$payload->description}",
                ] : null,
                'pix'            => $payload->paymentMethod === 'pix' ? [
                    'expires_in' => 3600 * 24,
                ] : null,
            ]],
            'metadata' => array_merge($payload->metadata, [
                'invoice_id'      => $payload->invoiceId,
                'subscription_id' => $payload->subscriptionId,
            ]),
        ];
    }

    // ── Webhooks ─────────────────────────────────────────────────────────────

    /**
     * Pagar.me assina com x-pagarme-signature: sha256=HASH
     * O HASH é HMAC-SHA256 do corpo com a chave de webhook.
     */
    public function validateWebhookSignature(GatewayWebhookInputDTO $payload): bool
    {
        $secret = $this->resolveWebhookSecret();

        if ($secret === null || $secret === '') {
            return true;
        }

        $sigHeader = $payload->headers['x-pagarme-signature']
            ?? $payload->headers['X-Pagarme-Signature']
            ?? '';

        if (! $sigHeader) {
            return false;
        }

        // Formato: sha256=HASH
        $provided = str_starts_with($sigHeader, 'sha256=')
            ? substr($sigHeader, 7)
            : $sigHeader;

        $computed = hash_hmac('sha256', $payload->body, $secret);

        return hash_equals($computed, $provided);
    }

    public function parseWebhook(GatewayWebhookInputDTO $payload): NormalizedWebhookEventDTO
    {
        $type = (string) ($payload->payload['type'] ?? 'unknown');
        $data = $payload->payload['data'] ?? [];

        $normalizedType = $this->normalizeEventType($type);

        $externalPaymentId      = null;
        $externalSubscriptionId = null;
        $amount                 = null;
        $status                 = null;

        if (str_starts_with($type, 'order.')) {
            $externalPaymentId = $data['id'] ?? null;
            $amount            = isset($data['amount']) ? ((float) $data['amount']) / 100 : null;
            $charges           = $data['charges'][0] ?? [];
            $status            = $this->normalizePagarmeChargeStatus($charges['status'] ?? '');
        } elseif (str_starts_with($type, 'subscription.') || str_starts_with($type, 'charge.')) {
            $externalSubscriptionId = $data['subscription_id'] ?? $data['id'] ?? null;
            $externalPaymentId      = $data['id'] ?? null;
            $status                 = $this->normalizePagarmeStatus($data['status'] ?? '');
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
            metadata: $data['metadata'] ?? [],
            rawPayload: $payload->payload,
            occurredAt: now()->toIso8601String(),
        );
    }

    protected function eventTypeMap(): array
    {
        return [
            'order.paid'                       => 'paid',
            'order.payment_failed'             => 'failed',
            'order.canceled'                   => 'cancelled',
            'charge.paid'                      => 'paid',
            'charge.payment_failed'            => 'failed',
            'charge.refunded'                  => 'refunded',
            'charge.chargeback_notification'   => 'chargeback',
            'subscription.deactivated'         => 'cancelled',
            'subscription.activated'           => 'unknown',
            'subscription.payment_failed'      => 'failed',
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function normalizePagarmeStatus(string $status): string
    {
        return match (strtolower($status)) {
            'active', 'paid'   => 'active',
            'canceled'         => 'cancelled',
            'pending'          => 'pending',
            default            => strtolower($status),
        };
    }

    private function normalizePagarmeChargeStatus(string $status): string
    {
        return match (strtolower($status)) {
            'paid', 'overpaid'   => 'paid',
            'pending', 'processing' => 'pending',
            'failed', 'with_error'  => 'failed',
            'refunded'              => 'refunded',
            'chargedback'           => 'chargeback',
            'canceled'              => 'cancelled',
            default                 => strtolower($status),
        };
    }

    private function mapPaymentMethod(?string $method): string
    {
        return match (strtolower((string) $method)) {
            'pix'         => 'pix',
            'credit_card' => 'credit_card',
            default       => 'boleto',
        };
    }
}
