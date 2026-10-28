<?php

namespace App\Services\Billing\Gateways;

use App\DTOs\Billing\{
    CancelSubscriptionDTO,
    CancelSubscriptionResultDTO,
    CardChargeDTO,
    CardCheckoutConfigDTO,
    CreateChargeDTO,
    CreateChargeResultDTO,
    CreateSubscriptionDTO,
    CreateSubscriptionResultDTO,
    CustomerDTO,
    GatewayWebhookInputDTO,
    NormalizedWebhookEventDTO,
    PaymentInstructionsDTO,
    SaveCardResultDTO,
    SavedCardDTO,
};
use App\Exceptions\Billing\GatewayIntegrationException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\{Arr, Sleep, Str};
use Throwable;

/**
 * Pagar.me — API Core v5 (https://api.pagar.me/core/v5).
 *
 * Renovação local: o EasyEye não cria assinatura no Pagar.me
 * (createSubscription devolve externalSubscriptionId nulo) e emite cada ciclo
 * como um pedido avulso (POST /orders) com uma cobrança Pix ou boleto.
 *
 * Id da cobrança no EasyEye (Payment.external_payment_id e
 * Invoice.external_invoice_id): o id da cobrança do pedido (ch_…), não o do
 * pedido (or_…). É o único id que aparece documentado nos dois tipos de
 * webhook — `data.id` em charge.* e `data.charges[].id` em order.* — então
 * order.paid e charge.paid do mesmo pagamento caem no MESMO Payment, e o
 * segundo é descartado como duplicado (ProcessWebhookEventService::applyPaid).
 *
 * Documentação (referências usadas em cada ponto):
 *  - Autenticação: https://docs.pagar.me/reference/autentica%C3%A7%C3%A3o-2
 *  - Idempotência: https://docs.pagar.me/docs/o-que-%C3%A9
 *  - Cliente:      https://docs.pagar.me/reference/criar-cliente-1
 *  - Pedido:       https://docs.pagar.me/reference/criar-pedido-2 e https://docs.pagar.me/reference/pedidos-1
 *  - Pix:          https://docs.pagar.me/reference/pix-2
 *  - Boleto:       https://docs.pagar.me/reference/boleto-1
 *  - Cobrança:     https://docs.pagar.me/reference/cobran%C3%A7as-1
 *  - Cancelar cobrança:   https://docs.pagar.me/reference/cancelar-cobran%C3%A7a
 *  - Cancelar assinatura: https://docs.pagar.me/reference/cancelar-assinatura-1
 *  - Webhooks:     https://docs.pagar.me/reference/vis%C3%A3o-geral-sobre-webhooks,
 *                  https://docs.pagar.me/reference/eventos-de-webhook-1,
 *                  https://docs.pagar.me/reference/exemplo-de-webhook-1,
 *                  https://docs.pagar.me/page/chargeback-novo-status-na-cobran%C3%A7a
 */
class PagarmeGateway extends AbstractHttpGateway
{
    /**
     * Caminhos da API v5 (fixos pela documentação). Têm precedência sobre
     * config('billing.gateways.pagarme.endpoints'), que trazia
     * POST /subscriptions/{id}/cancel e GET /orders/{id} para a cobrança —
     * caminhos que não existem/não correspondem ao id gravado.
     */
    private const ENDPOINTS = [
        'customers'           => '/customers',
        'charges'             => '/orders',
        'order'               => '/orders/{id}',
        'payments'            => '/charges/{id}',
        'charge_cancel'       => '/charges/{id}',
        'subscription_cancel' => '/subscriptions/{id}',
        'customer_cards'      => '/customers/{id}/cards',
    ];

    /** Script oficial de tokenização (https://docs.pagar.me/reference/pagarme-js). */
    public const SDK_URL = 'https://checkout.pagar.me/v1/tokenizecard.js';

    /** Nome na fatura do cartão (até 13 caracteres no PSP). */
    private const STATEMENT_DESCRIPTOR = 'EASYEYE';

    /** Pix e boleto: só os métodos que dispensam dados de cartão. */
    private const DEFAULT_PAYMENT_METHOD = 'pix';

    /** Fuso dos vencimentos (datas Y-m-d do EasyEye são dias de Brasília). */
    private const LOCAL_TZ = 'America/Sao_Paulo';

    /** 409 = já há uma requisição em aberto com a mesma Idempotency-Key. */
    private const IDEMPOTENCY_CONFLICT_RETRIES = 3;

    /** Metadados que não vão ao Pagar.me (dados pessoais já estão no cliente). */
    private const PERSONAL_METADATA = ['email', 'customer_name', 'document', 'phone', 'address'];

    private ?string $pendingIdempotencyKey = null;

    public function code(): string
    {
        return 'pagarme';
    }

    // ── HTTP ─────────────────────────────────────────────────────────────────

    /**
     * Basic Auth: usuário = secret key (sk_… / sk_test_…), senha vazia.
     * Idempotência: header `Idempotency-Key` (o X-Idempotency-Key da classe
     * base não é lido pelo Pagar.me).
     */
    protected function authHeaders(): array
    {
        $headers = [
            'Authorization' => 'Basic ' . base64_encode((string) $this->resolveSecret() . ':'),
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
        ];

        if ($this->pendingIdempotencyKey !== null && $this->pendingIdempotencyKey !== '') {
            $headers['Idempotency-Key'] = $this->pendingIdempotencyKey;
        }

        return $headers;
    }

    protected function request(
        string $method,
        string $endpointKey,
        array $payload = [],
        array $replacements = [],
        ?string $idempotencyKey = null,
    ): Response {
        $this->pendingIdempotencyKey = $idempotencyKey;

        try {
            return parent::request($method, $endpointKey, $payload, $replacements, $idempotencyKey);
        } finally {
            $this->pendingIdempotencyKey = null;
        }
    }

    protected function buildEndpoint(string $endpointKey, array $replacements = []): string
    {
        $baseUrl  = rtrim((string) ($this->gatewayConfig('base_url') ?: 'https://api.pagar.me/core/v5'), '/');
        $endpoint = self::ENDPOINTS[$endpointKey]
            ?? (string) Arr::get((array) $this->gatewayConfig('endpoints'), $endpointKey, '');

        foreach ($replacements as $key => $value) {
            $endpoint = str_replace('{' . $key . '}', rawurlencode((string) $value), $endpoint);
        }

        return $baseUrl . $endpoint;
    }

    protected function cancelMethod(): string
    {
        return 'DELETE';
    }

    // ── Clientes ─────────────────────────────────────────────────────────────

    /**
     * POST /customers. O e-mail é único no Pagar.me: criar com um e-mail já
     * cadastrado atualiza o cliente existente e devolve o mesmo id (upsert).
     */
    public function upsertCustomer(CustomerDTO $customer): string
    {
        $response = $this->post('customers', $this->buildCustomerPayload($customer));

        if (! $response->successful()) {
            throw GatewayIntegrationException::fromHttpStatus($this->code(), $response->status(), $this->errorMessage($response));
        }

        $id = $this->extractString($response->json() ?? [], ['id']);

        if ($id === null) {
            throw GatewayIntegrationException::unavailable($this->code(), 'Resposta de cliente sem id.');
        }

        return $id;
    }

    protected function buildCustomerPayload(CustomerDTO $customer): array
    {
        $document = preg_replace('/\D/', '', (string) $customer->document) ?? '';
        $docType  = match (strlen($document)) {
            11      => 'CPF',
            14      => 'CNPJ',
            default => null,
        };
        $email = trim((string) $customer->email);

        return array_filter([
            'name'  => mb_substr(trim($customer->name), 0, 64),
            'email' => $email !== '' && mb_strlen($email) <= 64 ? $email : null,
            'code'  => mb_substr((string) ($customer->externalReference ?: $customer->entityId), 0, 52),
            // CPF = pessoa física (individual); CNPJ = pessoa jurídica (company).
            'type'          => $docType === 'CNPJ' ? 'company' : 'individual',
            'document'      => $docType ? $document : null,
            'document_type' => $docType,
            // Pix exige telefone do cliente (https://docs.pagar.me/reference/pix-2).
            'phones' => $this->buildPhones($customer->phone),
            // Boleto exige o endereço do cliente (https://docs.pagar.me/reference/boleto-1).
            'address'  => $this->buildAddress($customer->address),
            'metadata' => ['entity_id' => (string) $customer->entityId],
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);
    }

    /**
     * Endereço do cliente no formato da v5: line_1 = "Número, Rua, Bairro",
     * line_2 = complemento, zip_code, city, state (UF) e country (BR).
     * Incompleto não vai (o boleto seria recusado de qualquer forma).
     *
     * @return array<string, string>|null
     */
    private function buildAddress(?array $address): ?array
    {
        if ($address === null) {
            return null;
        }

        $value = fn (string $key): string => is_scalar($address[$key] ?? null) ? trim((string) $address[$key]) : '';

        $zip   = preg_replace('/\D/', '', $value('zipcode')) ?? '';
        $state = strtoupper($value('state'));

        if (strlen($zip) !== 8 || strlen($state) !== 2 || in_array('', [$value('street'), $value('number'), $value('district'), $value('city')], true)) {
            return null;
        }

        return array_filter([
            'line_1'   => mb_substr("{$value('number')}, {$value('street')}, {$value('district')}", 0, 256),
            'line_2'   => mb_substr($value('complement'), 0, 128),
            'zip_code' => $zip,
            'city'     => mb_substr($value('city'), 0, 64),
            'state'    => $state,
            'country'  => 'BR',
        ], fn ($v) => $v !== '');
    }

    /** @return array<string, array<string, string>>|null */
    private function buildPhones(?string $phone): ?array
    {
        $digits = preg_replace('/\D/', '', (string) $phone) ?? '';

        // +55 informado junto: tira o código do país.
        if (strlen($digits) >= 12 && str_starts_with($digits, '55')) {
            $digits = substr($digits, 2);
        }

        // Discagem com 0 na frente (0XX…).
        if (strlen($digits) === 11 && $digits[0] === '0') {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) < 10 || strlen($digits) > 11) {
            return null;
        }

        $number = substr($digits, 2);

        return [
            strlen($number) === 9 ? 'mobile_phone' : 'home_phone' => [
                'country_code' => '55',
                'area_code'    => substr($digits, 0, 2),
                'number'       => $number,
            ],
        ];
    }

    // ── Assinaturas (renovação local) ────────────────────────────────────────

    /**
     * Sem recorrência no Pagar.me: a assinatura nativa de lá emitiria as
     * cobranças sozinha, em paralelo com a renovação local (cobrança em
     * dobro), e subscriptionIssuesFirstCharge() é false. Cada ciclo sai por
     * createCharge (RenewSubscriptionJob).
     */
    public function createSubscription(CreateSubscriptionDTO $payload): CreateSubscriptionResultDTO
    {
        return new CreateSubscriptionResultDTO(
            success: true,
            externalSubscriptionId: null,
            externalCustomerId: $payload->customerId,
            status: 'active',
            rawResponse: ['note' => 'managed_locally'],
        );
    }

    /**
     * Só há o que cancelar se existir uma assinatura antiga criada no
     * Pagar.me (sub_…): DELETE /subscriptions/{id}, cancelando faturas e
     * cobranças pendentes dela. 404 = não existe lá, nada continua cobrando.
     */
    public function cancelSubscription(CancelSubscriptionDTO $payload): CancelSubscriptionResultDTO
    {
        if (blank($payload->externalSubscriptionId)) {
            return new CancelSubscriptionResultDTO(success: true, status: 'cancelled', rawResponse: []);
        }

        $response = $this->request(
            method: 'DELETE',
            endpointKey: 'subscription_cancel',
            payload: ['cancel_pending_invoices' => true],
            replacements: ['id' => $payload->externalSubscriptionId],
        );

        if ($response->status() === 404) {
            return new CancelSubscriptionResultDTO(success: true, status: 'cancelled', rawResponse: $response->json() ?? []);
        }

        if (! $response->successful()) {
            return new CancelSubscriptionResultDTO(
                success: false,
                status: null,
                rawResponse: $this->sanitizePayload($response->json() ?? []),
                errorMessage: $this->errorMessage($response),
            );
        }

        return new CancelSubscriptionResultDTO(success: true, status: 'cancelled', rawResponse: $response->json() ?? []);
    }

    // ── Cobranças (pedidos) ──────────────────────────────────────────────────

    /**
     * POST /orders com um item e um pagamento Pix ou boleto.
     *
     * O Pagar.me responde 200 mesmo quando a cobrança falha na criação (ex.:
     * cliente sem telefone no Pix, sem endereço no boleto registrado): o
     * pedido vem com status `failed` — tratado como falha, sem id.
     */
    public function createCharge(CreateChargeDTO $payload): CreateChargeResultDTO
    {
        $body     = $this->buildChargePayload($payload);
        $attempts = 0;

        while (true) {
            $response = $this->request(
                method: 'POST',
                endpointKey: 'charges',
                payload: $body,
                idempotencyKey: $payload->idempotencyKey,
            );

            // 409: a 1ª requisição com a mesma chave ainda está em andamento.
            // Repetir com a MESMA chave devolve o pedido original (nunca dois).
            if ($response->status() !== 409 || blank($payload->idempotencyKey) || ++$attempts >= self::IDEMPOTENCY_CONFLICT_RETRIES) {
                break;
            }

            Sleep::for(1)->second();
        }

        if (! $response->successful()) {
            return new CreateChargeResultDTO(
                success: false,
                externalPaymentId: null,
                status: null,
                amount: null,
                rawResponse: $this->sanitizePayload($response->json() ?? []),
                errorCode: (string) $response->status(),
                errorMessage: $this->errorMessage($response),
            );
        }

        $order  = $response->json() ?? [];
        $charge = $this->pickCharge($order, null);
        $status = $this->normalizeChargeStatus((string) ($charge['status'] ?? $order['status'] ?? ''));

        if (strtolower((string) ($order['status'] ?? '')) === 'failed' || $status === 'failed') {
            return new CreateChargeResultDTO(
                success: false,
                externalPaymentId: null,
                status: 'failed',
                amount: null,
                rawResponse: $this->sanitizePayload($order),
                errorCode: 'order_failed',
                errorMessage: $this->chargeFailureMessage($charge),
            );
        }

        $externalId = $this->extractString($charge, ['id']) ?? $this->extractString($order, ['id']);

        if ($externalId === null) {
            return new CreateChargeResultDTO(
                success: false,
                externalPaymentId: null,
                status: null,
                amount: null,
                rawResponse: $this->sanitizePayload($order),
                errorCode: 'invalid_response',
                errorMessage: 'Pedido do Pagar.me sem id da cobrança.',
            );
        }

        return new CreateChargeResultDTO(
            success: true,
            externalPaymentId: $externalId,
            status: $status,
            amount: $this->centsToFloat($charge['amount'] ?? $order['amount'] ?? null),
            rawResponse: $this->sanitizePayload($order),
            paymentUrl: $this->chargePaymentUrl($charge),
        );
    }

    /**
     * Cobrança gravada (ch_…) → GET /charges/{id}; pedido (or_…, gravado
     * pela versão anterior desta integração) → GET /orders/{id}.
     */
    public function fetchPayment(string $externalPaymentId): array
    {
        $endpoint = str_starts_with($externalPaymentId, 'or_') ? 'order' : 'payments';
        $response = $this->request('GET', $endpoint, [], ['id' => $externalPaymentId]);

        if (! $response->successful()) {
            throw GatewayIntegrationException::fromHttpStatus($this->code(), $response->status(), $this->errorMessage($response));
        }

        return $this->sanitizePayload($response->json() ?? []);
    }

    /**
     * DELETE /charges/{id} (https://docs.pagar.me/reference/cancelar-cobran%C3%A7a):
     * cancela a cobrança Pix/boleto ainda pendente; a resposta é a cobrança
     * com status "canceled". Só ch_… (pedido antigo or_… não tem cancelamento
     * de cobrança).
     */
    public function cancelCharge(string $externalPaymentId): bool
    {
        if (! str_starts_with($externalPaymentId, 'ch_')) {
            return false;
        }

        try {
            $response = $this->request('DELETE', 'charge_cancel', [], ['id' => $externalPaymentId]);
        } catch (GatewayIntegrationException) {
            return false;
        }

        return $response->successful()
            && $this->normalizeChargeStatus((string) $response->json('status')) === 'cancelled';
    }

    protected function buildChargePayload(CreateChargeDTO $payload): array
    {
        $code   = mb_substr($payload->invoiceId, 0, 52);
        $method = $this->resolvePaymentMethod($payload->paymentMethod);

        $payment = ['payment_method' => $method];

        if ($method === 'boleto') {
            $payment['boleto'] = [
                // Data sem fuso (formato do exemplo da doc): o dia não muda
                // seja lido em UTC ou em Brasília.
                'due_at'       => $this->dueDay($payload->dueDate)->format('Y-m-d') . 'T23:59:59',
                'instructions' => mb_substr("Pagamento referente a {$payload->description}", 0, 256),
            ];
        } else {
            // expires_in (segundos) é o campo obrigatório do Pix; vale até o
            // fim do dia do vencimento, no mínimo 1 hora.
            $expiresAt      = $this->dueDay($payload->dueDate)->endOfDay();
            $payment['pix'] = [
                'expires_in' => max(3600, (int) CarbonImmutable::now(self::LOCAL_TZ)->diffInSeconds($expiresAt, false)),
            ];
        }

        return [
            'code'        => $code,
            'customer_id' => $payload->customerId,
            'items'       => [[
                'amount'      => (int) round($payload->amount * 100),
                'description' => mb_substr($payload->description, 0, 256),
                'quantity'    => 1,
                'code'        => $code,
            ]],
            'payments' => [$payment],
            'closed'   => true,
            'metadata' => $this->buildMetadata($payload),
        ];
    }

    /** @return array<string, string> */
    private function buildMetadata(CreateChargeDTO $payload): array
    {
        $metadata = array_merge(
            Arr::except($payload->metadata, self::PERSONAL_METADATA),
            [
                'entity_id'       => $payload->entityId,
                'invoice_id'      => $payload->invoiceId,
                'subscription_id' => $payload->subscriptionId,
            ],
        );

        // Metadata do Pagar.me é chave/valor: só escalares, como texto.
        return array_map(
            fn ($value) => is_bool($value) ? ($value ? 'true' : 'false') : (string) $value,
            array_filter($metadata, fn ($value) => is_scalar($value) && (string) $value !== ''),
        );
    }

    private function resolvePaymentMethod(?string $method): string
    {
        $method = strtolower(trim((string) ($method ?: $this->gatewayConfig('payment_method') ?: self::DEFAULT_PAYMENT_METHOD)));

        // Cartão exige dados/token do cartão (não coletados aqui): só Pix e boleto.
        return in_array($method, ['boleto', 'bank_slip'], true) ? 'boleto' : 'pix';
    }

    /** Dia do vencimento em Brasília; nunca antes de hoje. */
    private function dueDay(?string $dueDate): CarbonImmutable
    {
        $today = CarbonImmutable::now(self::LOCAL_TZ)->startOfDay();

        if (blank($dueDate)) {
            return $today->addDays(3);
        }

        try {
            $due = CarbonImmutable::parse(substr((string) $dueDate, 0, 10), self::LOCAL_TZ)->startOfDay();
        } catch (Throwable) {
            return $today->addDays(3);
        }

        return $due->lessThan($today) ? $today : $due;
    }

    // ── Checkout transparente ────────────────────────────────────────────────

    public function transparentMethods(): array
    {
        return ['pix', 'boleto', 'credit_card'];
    }

    /**
     * Pix: last_transaction.qr_code (copia-e-cola), qr_code_url, expires_at;
     * boleto: last_transaction.line (linha digitável), pdf, url, barcode,
     * due_at (https://docs.pagar.me/reference/pix-2, /boleto-1). Lê do pedido
     * guardado na emissão; sem ele, GET /charges/{id}.
     */
    public function paymentInstructions(string $method, string $externalPaymentId, array $chargePayload = []): ?PaymentInstructionsDTO
    {
        $charge = isset($chargePayload['charges']) ? $this->pickCharge($chargePayload, null) : $chargePayload;

        if (! is_array($charge['last_transaction'] ?? null)) {
            $charge = $this->fetchPayment($externalPaymentId);
        }

        $chargeMethod = strtolower((string) ($charge['payment_method'] ?? Arr::get($charge, 'last_transaction.transaction_type', '')));
        $tx           = (array) ($charge['last_transaction'] ?? []);

        return match (true) {
            $method === 'pix' && $chargeMethod === 'pix' => PaymentInstructionsDTO::pix(
                copyPaste: $this->extractString($tx, ['qr_code']),
                qrImageUrl: $this->extractString($tx, ['qr_code_url']),
                expiresAt: $this->isoDate($tx['expires_at'] ?? null),
            ),
            $method === 'boleto' && $chargeMethod === 'boleto' => PaymentInstructionsDTO::boleto(
                digitableLine: $this->extractString($tx, ['line']),
                pdfUrl: $this->extractString($tx, ['pdf']),
                dueDate: $this->extractString($tx, ['due_at']),
                paymentUrl: $this->extractString($tx, ['url']),
            ),
            default => null,
        };
    }

    /**
     * tokenizecard.js com a chave pública (pk_…) gera o token do cartão no
     * navegador (validade 60 s, uso único) — https://docs.pagar.me/reference/pagarme-js.
     */
    public function cardCheckoutConfig(): ?CardCheckoutConfigDTO
    {
        return new CardCheckoutConfigDTO(
            gateway: $this->code(),
            publicKey: $this->publicKey(),
            sdkUrl: self::SDK_URL,
            tokenization: 'card_token',
            maxInstallments: $this->cardMaxInstallments(),
        );
    }

    /**
     * Cartão em dois passos, válidos para conta Gateway e PSP (no PSP o
     * card_token direto em /orders não é aceito — https://docs.pagar.me/reference/criar-pedido-2):
     *  1. token → cartão na carteira do cliente (POST /customers/{id}/cards,
     *     verify_card) — https://docs.pagar.me/reference/criar-cart%C3%A3o;
     *  2. pedido com credit_card.card_id e as parcelas.
     *
     * Recorrência (https://docs.pagar.me/docs/api-v5-identificador-de-recorr%C3%AAncia-para-assinaturas-externas):
     * 1ª cobrança recurrence_cycle "first"; renovação (offSession)
     * "subsequent" com payment_origin.charge_id da 1ª cobrança e 1 parcela.
     */
    public function chargeCard(CardChargeDTO $payload): CreateChargeResultDTO
    {
        $savedCard = null;
        $cardId    = $payload->savedCardId;

        if (blank($cardId)) {
            $stored = $this->storeCard($payload->customerId, (string) $payload->cardToken, $payload->payer, $payload->idempotencyKey);

            if (! $stored->success || $stored->card === null) {
                return new CreateChargeResultDTO(
                    success: true,
                    externalPaymentId: null,
                    status: 'failed',
                    amount: null,
                    rawResponse: [],
                    errorCode: 'card_declined',
                    errorMessage: $stored->errorMessage,
                );
            }

            $savedCard = $stored->card;
            $cardId    = $stored->card->id;
        }

        $originChargeId = $payload->metadata['card_origin_payment_id'] ?? null;
        $subsequent     = $payload->offSession && is_string($originChargeId) && $originChargeId !== '';
        $installments   = $payload->offSession ? 1 : max(1, $payload->installments);

        $creditCard = array_filter([
            'installments'         => $installments,
            'statement_descriptor' => self::STATEMENT_DESCRIPTOR,
            'operation_type'       => 'auth_and_capture',
            'card_id'              => $cardId,
            // Identificador de recorrência só à vista (parcelado não é recorrência).
            'recurrence_cycle' => $subsequent ? 'subsequent' : ($installments === 1 && ($payload->saveCard || $payload->offSession) ? 'first' : null),
            'payment_origin'   => $subsequent ? ['charge_id' => $originChargeId] : null,
        ], fn ($value) => $value !== null);

        $code  = mb_substr($payload->invoiceId, 0, 52);
        $order = [
            'code'        => $code,
            'customer_id' => $payload->customerId,
            'items'       => [[
                'amount'      => (int) round($payload->amount * 100),
                'description' => mb_substr($payload->description, 0, 256),
                'quantity'    => 1,
                'code'        => $code,
            ]],
            'payments' => [['payment_method' => 'credit_card', 'credit_card' => $creditCard]],
            'closed'   => true,
            'metadata' => $this->buildMetadata(new CreateChargeDTO(
                entityId: $payload->entityId,
                invoiceId: $payload->invoiceId,
                subscriptionId: $payload->subscriptionId,
                customerId: $payload->customerId,
                amount: $payload->amount,
                currency: $payload->currency,
                description: $payload->description,
                metadata: Arr::except($payload->metadata, ['card_origin_payment_id']),
            )),
        ];

        $response = $this->request('POST', 'charges', $order, [], $payload->idempotencyKey);

        if (! $response->successful()) {
            return $this->cardFailure($response, $this->errorMessage($response));
        }

        $json   = $response->json() ?? [];
        $charge = $this->pickCharge($json, null);
        $tx     = (array) ($charge['last_transaction'] ?? []);
        $status = $this->cardStatus($charge, $json);
        $card   = (array) ($tx['card'] ?? []);

        $savedCard ??= SavedCardDTO::make($card['id'] ?? $cardId, $card['brand'] ?? null, $card['last_four_digits'] ?? null);

        return $this->cardResult(
            response: $response,
            externalId: $this->extractString($charge, ['id']) ?? $this->extractString($json, ['id']),
            status: $status,
            amount: $this->centsToFloat($charge['amount'] ?? $json['amount'] ?? null),
            json: $json,
            savedCard: $savedCard,
            declineMessage: $status === 'failed' ? $this->chargeFailureMessage($charge) : null,
        );
    }

    /** Troca do cartão da renovação: só guarda na carteira do cliente (verify_card). */
    public function saveCard(string $customerId, string $cardToken, CustomerDTO $payer): SaveCardResultDTO
    {
        return $this->storeCard($customerId, $cardToken, $payer, null);
    }

    /**
     * POST /customers/{id}/cards com o token do tokenizecard.js e o endereço
     * de cobrança; verify_card faz a validação de R$ 0 (Zero Dollar Auth).
     */
    private function storeCard(string $customerId, string $token, CustomerDTO $payer, ?string $idempotencyKey): SaveCardResultDTO
    {
        if ($customerId === '' || ! str_starts_with($token, 'token_')) {
            return new SaveCardResultDTO(success: false, errorMessage: __('checkout.decline.token_expired'));
        }

        $response = $this->request('POST', 'customer_cards', array_filter([
            'token'           => $token,
            'billing_address' => $this->buildAddress($payer->address),
            'options'         => ['verify_card' => true],
        ], fn ($value) => $value !== null), ['id' => $customerId], $idempotencyKey ? "{$idempotencyKey}:card" : null);

        if (! $response->successful()) {
            return new SaveCardResultDTO(success: false, errorMessage: $this->errorMessage($response));
        }

        $json = $response->json() ?? [];
        $card = SavedCardDTO::make($json['id'] ?? null, $json['brand'] ?? null, $json['last_four_digits'] ?? null);

        if ($card === null || in_array(strtolower((string) ($json['status'] ?? 'active')), ['deleted', 'expired'], true)) {
            return new SaveCardResultDTO(success: false, errorMessage: __('checkout.decline.card_not_accepted', ['gateway' => 'Pagar.me']));
        }

        return new SaveCardResultDTO(success: true, card: $card);
    }

    /**
     * Cobrança no cartão: charges[].status paid → pago; failed ou
     * last_transaction not_authorized/with_error/failed → recusado; o resto
     * (processing, pending) → aguardando. https://docs.pagar.me/reference/cobran%C3%A7as-1.
     */
    private function cardStatus(array $charge, array $order): string
    {
        $tx = strtolower((string) Arr::get($charge, 'last_transaction.status', ''));

        if (in_array($tx, ['not_authorized', 'with_error', 'failed'], true) || strtolower((string) ($order['status'] ?? '')) === 'failed') {
            return 'failed';
        }

        return $this->normalizeChargeStatus((string) ($charge['status'] ?? $order['status'] ?? ''));
    }

    // ── Webhooks ─────────────────────────────────────────────────────────────

    /**
     * A API v5 não documenta assinatura HMAC no webhook (o header
     * x-pagarme-signature não existe). A autenticidade é a autenticação
     * opcional do cadastro do webhook no dashboard (Configurações → Webhooks:
     * usuário e senha), enviada como `Authorization: Basic …`.
     *
     * webhook_secret = "usuario:senha" desse cadastro (ou só a senha). Sem
     * webhook_secret configurado: rejeita (fail-closed).
     */
    public function validateWebhookSignature(GatewayWebhookInputDTO $payload): bool
    {
        $secret = $this->resolveWebhookSecret();

        if ($secret === null || $secret === '') {
            return false;
        }

        $header = $this->header($payload->headers, 'authorization');

        if ($header === null || ! preg_match('/^Basic\s+(\S+)$/i', trim($header), $m)) {
            return false;
        }

        $decoded = base64_decode($m[1], true);

        if ($decoded === false || ! str_contains($decoded, ':')) {
            return false;
        }

        if (str_contains($secret, ':')) {
            return hash_equals($secret, $decoded);
        }

        return hash_equals($secret, (string) Str::after($decoded, ':'));
    }

    /**
     * Id do webhook (hook_…): único por notificação e repetido nas
     * retentativas da mesma. order.paid e charge.paid do mesmo pagamento têm
     * hooks diferentes — a duplicidade é barrada pelo Payment (mesmo ch_…).
     */
    public function webhookEventKey(array $payload): ?string
    {
        $hookId = $this->extractString($payload, ['id']);

        if ($hookId !== null) {
            return $hookId;
        }

        return $this->composeWebhookEventKey([
            Arr::get($payload, 'type'),
            Arr::get($payload, 'data.id'),
            Arr::get($payload, 'data.status'),
        ]);
    }

    public function parseWebhook(GatewayWebhookInputDTO $payload): NormalizedWebhookEventDTO
    {
        $type = strtolower((string) ($payload->payload['type'] ?? 'unknown'));
        $data = is_array($payload->payload['data'] ?? null) ? $payload->payload['data'] : [];

        [$order, $charge] = match (true) {
            str_starts_with($type, 'order.')      => [$data, $this->pickCharge($data, $type)],
            str_starts_with($type, 'charge.')     => [is_array($data['order'] ?? null) ? $data['order'] : [], $data],
            str_starts_with($type, 'chargeback.') => [[], $this->chargeOfChargeback($data)],
            default                               => [[], []],
        };

        $orderId  = $this->extractString($order, ['id']) ?? $this->extractString($charge, ['order.id', 'order_id']);
        $chargeId = $this->extractString($charge, ['id']);
        $isPaid   = in_array($type, ['order.paid', 'charge.paid', 'charge.overpaid'], true);

        $metadata = array_merge(
            is_array($order['metadata'] ?? null) ? $order['metadata'] : [],
            is_array($charge['metadata'] ?? null) ? $charge['metadata'] : [],
        );

        // Pedido criado pelo EasyEye: code = id da fatura.
        $orderCode = $this->extractString($order, ['code']);

        if (! isset($metadata['invoice_id']) && $orderCode !== null && Str::isUuid($orderCode)) {
            $metadata['invoice_id'] = $orderCode;
        }

        $amount = $isPaid && is_numeric($charge['paid_amount'] ?? null) && (int) $charge['paid_amount'] > 0
            ? $charge['paid_amount']
            : ($charge['amount'] ?? $order['amount'] ?? null);

        return new NormalizedWebhookEventDTO(
            gatewayCode: $payload->gatewayCode,
            eventType: $this->normalizeEventType($type),
            externalEventId: $payload->externalEventId,
            // Sem recorrência no Pagar.me (renovação local).
            externalSubscriptionId: null,
            // ch_… (o id gravado no Payment); sem cobrança, o do pedido.
            externalPaymentId: $chargeId ?? $orderId,
            // or_…: casa faturas emitidas pela versão anterior (que gravava o pedido).
            externalInvoiceId: $chargeId !== null ? $orderId : null,
            status: $this->normalizeChargeStatus((string) ($charge['status'] ?? $order['status'] ?? '')) ?: null,
            amount: $this->centsToFloat($amount),
            currency: strtoupper((string) ($charge['currency'] ?? $order['currency'] ?? 'BRL')),
            metadata: $metadata,
            rawPayload: $payload->payload,
            occurredAt: $this->isoDate($payload->payload['created_at'] ?? null) ?? now()->toIso8601String(),
            dueDate: $this->boletoDueDate($charge),
            paymentUrl: $this->chargePaymentUrl($charge),
        );
    }

    /**
     * Eventos da v5 (https://docs.pagar.me/reference/eventos-de-webhook-1).
     *
     * Estado da cobrança vem dos eventos charge.*; dos order.* só o
     * order.paid (idempotente: mesmo ch_ do charge.paid → "duplicate") e o
     * order.canceled (não há charge.canceled). order.payment_failed fica só
     * em registro: com charge.payment_failed também assinado, a falha seria
     * aplicada duas vezes (dois eventos PaymentFailed / duas réguas).
     * subscription.* e invoice.* são da recorrência nativa, que não usamos.
     */
    protected function eventTypeMap(): array
    {
        return [
            'order.paid'            => 'paid',
            'order.canceled'        => 'payment_cancelled',
            'order.payment_failed'  => 'unknown',
            'charge.created'        => 'created',
            'charge.pending'        => 'created',
            'charge.paid'           => 'paid',
            'charge.overpaid'       => 'paid',
            'charge.payment_failed' => 'failed',
            'charge.refunded'       => 'refunded',
            'charge.chargedback'    => 'chargeback',
            'chargeback.received'   => 'chargeback',
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Cobrança do pedido: no order.paid, a paga; no order.canceled, a
     * cancelada; senão a primeira (o EasyEye cria pedidos de uma cobrança).
     */
    private function pickCharge(array $order, ?string $type): array
    {
        $charges = array_values(array_filter((array) ($order['charges'] ?? []), 'is_array'));

        if ($charges === []) {
            return [];
        }

        $wanted = match ($type) {
            'order.paid'     => ['paid', 'overpaid'],
            'order.canceled' => ['canceled'],
            default          => [],
        };

        foreach ($charges as $charge) {
            if (in_array(strtolower((string) ($charge['status'] ?? '')), $wanted, true)) {
                return $charge;
            }
        }

        return $charges[0];
    }

    /**
     * chargeback.received substitui o charge.chargedback (migração até
     * 30/09/2026), mas o payload dele não está documentado: procura a
     * cobrança nos formatos prováveis; `data` com status chargedback é a
     * própria cobrança (formato do charge.chargedback). Sem ch_, ignorado.
     */
    private function chargeOfChargeback(array $data): array
    {
        if (is_array($data['charge'] ?? null)) {
            return $data['charge'];
        }

        $chargeId = $this->extractString($data, ['charge_id', 'charge.id']);

        if ($chargeId !== null) {
            return ['id' => $chargeId, 'status' => 'chargedback', 'amount' => $data['amount'] ?? null];
        }

        $id = $this->extractString($data, ['id']);

        return $id !== null && str_starts_with($id, 'ch_') ? $data : [];
    }

    /** Boleto: página (url), depois PDF; Pix: imagem do QR Code. */
    private function chargePaymentUrl(array $charge): ?string
    {
        return $this->extractPaymentUrl($charge, [
            'last_transaction.url',
            'last_transaction.pdf',
            'last_transaction.qr_code_url',
        ]);
    }

    /** Só o boleto tem vencimento próprio; o Pix tem expiração, não vencimento. */
    private function boletoDueDate(array $charge): ?string
    {
        $method = strtolower((string) ($charge['payment_method'] ?? Arr::get($charge, 'last_transaction.transaction_type', '')));

        if ($method !== 'boleto') {
            return null;
        }

        $due = $this->extractString($charge, ['last_transaction.due_at', 'due_at']);

        return $due !== null && preg_match('/^\d{4}-\d{2}-\d{2}/', $due) ? substr($due, 0, 10) : null;
    }

    private function chargeFailureMessage(array $charge): string
    {
        $messages = [];

        foreach ((array) Arr::get($charge, 'last_transaction.gateway_response.errors', []) as $error) {
            $message = is_array($error) ? ($error['message'] ?? null) : $error;

            if (is_string($message) && $message !== '') {
                $messages[] = $message;
            }
        }

        if ($messages === [] && is_string($acquirer = Arr::get($charge, 'last_transaction.acquirer_message')) && $acquirer !== '') {
            $messages[] = $acquirer;
        }

        return mb_substr($messages !== [] ? implode(' | ', $messages) : __('checkout.decline.generic'), 0, 1000);
    }

    /**
     * Erro da API ({"message": "...", "errors": {"campo": ["..."]}}) em uma
     * linha. O eco `request` (com os dados enviados) fica de fora.
     */
    private function errorMessage(Response $response): string
    {
        $json  = $response->json();
        $parts = [];

        if (is_array($json)) {
            if (is_string($json['message'] ?? null) && $json['message'] !== '') {
                $parts[] = $json['message'];
            }

            foreach ((array) ($json['errors'] ?? []) as $field => $messages) {
                $text    = implode('; ', array_filter(Arr::flatten((array) $messages), 'is_string'));
                $parts[] = is_string($field) ? "{$field}: {$text}" : $text;
            }
        }

        $message = $parts !== [] ? implode(' | ', array_filter($parts)) : $response->body();

        return mb_substr("HTTP {$response->status()}: {$message}", 0, 1000);
    }

    protected function sanitizePayload(array $payload): array
    {
        unset($payload['request']);

        return parent::sanitizePayload($payload);
    }

    /**
     * Status da cobrança (pending, paid, canceled, processing, failed,
     * overpaid, underpaid, chargedback) e das transações Pix/boleto
     * (waiting_payment, generated, with_error, voided...) → vocabulário de
     * PaymentStatus::fromGatewayStatus. underpaid fica como está (pendente:
     * pago a menos não quita a fatura).
     */
    private function normalizeChargeStatus(string $status): string
    {
        return match (strtolower($status)) {
            'paid', 'overpaid' => 'paid',
            'pending', 'processing', 'waiting_payment', 'generated' => 'pending',
            'failed', 'with_error', 'not_authorized' => 'failed',
            'canceled', 'voided' => 'cancelled',
            'refunded'    => 'refunded',
            'chargedback' => 'chargeback',
            default       => strtolower($status),
        };
    }

    private function centsToFloat(mixed $cents): ?float
    {
        return is_numeric($cents) ? round(((float) $cents) / 100, 2) : null;
    }

    private function isoDate(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            // Datas da API v5 são UTC.
            return CarbonImmutable::parse($value, 'UTC')->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }

    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) === $name && is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
