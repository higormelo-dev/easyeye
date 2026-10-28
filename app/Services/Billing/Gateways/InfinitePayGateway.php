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
    GatewayHealthDTO,
    GatewayWebhookInputDTO,
    NormalizedWebhookEventDTO,
};
use App\Exceptions\Billing\GatewayIntegrationException;

/**
 * InfinitePay — Checkout Integrado (link de pagamento).
 *
 * A única API pública da InfinitePay para cobrança online é a do checkout:
 *   POST https://api.checkout.infinitepay.io/links          → {"url": "..."}
 *   POST https://api.checkout.infinitepay.io/payment_check  → {"success", "paid", "amount", ...}
 * e o webhook enviado à webhook_url de cada link, só na aprovação do
 * pagamento. Fontes:
 *   https://www.infinitepay.io/checkout-documentacao
 *   https://ajuda.infinitepay.io/pt-BR/articles/10766888-como-usar-o-checkout-integrado-da-infinitepay
 *
 * Não existem na documentação pública: clientes (/v1/customers),
 * assinaturas/recorrência (/v1/subscriptions), cobranças (/v1/charges) nem
 * consulta por id (/v1/payments/{id}). A recorrência é local
 * (RenewSubscriptionJob): cada ciclo vira um link de pagamento novo, e o
 * cliente escolhe Pix ou cartão na página da InfinitePay.
 *
 * Autenticação: a documentação não usa token — o lojista é identificado pelo
 * `handle` (InfiniteTag, sem "$") no corpo. Por isso nenhum segredo é
 * enviado no header.
 *
 * Webhook: sem assinatura/HMAC documentado. A segurança aqui vem de dois
 * passos:
 *   1. order_nsu assinado por nós (HMAC com a APP_KEY) amarra a fatura ao
 *      valor em centavos — um link criado por terceiros com o nosso handle e
 *      outro valor não passa;
 *   2. antes de aplicar, o pagamento é confirmado no payment_check da
 *      InfinitePay (paid=true e o mesmo valor).
 */
class InfinitePayGateway extends AbstractHttpGateway
{
    /** https://www.infinitepay.io/checkout-documentacao */
    public const CHECKOUT_BASE_URL = 'https://api.checkout.infinitepay.io';

    private const CHECKOUT_ENDPOINTS = [
        'links'         => '/links',
        'payment_check' => '/payment_check',
    ];

    /** {uuid da fatura}.{valor em centavos}.{nonce}.{assinatura} */
    private const ORDER_NSU_PATTERN = '/^([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\.([1-9]\d{0,11})\.([0-9a-f]{8})\.([0-9a-f]{16})$/i';

    /** InfiniteTag: letras, números, ponto, hífen e sublinhado (sem "$"). */
    private const HANDLE_PATTERN = '/^[A-Za-z0-9._-]{1,64}$/';

    public function code(): string
    {
        return 'infinitepay';
    }

    /** Página da cobrança do gateway aceita cartão (cartão sem transparente vai por ela). */
    public function supportsCardLink(): bool
    {
        return true;
    }

    // ── Clientes ─────────────────────────────────────────────────────────────

    /**
     * Sem API de clientes: o cliente vai no próprio link (customer.name,
     * customer.email, customer.phone_number). O id local da empresa segue
     * como referência.
     */
    public function upsertCustomer(CustomerDTO $customer): string
    {
        return $customer->entityId;
    }

    // ── Assinaturas (renovação local) ────────────────────────────────────────

    /**
     * Sem recorrência na API pública: externalSubscriptionId null faz a
     * renovação local (RenewSubscriptionJob) emitir um link a cada ciclo.
     */
    public function createSubscription(CreateSubscriptionDTO $payload): CreateSubscriptionResultDTO
    {
        return new CreateSubscriptionResultDTO(
            success: true,
            externalSubscriptionId: null,
            externalCustomerId: $payload->customerId,
            status: 'active',
            rawResponse: ['note' => 'InfinitePay checkout has no recurrence API. Renewal managed locally.'],
        );
    }

    /** Sem recorrência no gateway, nada a cancelar lá. */
    public function cancelSubscription(CancelSubscriptionDTO $payload): CancelSubscriptionResultDTO
    {
        return new CancelSubscriptionResultDTO(
            success: true,
            status: 'cancelled',
            rawResponse: ['note' => 'InfinitePay has no subscription to cancel.'],
        );
    }

    // ── Cobranças: link de pagamento ─────────────────────────────────────────

    /**
     * Cria o link de pagamento da fatura (POST /links). A resposta
     * documentada é só {"url": "..."}: o link vai para paymentUrl e o id
     * externo é o nosso order_nsu (devolvido pela InfinitePay no webhook).
     * Nada é cobrado até o cliente pagar na página do link.
     *
     * Falta de configuração (handle, APP_KEY) ou valor inválido lança
     * GatewayIntegrationException antes de qualquer chamada HTTP.
     */
    public function createCharge(CreateChargeDTO $payload): CreateChargeResultDTO
    {
        $body = $this->buildChargePayload($payload);

        $response = $this->request(
            method: 'POST',
            endpointKey: 'links',
            payload: $body,
            idempotencyKey: $payload->idempotencyKey,
        );

        $json = $response->json();
        $json = is_array($json) ? $json : [];

        if (! $response->successful()) {
            return new CreateChargeResultDTO(
                success: false,
                externalPaymentId: null,
                status: null,
                amount: null,
                rawResponse: $this->sanitizePayload($json),
                errorCode: (string) $response->status(),
                errorMessage: mb_substr($response->body(), 0, 1000),
            );
        }

        $url = $this->checkoutUrl($json['url'] ?? null);

        // 2xx sem link utilizável: não grava fatura "pendente" que ninguém
        // consegue pagar.
        if ($url === null) {
            return new CreateChargeResultDTO(
                success: false,
                externalPaymentId: null,
                status: null,
                amount: null,
                rawResponse: $this->sanitizePayload($json),
                errorCode: 'invalid_response',
                errorMessage: '[infinitepay] Resposta de /links sem "url" https válido: ' . mb_substr($response->body(), 0, 500),
            );
        }

        return new CreateChargeResultDTO(
            success: true,
            externalPaymentId: $body['order_nsu'],
            status: 'pending',
            amount: $payload->amount,
            rawResponse: ['url' => $url, 'order_nsu' => $body['order_nsu']],
            paymentUrl: $url,
        );
    }

    /**
     * Corpo de POST /links conforme a documentação: handle, items (price em
     * centavos), order_nsu, webhook_url, redirect_url e customer opcionais.
     */
    protected function buildChargePayload(CreateChargeDTO $payload): array
    {
        if (strtoupper($payload->currency) !== 'BRL') {
            throw new GatewayIntegrationException("[infinitepay] Moeda {$payload->currency} não suportada: o checkout cobra só em BRL.", 'invalid_request');
        }

        $cents = (int) round($payload->amount * 100);

        if ($cents <= 0) {
            throw new GatewayIntegrationException('[infinitepay] Valor da cobrança precisa ser maior que zero.', 'invalid_request');
        }

        $customer = array_filter([
            'name'         => $this->stringOrNull($payload->metadata['customer_name'] ?? null),
            'email'        => filter_var($payload->metadata['email'] ?? null, FILTER_VALIDATE_EMAIL) ?: null,
            'phone_number' => $this->phoneNumber($payload->metadata['phone'] ?? null),
        ]);

        return array_filter([
            'handle' => $this->requireHandle(),
            'items'  => [[
                'quantity'    => 1,
                'price'       => $cents,
                'description' => $payload->description,
            ]],
            'order_nsu'    => $this->makeOrderNsu($payload->invoiceId, $cents, $payload->idempotencyKey),
            'webhook_url'  => $this->webhookUrl(),
            'redirect_url' => $this->checkoutUrl($this->gatewayConfig('redirect_url')),
            'customer'     => $customer ?: null,
        ], fn ($value) => $value !== null);
    }

    /**
     * A consulta documentada (payment_check) exige order_nsu, transaction_nsu
     * e slug — os dois últimos só chegam no webhook/redirect. Com só o id
     * externo (order_nsu) não há consulta documentada: falha clara.
     */
    public function fetchPayment(string $externalPaymentId): array
    {
        throw new GatewayIntegrationException(
            '[infinitepay] Consulta por id não documentada: o payment_check exige order_nsu, transaction_nsu e slug (use checkPayment).',
            'unsupported',
        );
    }

    /** O checkout não tem API para cancelar/expirar um link de pagamento. */
    public function cancelCharge(string $externalPaymentId): bool
    {
        return false;
    }

    /**
     * POST /payment_check — confirma o pagamento na InfinitePay.
     *
     * @return array{success?: bool, paid?: bool, amount?: int, paid_amount?: int, installments?: int, capture_method?: string}
     */
    public function checkPayment(string $orderNsu, string $transactionNsu, string $slug): array
    {
        $response = $this->request('POST', 'payment_check', [
            'handle'          => $this->requireHandle(),
            'order_nsu'       => $orderNsu,
            'transaction_nsu' => $transactionNsu,
            'slug'            => $slug,
        ]);

        if (! $response->successful()) {
            throw GatewayIntegrationException::fromHttpStatus($this->code(), $response->status(), $response->body());
        }

        $json = $response->json();

        return $this->sanitizePayload(is_array($json) ? $json : []);
    }

    // ── Webhooks ─────────────────────────────────────────────────────────────

    /**
     * Sem assinatura documentada: na ingestão (resposta rápida, a
     * InfinitePay pede < 1 s) só confere o formato documentado e o nosso
     * order_nsu assinado, com o valor do webhook igual ao assinado. A
     * confirmação no payment_check fica no processamento (parseWebhook), que
     * roda na fila com novas tentativas.
     */
    public function validateWebhookSignature(GatewayWebhookInputDTO $payload): bool
    {
        if ($this->resolveHandle() === null) {
            return false;
        }

        $data = $payload->payload;

        foreach (['transaction_nsu', 'invoice_slug'] as $field) {
            if (! is_string($data[$field] ?? null) || trim($data[$field]) === '') {
                return false;
            }
        }

        $order = $this->parseOrderNsu($data['order_nsu'] ?? null);

        return $order !== null && $this->centsOf($data['amount'] ?? null) === $order['cents'];
    }

    /**
     * O webhook só é enviado na aprovação. Antes de normalizar como "paid",
     * confirma no payment_check (paid=true e o mesmo valor assinado no
     * order_nsu). Sem confirmação, lança: o processamento falha e é
     * retentado pela fila; um reenvio da InfinitePay reprocessa o evento.
     */
    public function parseWebhook(GatewayWebhookInputDTO $payload): NormalizedWebhookEventDTO
    {
        $data  = $payload->payload;
        $order = $this->parseOrderNsu($data['order_nsu'] ?? null);

        if ($order === null || $this->centsOf($data['amount'] ?? null) !== $order['cents']) {
            throw new GatewayIntegrationException('[infinitepay] Webhook com order_nsu inválido ou valor diferente do emitido.', 'invalid_webhook');
        }

        $transactionNsu = (string) ($data['transaction_nsu'] ?? '');
        $slug           = (string) ($data['invoice_slug'] ?? '');

        if (trim($transactionNsu) === '' || trim($slug) === '') {
            throw new GatewayIntegrationException('[infinitepay] Webhook sem transaction_nsu ou invoice_slug.', 'invalid_webhook');
        }

        $check = $this->checkPayment($order['order_nsu'], $transactionNsu, $slug);
        $paid  = $this->centsOf($check['paid_amount'] ?? $data['paid_amount'] ?? null);

        if (($check['success'] ?? false) !== true || ($check['paid'] ?? false) !== true) {
            throw new GatewayIntegrationException('[infinitepay] payment_check não confirmou o pagamento do pedido ' . $order['order_nsu'] . '.', 'payment_not_confirmed');
        }

        if ($this->centsOf($check['amount'] ?? null) !== $order['cents']) {
            throw new GatewayIntegrationException('[infinitepay] payment_check devolveu valor diferente do emitido para o pedido ' . $order['order_nsu'] . '.', 'amount_mismatch');
        }

        return new NormalizedWebhookEventDTO(
            gatewayCode: $payload->gatewayCode,
            eventType: 'paid',
            externalEventId: $payload->externalEventId ?? $this->webhookEventKey($data),
            externalSubscriptionId: null,
            externalPaymentId: $order['order_nsu'],
            externalInvoiceId: null,
            status: 'paid',
            amount: $order['cents'] / 100,
            currency: 'BRL',
            metadata: array_filter([
                'invoice_id'      => $order['invoice_id'],
                'transaction_nsu' => $transactionNsu,
                'invoice_slug'    => $slug,
                'capture_method'  => $this->stringOrNull($check['capture_method'] ?? $data['capture_method'] ?? null),
                'installments'    => is_numeric($check['installments'] ?? null) ? (int) $check['installments'] : null,
                // paid_amount inclui juros do parcelamento pagos pelo cliente.
                'paid_amount' => $paid !== null ? $paid / 100 : null,
                'receipt_url' => $this->httpUrl($data['receipt_url'] ?? null),
            ], fn ($value) => $value !== null),
            rawPayload: $data,
            occurredAt: now()->toIso8601String(),
        );
    }

    /**
     * Sem id de evento no webhook. order_nsu + transaction_nsu + invoice_slug
     * identificam o pagamento: o reenvio da mesma notificação tem a mesma
     * chave; outro pagamento (outra transação), outra chave.
     */
    public function webhookEventKey(array $payload): ?string
    {
        if (! is_string($payload['order_nsu'] ?? null) || $payload['order_nsu'] === '') {
            return null;
        }

        return $this->composeWebhookEventKey([
            $payload['order_nsu'],
            $payload['transaction_nsu'] ?? null,
            $payload['invoice_slug'] ?? null,
        ]);
    }

    public function healthCheck(): GatewayHealthDTO
    {
        if ($this->resolveHandle() === null) {
            return new GatewayHealthDTO(healthy: false, message: 'InfinitePay sem InfiniteTag (handle) configurada.');
        }

        if ($this->signingKey() === null) {
            return new GatewayHealthDTO(healthy: false, message: 'InfinitePay sem APP_KEY para assinar o order_nsu.');
        }

        return new GatewayHealthDTO(healthy: true, message: 'InfinitePay (checkout) configurado.');
    }

    // ── Infra ────────────────────────────────────────────────────────────────

    /** A API do checkout não documenta token: nenhum segredo no header. */
    protected function authHeaders(): array
    {
        return [
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Endpoints do checkout (api.checkout.infinitepay.io). O bloco
     * billing.gateways.infinitepay.base_url/endpoints antigo (/v1/...) não
     * existe na documentação e não é usado.
     */
    protected function buildEndpoint(string $endpointKey, array $replacements = []): string
    {
        $base = rtrim((string) ($this->gatewayConfig('checkout_base_url') ?: self::CHECKOUT_BASE_URL), '/');
        $path = (string) (self::CHECKOUT_ENDPOINTS[$endpointKey] ?? '');

        if ($path === '') {
            throw new GatewayIntegrationException("[infinitepay] Endpoint [{$endpointKey}] não existe na API de checkout.", 'unsupported');
        }

        return $base . $path;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * InfiniteTag: credencial extra "handle", config billing.gateways.
     * infinitepay.handle ou, por fim, o campo "secret" (único que o manager
     * grava — a API do checkout não tem token).
     */
    private function resolveHandle(): ?string
    {
        foreach ([
            fn () => $this->resolveExtraCredential('handle'),
            fn () => $this->gatewayConfig('handle'),
            fn () => $this->resolveSecret(),
        ] as $source) {
            $value = $source();

            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $handle = ltrim(trim($value), '$');

            return preg_match(self::HANDLE_PATTERN, $handle) === 1 ? $handle : null;
        }

        return null;
    }

    private function requireHandle(): string
    {
        return $this->resolveHandle()
            ?? throw GatewayIntegrationException::unavailable($this->code(), 'InfiniteTag (handle) ausente ou inválida — configure a credencial "handle" ou billing.gateways.infinitepay.handle.');
    }

    private function signingKey(): ?string
    {
        $key = config('app.key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * order_nsu = {fatura}.{centavos}.{nonce}.{hmac}. O nonce vem da chave de
     * idempotência (a mesma tentativa gera o mesmo pedido; outra tentativa,
     * outro pedido) e o HMAC impede forjar pedido/valor para a fatura.
     */
    private function makeOrderNsu(string $invoiceId, int $cents, ?string $idempotencyKey): string
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $invoiceId) !== 1) {
            throw new GatewayIntegrationException('[infinitepay] Fatura sem id UUID: não dá para montar o order_nsu.', 'invalid_request');
        }

        $nonce = filled($idempotencyKey)
            ? substr(hash('sha256', (string) $idempotencyKey), 0, 8)
            : bin2hex(random_bytes(4));

        $invoiceId = strtolower($invoiceId);

        return "{$invoiceId}.{$cents}.{$nonce}." . $this->signOrder($invoiceId, $cents, $nonce);
    }

    private function signOrder(string $invoiceId, int $cents, string $nonce): string
    {
        $key = $this->signingKey()
            ?? throw GatewayIntegrationException::unavailable($this->code(), 'APP_KEY ausente: não dá para assinar o order_nsu.');

        return substr(hash_hmac('sha256', "infinitepay|{$invoiceId}|{$cents}|" . strtolower($nonce), $key), 0, 16);
    }

    /**
     * @return array{order_nsu: string, invoice_id: string, cents: int}|null
     */
    private function parseOrderNsu(mixed $value): ?array
    {
        if (! is_string($value) || preg_match(self::ORDER_NSU_PATTERN, $value, $m) !== 1 || $this->signingKey() === null) {
            return null;
        }

        $invoiceId = strtolower($m[1]);
        $cents     = (int) $m[2];

        if (! hash_equals($this->signOrder($invoiceId, $cents, $m[3]), strtolower($m[4]))) {
            return null;
        }

        return ['order_nsu' => $value, 'invoice_id' => $invoiceId, 'cents' => $cents];
    }

    /** Valor em centavos (inteiro) como a InfinitePay documenta. */
    private function centsOf(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        return null;
    }

    private function webhookUrl(): string
    {
        $configured = $this->checkoutUrl($this->gatewayConfig('webhook_url'));

        return $configured ?? route('billing.webhooks', ['gateway' => $this->code()]);
    }

    /** Só https (link vira href no painel/e-mail e a InfinitePay serve em https). */
    private function checkoutUrl(mixed $value): ?string
    {
        $url = $this->httpUrl($value);

        return $url !== null && str_starts_with(strtolower($url), 'https://') ? $url : null;
    }

    /** Formato do exemplo da documentação: +5511999887766. */
    private function phoneNumber(mixed $value): ?string
    {
        $digits = preg_replace('/\D/', '', is_scalar($value) ? (string) $value : '');

        return match (true) {
            in_array(strlen($digits), [10, 11], true)                                   => '+55' . $digits,
            in_array(strlen($digits), [12, 13], true) && str_starts_with($digits, '55') => '+' . $digits,
            default                                                                     => null,
        };
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
