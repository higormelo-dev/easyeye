<?php

namespace App\Services\Billing\Gateways;

use App\Contracts\Billing\PaymentGatewayInterface;
use App\DTOs\Billing\{
    CancelSubscriptionDTO,
    CancelSubscriptionResultDTO,
    CreateChargeDTO,
    CreateChargeResultDTO,
    CreateSubscriptionDTO,
    CreateSubscriptionResultDTO,
    CustomerDTO,
    GatewayCallContext,
    GatewayHealthDTO,
    GatewayWebhookInputDTO,
    NormalizedWebhookEventDTO,
};
use App\Exceptions\Billing\GatewayIntegrationException;
use App\Services\Billing\GatewayCredentialResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\{Arr, Str};
use Illuminate\Support\Facades\Http;

abstract class AbstractHttpGateway implements PaymentGatewayInterface
{
    private ?string $correlationId = null;

    private ?string $entityId = null;

    public function __construct(
        protected readonly GatewayCredentialResolver $credentialResolver,
    ) {
    }

    // ── Contexto de chamada ──────────────────────────────────────────────────

    public function withContext(GatewayCallContext $context): static
    {
        $clone = clone $this;
        $clone->correlationId = $context->correlationId;
        $clone->entityId      = $context->entityId;

        return $clone;
    }

    // ── Interface pública — cada subclasse deve implementar ──────────────────

    abstract public function code(): string;

    // ── Operações de gateway — implementações base sobrescrevíveis ───────────

    public function upsertCustomer(CustomerDTO $customer): string
    {
        $payload  = $this->buildCustomerPayload($customer);
        $response = $this->post('customers', $payload);

        if (! $response->successful()) {
            throw GatewayIntegrationException::fromHttpStatus($this->code(), $response->status(), $response->body());
        }

        return (string) $this->extractString($response->json(), ['id', 'customer.id', 'data.id']);
    }

    public function createSubscription(CreateSubscriptionDTO $payload): CreateSubscriptionResultDTO
    {
        $body     = $this->buildSubscriptionPayload($payload);
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
            externalSubscriptionId: $this->extractString($json, ['id', 'subscription.id', 'data.id']),
            externalCustomerId: $this->extractString($json, ['customer', 'customer_id', 'data.customer']),
            status: $this->normalizeStatus($this->extractString($json, ['status', 'data.status'])),
            rawResponse: $json ?? [],
        );
    }

    public function cancelSubscription(CancelSubscriptionDTO $payload): CancelSubscriptionResultDTO
    {
        $response = $this->request(
            method: $this->cancelMethod(),
            endpointKey: 'subscription_cancel',
            payload: $this->buildCancelSubscriptionPayload($payload),
            replacements: ['id' => $payload->externalSubscriptionId],
        );

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
            status: $this->normalizeStatus($this->extractString($response->json(), ['status', 'data.status'])),
            rawResponse: $response->json() ?? [],
        );
    }

    public function createCharge(CreateChargeDTO $payload): CreateChargeResultDTO
    {
        $body     = $this->buildChargePayload($payload);
        $response = $this->request(
            method: 'POST',
            endpointKey: 'charges',
            payload: $body,
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
            externalPaymentId: $this->extractString($json, ['id', 'payment.id', 'data.id']),
            status: $this->normalizeStatus($this->extractString($json, ['status', 'data.status'])),
            amount: $this->extractFloat($json, ['amount', 'value', 'data.amount']),
            rawResponse: $this->sanitizePayload($json ?? []),
        );
    }

    public function fetchPayment(string $externalPaymentId): array
    {
        $response = $this->request('GET', 'payments', [], ['id' => $externalPaymentId]);

        if (! $response->successful()) {
            throw GatewayIntegrationException::fromHttpStatus($this->code(), $response->status(), $response->body());
        }

        return $this->sanitizePayload($response->json() ?? []);
    }

    /**
     * Cada gateway DEVE sobrescrever este método com o parser específico.
     * O base implementa um parser genérico que provavelmente não funcionará
     * para gateways reais — serve apenas como fallback.
     */
    public function parseWebhook(GatewayWebhookInputDTO $payload): NormalizedWebhookEventDTO
    {
        $rawEventType = (string) ($this->extractString($payload->payload, $this->eventTypeKeyPaths()) ?? 'unknown');
        $normalizedType = $this->normalizeEventType($rawEventType);

        return new NormalizedWebhookEventDTO(
            gatewayCode: $payload->gatewayCode,
            eventType: $normalizedType,
            externalEventId: $payload->externalEventId
                ?? $this->extractString($payload->payload, ['id', 'event_id', 'data.id']),
            externalSubscriptionId: $this->extractString($payload->payload, [
                'subscription_id', 'data.subscription', 'data.subscription_id', 'subscription.id',
            ]),
            externalPaymentId: $this->extractString($payload->payload, [
                'payment_id', 'data.payment', 'data.payment_id', 'payment.id',
            ]),
            externalInvoiceId: $this->extractString($payload->payload, [
                'invoice_id', 'data.invoice', 'data.invoice_id', 'invoice.id',
            ]),
            status: $this->normalizeStatus($this->extractString($payload->payload, ['status', 'data.status'])),
            amount: $this->extractFloat($payload->payload, ['amount', 'value', 'data.amount']),
            currency: $this->extractString($payload->payload, ['currency', 'data.currency']) ?? 'BRL',
            metadata: $payload->payload['metadata'] ?? [],
            rawPayload: $payload->payload,
            occurredAt: now()->toIso8601String(),
        );
    }

    /**
     * Cada gateway DEVE sobrescrever com o algoritmo de validação correto.
     */
    public function validateWebhookSignature(GatewayWebhookInputDTO $payload): bool
    {
        $secret = $this->resolveWebhookSecret();

        // Sem secret configurado: aceita qualquer webhook (ambiente de desenvolvimento)
        if ($secret === '' || $secret === null) {
            return true;
        }

        $provided = $payload->signature
            ?? Arr::get($payload->headers, 'x-signature')
            ?? Arr::get($payload->headers, 'X-Signature')
            ?? Arr::get($payload->headers, 'x-hub-signature');

        if (! is_string($provided) || $provided === '') {
            return false;
        }

        $computed = hash_hmac('sha256', $payload->body, $secret);

        return hash_equals($computed, trim($provided));
    }

    public function healthCheck(): GatewayHealthDTO
    {
        $baseUrl = (string) $this->gatewayConfig('base_url');
        $secret  = $this->resolveSecret();

        if ($baseUrl === '' || $secret === null || $secret === '') {
            return new GatewayHealthDTO(
                healthy: false,
                message: "Gateway {$this->code()} sem configuração mínima (base_url ou secret ausente).",
            );
        }

        return new GatewayHealthDTO(
            healthy: true,
            message: "Gateway {$this->code()} configurado.",
        );
    }

    // ── Métodos sobrescrevíveis pelos gateways concretos ────────────────────

    protected function cancelMethod(): string
    {
        return 'DELETE';
    }

    /** Retorna os headers de autenticação para o gateway. Sobrescrever conforme necessário. */
    protected function authHeaders(): array
    {
        $secret = $this->resolveSecret();

        return [
            'Authorization' => 'Bearer ' . $secret,
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
        ];
    }

    /**
     * Mapa de tipos de evento nativos do gateway para tipos normalizados internos.
     * Tipos normalizados: paid | failed | cancelled | refunded | chargeback | authorized | unknown
     */
    protected function eventTypeMap(): array
    {
        return [];
    }

    /** Paths para extrair o tipo de evento do payload do webhook. */
    protected function eventTypeKeyPaths(): array
    {
        return ['event', 'type', 'name', 'data.type'];
    }

    /**
     * Transforma o payload do CustomerDTO para o formato esperado pelo gateway.
     * Sobrescrever em cada gateway concreto.
     */
    protected function buildCustomerPayload(CustomerDTO $customer): array
    {
        return $customer->toArray();
    }

    /**
     * Transforma o payload do CreateSubscriptionDTO para o formato esperado pelo gateway.
     * Sobrescrever em cada gateway concreto.
     */
    protected function buildSubscriptionPayload(CreateSubscriptionDTO $payload): array
    {
        return $payload->toArray();
    }

    /**
     * Transforma o payload do CreateChargeDTO para o formato esperado pelo gateway.
     * Sobrescrever em cada gateway concreto.
     */
    protected function buildChargePayload(CreateChargeDTO $payload): array
    {
        return $payload->toArray();
    }

    /**
     * Payload de cancelamento de assinatura.
     * Sobrescrever em cada gateway concreto se necessário.
     */
    protected function buildCancelSubscriptionPayload(CancelSubscriptionDTO $payload): array
    {
        return [];
    }

    // ── Infraestrutura HTTP ──────────────────────────────────────────────────

    /**
     * Executa uma requisição HTTP ao gateway.
     * Diferencia timeout (ConnectionException) de erro HTTP para circuit breaker.
     */
    protected function request(
        string $method,
        string $endpointKey,
        array $payload = [],
        array $replacements = [],
        ?string $idempotencyKey = null,
    ): Response {
        $url = $this->buildEndpoint($endpointKey, $replacements);

        try {
            $client = Http::timeout(30)
                ->withHeaders($this->authHeaders())
                ->withHeaders($this->extraHeaders($idempotencyKey));

            return match (strtoupper($method)) {
                'GET'    => $client->get($url, $payload),
                'DELETE' => $client->delete($url, $payload),
                'PATCH'  => $client->patch($url, $payload),
                'PUT'    => $client->put($url, $payload),
                default  => $client->post($url, $payload),
            };
        } catch (ConnectionException $e) {
            throw GatewayIntegrationException::timeout($this->code(), $e->getMessage());
        }
    }

    protected function post(string $endpointKey, array $payload = [], array $replacements = []): Response
    {
        return $this->request('POST', $endpointKey, $payload, $replacements);
    }

    protected function get(string $endpointKey, array $query = [], array $replacements = []): Response
    {
        return $this->request('GET', $endpointKey, $query, $replacements);
    }

    private function extraHeaders(?string $idempotencyKey): array
    {
        $headers = [];

        if ($idempotencyKey) {
            $headers['X-Idempotency-Key'] = $idempotencyKey;
        }

        if ($this->correlationId) {
            $headers['X-Correlation-Id'] = $this->correlationId;
        }

        return $headers;
    }

    // ── Resolução de credenciais ─────────────────────────────────────────────

    protected function resolveSecret(): ?string
    {
        return $this->credentialResolver->resolveSecret($this->code(), $this->entityId)
            ?: ((string) $this->gatewayConfig('secret') ?: null);
    }

    protected function resolveWebhookSecret(): ?string
    {
        return $this->credentialResolver->resolveWebhookSecret($this->code(), $this->entityId)
            ?: ((string) $this->gatewayConfig('webhook_secret') ?: null);
    }

    protected function resolveExtraCredential(string $key): ?string
    {
        return $this->credentialResolver->resolveExtra($this->code(), $key, $this->entityId)
            ?: ((string) $this->gatewayConfig($key) ?: null);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    protected function gatewayConfig(?string $key = null): mixed
    {
        $config = config('billing.gateways.' . $this->code(), []);

        if ($key === null) {
            return $config;
        }

        return Arr::get($config, $key);
    }

    protected function buildEndpoint(string $endpointKey, array $replacements = []): string
    {
        $baseUrl  = rtrim((string) $this->gatewayConfig('base_url'), '/');
        $endpoint = (string) Arr::get($this->gatewayConfig('endpoints'), $endpointKey, '');

        foreach ($replacements as $key => $value) {
            $endpoint = str_replace('{' . $key . '}', (string) $value, $endpoint);
        }

        return $baseUrl . $endpoint;
    }

    protected function extractString(array $payload, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = Arr::get($payload, $path);

            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    protected function extractFloat(array $payload, array $paths): ?float
    {
        foreach ($paths as $path) {
            $value = Arr::get($payload, $path);

            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }

    protected function normalizeStatus(?string $status): ?string
    {
        if (! $status) {
            return null;
        }

        return Str::of($status)->lower()->replace('-', '_')->toString();
    }

    /**
     * Converte um tipo de evento nativo do gateway para o tipo normalizado interno.
     * Tipos: paid | failed | cancelled | refunded | chargeback | authorized | unknown
     */
    protected function normalizeEventType(string $rawType): string
    {
        $map = $this->eventTypeMap();

        $rawUpper = strtoupper($rawType);
        $rawLower = strtolower($rawType);

        return $map[$rawType]
            ?? $map[$rawUpper]
            ?? $map[$rawLower]
            ?? 'unknown';
    }

    /**
     * Remove campos sensíveis de payloads antes de persistir.
     */
    protected function sanitizePayload(array $payload): array
    {
        $sensitiveKeys = ['card_number', 'cvv', 'card_cvv', 'card_token', 'password', 'secret', 'access_token'];

        array_walk_recursive($payload, function (&$value, $key) use ($sensitiveKeys): void {
            if (in_array(strtolower((string) $key), $sensitiveKeys, true)) {
                $value = '***REDACTED***';
            }
        });

        return $payload;
    }
}
