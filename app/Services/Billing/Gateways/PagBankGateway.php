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
 * Integração completa com PagBank (PagSeguro).
 *
 * Documentação: https://dev.pagbank.uol.com.br/reference
 * Autenticação: Authorization: Bearer TOKEN
 * Webhook: validação via token de acesso configurado
 */
class PagBankGateway extends AbstractHttpGateway
{
    public function code(): string
    {
        return 'pagbank';
    }

    // ── Clientes ─────────────────────────────────────────────────────────────

    /**
     * PagBank não tem endpoint de customers — o comprador é embutido no pedido/assinatura.
     * Retorna o entityId como identificador local.
     */
    public function upsertCustomer(CustomerDTO $customer): string
    {
        return $customer->entityId;
    }

    // ── Assinaturas (Pre-approval) ────────────────────────────────────────────

    public function createSubscription(CreateSubscriptionDTO $payload): CreateSubscriptionResultDTO
    {
        $planId = $payload->metadata['pagbank_plan_id'] ?? null;

        if (! $planId) {
            return new CreateSubscriptionResultDTO(
                success: true,
                externalSubscriptionId: null,
                externalCustomerId: $payload->customerId,
                status: 'active',
                rawResponse: ['note' => 'managed_locally'],
            );
        }

        $response = $this->post('subscriptions', [
            'plan'        => ['id' => $planId],
            'reference_id' => $payload->subscriptionId,
            'customer'    => [
                'name'          => $payload->metadata['customer_name'] ?? '',
                'email'         => $payload->metadata['email'] ?? '',
                'tax_id'        => preg_replace('/\D/', '', (string) ($payload->metadata['document'] ?? '')),
                'phone'         => [['country' => '55', 'area' => substr(preg_replace('/\D/', '', (string) ($payload->metadata['phone'] ?? '')), 0, 2), 'number' => substr(preg_replace('/\D/', '', (string) ($payload->metadata['phone'] ?? '')), 2)]],
            ],
            'payment_method' => [[
                'type' => 'CREDIT_CARD',
            ]],
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
            externalCustomerId: $payload->customerId,
            status: $this->normalizePagBankStatus($json['status'] ?? ''),
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
        $charges = $json['charges'] ?? [];
        $first   = $charges[0] ?? [];

        return new CreateChargeResultDTO(
            success: true,
            externalPaymentId: (string) ($json['id'] ?? ''),
            status: $this->normalizePagBankChargeStatus($first['status'] ?? $json['status'] ?? ''),
            amount: isset($json['amount']['value']) ? ((float) $json['amount']['value']) / 100 : null,
            rawResponse: $this->sanitizePayload($json ?? []),
        );
    }

    protected function buildChargePayload(CreateChargeDTO $payload): array
    {
        $amountCents = (int) round($payload->amount * 100);

        return [
            'reference_id'    => $payload->invoiceId,
            'customer'        => [
                'name'  => $payload->metadata['customer_name'] ?? 'Cliente',
                'email' => $payload->metadata['email'] ?? '',
                'tax_id' => preg_replace('/\D/', '', (string) ($payload->metadata['document'] ?? '')),
            ],
            'items'           => [[
                'name'      => $payload->description,
                'quantity'  => 1,
                'unit_amount' => $amountCents,
            ]],
            'charges'         => [[
                'reference_id'   => $payload->invoiceId,
                'description'    => $payload->description,
                'amount'         => ['value' => $amountCents, 'currency' => 'BRL'],
                'payment_method' => [
                    'type' => match (strtolower((string) $payload->paymentMethod)) {
                        'pix'   => 'PIX',
                        default => 'BOLETO',
                    },
                    'boleto' => $payload->paymentMethod !== 'pix' ? [
                        'due_date'     => $payload->dueDate ?? now()->addDays(3)->format('Y-m-d'),
                        'instruction_lines' => ['line_1' => "Pagamento: {$payload->description}"],
                    ] : null,
                    'pix' => $payload->paymentMethod === 'pix' ? ['expires_in' => 3600 * 24] : null,
                ],
            ]],
        ];
    }

    // ── Webhooks ─────────────────────────────────────────────────────────────

    /**
     * PagBank pode usar notificação via token de acesso no header Authorization.
     * Validamos que o token recebido bate com o configurado.
     */
    public function validateWebhookSignature(GatewayWebhookInputDTO $payload): bool
    {
        $secret = $this->resolveWebhookSecret();

        if ($secret === null || $secret === '') {
            return true;
        }

        // PagBank envia o token de autorização como Bearer no header
        $authHeader = $payload->headers['authorization']
            ?? $payload->headers['Authorization']
            ?? '';

        $provided = str_starts_with($authHeader, 'Bearer ')
            ? substr($authHeader, 7)
            : $authHeader;

        if ($provided === '') {
            return false;
        }

        return hash_equals($secret, $provided);
    }

    public function parseWebhook(GatewayWebhookInputDTO $payload): NormalizedWebhookEventDTO
    {
        $eventType = (string) ($payload->payload['type'] ?? 'unknown');
        $data      = $payload->payload['data'] ?? $payload->payload;

        $normalizedType         = $this->normalizeEventType($eventType);
        $externalPaymentId      = $data['id'] ?? null;
        $externalSubscriptionId = $data['subscription_id'] ?? null;
        $charges                = $data['charges'] ?? [];
        $firstCharge            = $charges[0] ?? [];
        $status                 = $this->normalizePagBankChargeStatus($firstCharge['status'] ?? $data['status'] ?? '');
        $amountValue            = $data['amount']['value'] ?? $firstCharge['amount']['value'] ?? null;
        $amount                 = $amountValue !== null ? ((float) $amountValue) / 100 : null;

        return new NormalizedWebhookEventDTO(
            gatewayCode: $payload->gatewayCode,
            eventType: $normalizedType,
            externalEventId: $payload->externalEventId,
            externalSubscriptionId: is_string($externalSubscriptionId) ? $externalSubscriptionId : null,
            externalPaymentId: is_string($externalPaymentId) ? $externalPaymentId : null,
            externalInvoiceId: $data['reference_id'] ?? null,
            status: $status,
            amount: $amount,
            currency: 'BRL',
            metadata: [],
            rawPayload: $payload->payload,
            occurredAt: now()->toIso8601String(),
        );
    }

    protected function eventTypeMap(): array
    {
        return [
            'PAYMENT_AUTHORIZED'             => 'authorized',
            'PAYMENT_APPROVED'               => 'paid',
            'PAYMENT_DECLINED'               => 'failed',
            'PAYMENT_CANCELLED'              => 'cancelled',
            'PAYMENT_REFUNDED'               => 'refunded',
            'PAYMENT_CHARGEBACK'             => 'chargeback',
            'SUBSCRIPTION_APPROVED'          => 'unknown',
            'SUBSCRIPTION_DEACTIVATED'       => 'cancelled',
            'ORDER_PAID'                     => 'paid',
            'ORDER_AUTHORIZED'               => 'authorized',
            'CHARGE_PAID'                    => 'paid',
            'CHARGE_DECLINED'                => 'failed',
            'order.paid'                     => 'paid',
            'order.authorized'               => 'authorized',
            'charge.paid'                    => 'paid',
            'charge.declined'                => 'failed',
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function normalizePagBankStatus(string $status): string
    {
        return match (strtoupper($status)) {
            'ACTIVE', 'TRIAL'   => 'active',
            'CANCELED'          => 'cancelled',
            'SUSPENDED'         => 'past_due',
            'PENDING'           => 'pending',
            default             => strtolower($status),
        };
    }

    private function normalizePagBankChargeStatus(string $status): string
    {
        return match (strtoupper($status)) {
            'PAID', 'AUTHORIZED'  => 'paid',
            'DECLINED', 'CANCELED' => 'failed',
            'IN_ANALYSIS', 'WAITING' => 'pending',
            'REFUNDED'             => 'refunded',
            'CHARGEBACK'           => 'chargeback',
            default                => strtolower($status),
        };
    }
}
