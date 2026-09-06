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
 * Integração completa com Stripe (Brasil).
 *
 * Documentação: https://stripe.com/docs/api
 * Autenticação: Authorization: Bearer sk_...
 * Webhook: Stripe-Signature: t=timestamp,v1=hash
 */
class StripeBrGateway extends AbstractHttpGateway
{
    public function code(): string
    {
        return 'stripe_br';
    }

    // ── Clientes ─────────────────────────────────────────────────────────────

    public function upsertCustomer(CustomerDTO $customer): string
    {
        if ($customer->email) {
            $search = $this->get('customer_search', ['query' => "email:'{$customer->email}'"]);

            if ($search->successful()) {
                $data = $search->json('data', []);

                if (! empty($data)) {
                    return (string) ($data[0]['id'] ?? '');
                }
            }
        }

        $response = $this->post('customers', $this->buildCustomerPayload($customer));

        if (! $response->successful()) {
            throw GatewayIntegrationException::fromHttpStatus($this->code(), $response->status(), $response->body());
        }

        return (string) $response->json('id');
    }

    protected function buildCustomerPayload(CustomerDTO $customer): array
    {
        return array_filter([
            'name'     => $customer->name,
            'email'    => $customer->email,
            'phone'    => preg_replace('/\D/', '', (string) $customer->phone) ?: null,
            'metadata' => array_filter([
                'entity_id' => $customer->entityId,
                'cpf_cnpj'  => preg_replace('/\D/', '', (string) $customer->document),
            ]),
        ]);
    }

    // ── Assinaturas ──────────────────────────────────────────────────────────

    public function createSubscription(CreateSubscriptionDTO $payload): CreateSubscriptionResultDTO
    {
        $priceId = $payload->metadata['stripe_price_id'] ?? null;

        // Sem price_id configurado: gerencia renovação localmente
        if (! $priceId) {
            return new CreateSubscriptionResultDTO(
                success: true,
                externalSubscriptionId: null,
                externalCustomerId: $payload->customerId,
                status: 'active',
                rawResponse: ['note' => 'managed_locally'],
            );
        }

        $response = $this->post('subscriptions', [
            'customer'         => $payload->customerId,
            'items'            => [['price' => $priceId]],
            'payment_behavior' => 'default_incomplete',
            'expand'           => ['latest_invoice.payment_intent'],
            'metadata'         => [
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
            externalCustomerId: (string) ($json['customer'] ?? $payload->customerId),
            status: $this->normalizeSubStatus($json['status'] ?? ''),
            rawResponse: $json ?? [],
        );
    }

    public function cancelSubscription(CancelSubscriptionDTO $payload): CancelSubscriptionResultDTO
    {
        if (! $payload->externalSubscriptionId) {
            return new CancelSubscriptionResultDTO(success: true, status: 'cancelled', rawResponse: []);
        }

        $response = $this->request('DELETE', 'subscription_cancel', [], ['id' => $payload->externalSubscriptionId]);

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
            status: $this->normalizePiStatus($json['status'] ?? ''),
            amount: isset($json['amount']) ? ((float) $json['amount']) / 100 : null,
            rawResponse: $this->sanitizePayload($json ?? []),
        );
    }

    protected function buildChargePayload(CreateChargeDTO $payload): array
    {
        return array_filter([
            'amount'                    => (int) round($payload->amount * 100),
            'currency'                  => strtolower($payload->currency),
            'customer'                  => $payload->customerId,
            'description'               => $payload->description,
            'confirm'                   => false,
            'automatic_payment_methods' => ['enabled' => true],
            'metadata'                  => array_merge($payload->metadata, [
                'invoice_id'      => $payload->invoiceId,
                'subscription_id' => $payload->subscriptionId,
                'entity_id'       => $payload->entityId,
            ]),
        ]);
    }

    // ── Webhooks ─────────────────────────────────────────────────────────────

    public function validateWebhookSignature(GatewayWebhookInputDTO $payload): bool
    {
        $secret = $this->resolveWebhookSecret();

        // BUGFIX (revisao de seguranca): sem webhook_secret configurado não é possível
        // validar a assinatura, então falha FECHADO (rejeita) — aceitar sem verificação
        // permitia forjar webhooks e mutar assinaturas sem autenticação.
        if ($secret === null || $secret === '') {
            return false;
        }

        $sigHeader = $payload->headers['stripe-signature']
            ?? $payload->headers['Stripe-Signature']
            ?? '';

        if (! $sigHeader) {
            return false;
        }

        // Parseia t=timestamp,v1=hash
        $parts = [];

        foreach (explode(',', $sigHeader) as $part) {
            [$k, $v]     = array_pad(explode('=', $part, 2), 2, '');
            $parts[$k][] = $v;
        }

        $timestamp = (int) ($parts['t'][0] ?? 0);

        if ($timestamp === 0 || abs(time() - $timestamp) > 300) {
            return false;
        }

        $signedPayload = $timestamp . '.' . $payload->body;
        $computed      = hash_hmac('sha256', $signedPayload, $secret);

        foreach ($parts['v1'] ?? [] as $v1) {
            if (hash_equals($computed, $v1)) {
                return true;
            }
        }

        return false;
    }

    public function parseWebhook(GatewayWebhookInputDTO $payload): NormalizedWebhookEventDTO
    {
        $eventType = (string) ($payload->payload['type'] ?? 'unknown');
        $obj       = $payload->payload['data']['object'] ?? [];

        $normalizedType         = $this->normalizeEventType($eventType);
        $externalPaymentId      = $obj['id'] ?? null;
        $externalSubscriptionId = $obj['subscription'] ?? null;
        $metadata               = $obj['metadata'] ?? [];

        // Para invoice events: subscription vem do objeto, payment_intent é o payment
        if (str_starts_with($eventType, 'invoice.')) {
            $externalSubscriptionId = $obj['subscription'] ?? null;
            $externalPaymentId      = $obj['payment_intent'] ?? null;
        }

        // Para subscription events
        if (str_starts_with($eventType, 'customer.subscription.')) {
            $externalSubscriptionId = $obj['id'] ?? null;
            $externalPaymentId      = null;
        }

        $amount = isset($obj['amount']) ? ((float) $obj['amount']) / 100
            : (isset($obj['amount_paid']) ? ((float) $obj['amount_paid']) / 100 : null);

        return new NormalizedWebhookEventDTO(
            gatewayCode: $payload->gatewayCode,
            eventType: $normalizedType,
            externalEventId: $payload->payload['id'] ?? null,
            externalSubscriptionId: is_string($externalSubscriptionId) ? $externalSubscriptionId : null,
            externalPaymentId: is_string($externalPaymentId) ? $externalPaymentId : null,
            externalInvoiceId: null,
            status: $this->normalizePiStatus($obj['status'] ?? ''),
            amount: $amount,
            currency: strtoupper($obj['currency'] ?? 'BRL'),
            metadata: $metadata,
            rawPayload: $payload->payload,
            occurredAt: now()->toIso8601String(),
        );
    }

    protected function eventTypeMap(): array
    {
        return [
            'payment_intent.succeeded'      => 'paid',
            'payment_intent.payment_failed' => 'failed',
            'payment_intent.canceled'       => 'cancelled',
            'invoice.paid'                  => 'paid',
            'invoice.payment_succeeded'     => 'paid',
            'invoice.payment_failed'        => 'failed',
            'charge.succeeded'              => 'paid',
            'charge.failed'                 => 'failed',
            'charge.refunded'               => 'refunded',
            'charge.dispute.created'        => 'chargeback',
            'customer.subscription.deleted' => 'cancelled',
            'customer.subscription.paused'  => 'cancelled',
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function normalizePiStatus(string $status): string
    {
        return match ($status) {
            'succeeded' => 'paid',
            'requires_payment_method', 'requires_confirmation',
            'requires_action', 'processing' => 'pending',
            'canceled' => 'cancelled',
            default    => strtolower($status),
        };
    }

    private function normalizeSubStatus(string $status): string
    {
        return match ($status) {
            'active', 'trialing' => 'active',
            'past_due', 'unpaid' => 'past_due',
            'canceled', 'paused' => 'cancelled',
            'incomplete' => 'pending',
            default      => strtolower($status),
        };
    }
}
