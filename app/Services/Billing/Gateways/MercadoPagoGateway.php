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
use Carbon\Carbon;

/**
 * Integração completa com Mercado Pago.
 *
 * Documentação: https://www.mercadopago.com.br/developers/pt/reference
 * Autenticação: Authorization: Bearer ACCESS_TOKEN
 * Webhook: x-signature: ts=timestamp,v1=hash
 *   Hash: HMAC-SHA256 de "id:{data.id};request-id:{x-request-id};ts:{timestamp}"
 */
class MercadoPagoGateway extends AbstractHttpGateway
{
    public function code(): string
    {
        return 'mercadopago';
    }

    // ── Clientes ─────────────────────────────────────────────────────────────

    public function upsertCustomer(CustomerDTO $customer): string
    {
        if ($customer->email) {
            $search = $this->get('customer_search', ['email' => $customer->email]);

            if ($search->successful()) {
                $results = $search->json('results', []);

                if (! empty($results)) {
                    return (string) ($results[0]['id'] ?? '');
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
            'email'          => $customer->email,
            'first_name'     => $customer->name,
            'identification' => array_filter([
                'type'   => strlen(preg_replace('/\D/', '', (string) $customer->document)) === 11 ? 'CPF' : 'CNPJ',
                'number' => preg_replace('/\D/', '', (string) $customer->document) ?: null,
            ]),
            'metadata' => ['entity_id' => $customer->entityId],
        ]);
    }

    // ── Assinaturas (Pre-approval) ────────────────────────────────────────────

    public function createSubscription(CreateSubscriptionDTO $payload): CreateSubscriptionResultDTO
    {
        $planId = $payload->metadata['mercadopago_plan_id'] ?? null;

        $body = $planId
            ? $this->buildManagedSubscriptionPayload($payload, $planId)
            : $this->buildLocalSubscriptionPayload($payload);

        $response = $this->post('subscriptions', $body);

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
            externalCustomerId: $payload->customerId,
            status: $this->normalizeMpStatus($json['status'] ?? ''),
            rawResponse: $json ?? [],
        );
    }

    private function buildManagedSubscriptionPayload(CreateSubscriptionDTO $payload, string $planId): array
    {
        return [
            'preapproval_plan_id' => $planId,
            'payer_email'         => $payload->metadata['email'] ?? '',
            'external_reference'  => $payload->subscriptionId,
            'back_url'            => config('app.url'),
            'auto_recurring'      => [
                'start_date' => now()->toIso8601String(),
                'end_date'   => now()->addYears(10)->toIso8601String(),
            ],
        ];
    }

    private function buildLocalSubscriptionPayload(CreateSubscriptionDTO $payload): array
    {
        return [
            'reason'             => $payload->description ?? "Assinatura {$payload->planId}",
            'external_reference' => $payload->subscriptionId,
            'payer_email'        => $payload->metadata['email'] ?? '',
            'back_url'           => config('app.url'),
            'auto_recurring'     => [
                'frequency'          => $payload->intervalCount ?: 1,
                'frequency_type'     => $payload->interval === 'year' ? 'years' : 'months',
                'transaction_amount' => $payload->amount,
                'currency_id'        => $payload->currency,
                'start_date'         => now()->toIso8601String(),
                'end_date'           => now()->addYears(10)->toIso8601String(),
            ],
        ];
    }

    public function cancelSubscription(CancelSubscriptionDTO $payload): CancelSubscriptionResultDTO
    {
        if (! $payload->externalSubscriptionId) {
            return new CancelSubscriptionResultDTO(success: true, status: 'cancelled', rawResponse: []);
        }

        $response = $this->request('PUT', 'subscription_cancel', ['status' => 'cancelled'], ['id' => $payload->externalSubscriptionId]);

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
        return 'PUT';
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
        $status = $this->normalizeMpPaymentStatus($json['status'] ?? '');

        return new CreateChargeResultDTO(
            success: true,
            externalPaymentId: (string) ($json['id'] ?? ''),
            status: $status,
            amount: isset($json['transaction_amount']) ? (float) $json['transaction_amount'] : null,
            rawResponse: $this->sanitizePayload($json ?? []),
        );
    }

    protected function buildChargePayload(CreateChargeDTO $payload): array
    {
        return array_filter([
            'transaction_amount' => $payload->amount,
            'description'        => $payload->description,
            'payment_method_id'  => $this->mapPaymentMethodId($payload->paymentMethod),
            'payer'              => ['id' => $payload->customerId],
            'external_reference' => $payload->invoiceId,
            'date_of_expiration' => $payload->dueDate
                ? Carbon::parse($payload->dueDate)->endOfDay()->toIso8601String()
                : now()->addDays(3)->endOfDay()->toIso8601String(),
            'metadata' => array_merge($payload->metadata, [
                'invoice_id'      => $payload->invoiceId,
                'subscription_id' => $payload->subscriptionId,
            ]),
        ]);
    }

    // ── Webhooks ─────────────────────────────────────────────────────────────

    /**
     * Mercado Pago assina com:
     * x-signature: ts=timestamp,v1=hash
     * Dados assinados: "id:{data.id};request-id:{x-request-id};ts:{timestamp}"
     */
    public function validateWebhookSignature(GatewayWebhookInputDTO $payload): bool
    {
        $secret = $this->resolveWebhookSecret();

        // BUGFIX (revisao de seguranca): sem webhook_secret configurado não é possível
        // validar a assinatura, então falha FECHADO (rejeita) — aceitar sem verificação
        // permitia forjar webhooks e mutar assinaturas sem autenticação.
        if ($secret === null || $secret === '') {
            return false;
        }

        $sigHeader = $payload->headers['x-signature'] ?? $payload->headers['X-Signature'] ?? '';

        if (! $sigHeader) {
            return false;
        }

        $parts = [];

        foreach (explode(',', $sigHeader) as $part) {
            [$k, $v]         = array_pad(explode('=', $part, 2), 2, '');
            $parts[trim($k)] = trim($v);
        }

        $ts        = $parts['ts'] ?? '';
        $v1        = $parts['v1'] ?? '';
        $requestId = $payload->headers['x-request-id'] ?? $payload->headers['X-Request-Id'] ?? '';
        $dataId    = $payload->payload['data']['id'] ?? '';

        if ($ts === '' || $v1 === '') {
            return false;
        }

        $template = '';

        if ($dataId) {
            $template .= "id:{$dataId};";
        }

        if ($requestId) {
            $template .= "request-id:{$requestId};";
        }
        $template .= "ts:{$ts}";

        $computed = hash_hmac('sha256', $template, $secret);

        return hash_equals($computed, $v1);
    }

    public function parseWebhook(GatewayWebhookInputDTO $payload): NormalizedWebhookEventDTO
    {
        $action = (string) ($payload->payload['action'] ?? $payload->payload['type'] ?? 'unknown');
        $topic  = (string) ($payload->payload['type'] ?? '');
        $dataId = (string) ($payload->payload['data']['id'] ?? '');

        $normalizedType         = $this->normalizeEventType($action);
        $externalPaymentId      = null;
        $externalSubscriptionId = null;
        $amount                 = null;
        $status                 = null;

        if ($topic === 'payment') {
            $externalPaymentId = $dataId;
            $status            = $this->normalizeMpPaymentStatus($payload->payload['data']['status'] ?? '');
            $amount            = isset($payload->payload['data']['transaction_amount'])
                ? (float) $payload->payload['data']['transaction_amount']
                : null;
        } elseif ($topic === 'subscription_preapproval') {
            $externalSubscriptionId = $dataId;
            $status                 = $this->normalizeMpStatus($payload->payload['data']['status'] ?? '');
        }

        return new NormalizedWebhookEventDTO(
            gatewayCode: $payload->gatewayCode,
            eventType: $normalizedType,
            externalEventId: $payload->externalEventId,
            externalSubscriptionId: $externalSubscriptionId,
            externalPaymentId: $externalPaymentId,
            externalInvoiceId: null,
            status: $status,
            amount: $amount,
            currency: 'BRL',
            metadata: ['topic' => $topic, 'data_id' => $dataId],
            rawPayload: $payload->payload,
            occurredAt: now()->toIso8601String(),
        );
    }

    protected function eventTypeMap(): array
    {
        return [
            'payment.created'                  => 'unknown',
            'payment.updated'                  => 'unknown', // status determina o tipo
            'subscription_preapproval.created' => 'unknown',
            'subscription_preapproval.updated' => 'unknown',
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function normalizeMpPaymentStatus(string $status): string
    {
        return match (strtolower($status)) {
            'approved'   => 'paid',
            'authorized' => 'authorized',
            'pending', 'in_process', 'in_mediation' => 'pending',
            'rejected'  => 'failed',
            'cancelled' => 'cancelled',
            'refunded', 'charged_back' => 'refunded',
            default => strtolower($status),
        };
    }

    private function normalizeMpStatus(string $status): string
    {
        return match (strtolower($status)) {
            'authorized', 'active' => 'active',
            'paused'    => 'cancelled',
            'cancelled' => 'cancelled',
            'pending'   => 'pending',
            default     => strtolower($status),
        };
    }

    private function mapPaymentMethodId(?string $method): string
    {
        return match (strtolower((string) $method)) {
            'pix'    => 'pix',
            'boleto' => 'bolbradesco',
            default  => 'pix',
        };
    }
}
