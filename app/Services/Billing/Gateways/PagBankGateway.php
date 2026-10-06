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
    SavedCardDTO,
};
use App\Exceptions\Billing\GatewayIntegrationException;
use App\Models\Entity;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\{Cache, Log, Route};
use Throwable;

/**
 * PagBank — API de Pedidos e Pagamentos (Order).
 *
 * - Ambientes: produção https://api.pagseguro.com e sandbox
 *   https://sandbox.api.pagseguro.com (PAGBANK_BASE_URL).
 *   https://developer.pagbank.com.br/docs/ambientes-disponiveis
 * - Autenticação: Authorization: Bearer <token da conta>.
 *   https://developer.pagbank.com.br/docs/token-de-autenticacao
 * - Cobrança: POST /orders com o comprador embutido (não há cadastro de
 *   cliente na API de Pedidos) e uma cobrança em `charges` (BOLETO ou PIX).
 *   https://developer.pagbank.com.br/reference/criar-pedido
 * - Webhook: o PagBank só notifica o pedido que trouxe `notification_urls`;
 *   o corpo é o próprio pedido e o header x-authenticity-token é
 *   sha256("{token}-{corpo}").
 *   https://developer.pagbank.com.br/reference/webhooks
 *   https://developer.pagbank.com.br/reference/confirmar-autenticidade-da-notificacao
 *
 * Renovação local: a recorrência do PagBank (API de Pagamentos Recorrentes,
 * outro host e só cartão/PJ) não é usada — cada ciclo é um pedido avulso
 * emitido pelo RenewSubscriptionJob.
 */
class PagBankGateway extends AbstractHttpGateway
{
    /**
     * Endpoints da API de Pedidos. config/billing.php sobrescreve pela mesma
     * chave; ausentes lá, valem estes (a chave antiga `charges` => /charges
     * é a API de cobrança avulsa, que não aceita o corpo de um pedido).
     */
    private const ENDPOINTS = [
        'orders'      => '/orders',
        'order_show'  => '/orders/{id}',
        'payments'    => '/charges/{id}',
        'public_keys' => '/public-keys',
    ];

    /** SDK oficial de criptografia do cartão (https://developer.pagbank.com.br/docs/criptografia-e-chave-publica). */
    public const SDK_URL = 'https://assets.pagseguro.com.br/checkout-sdk-js/rc/dist/browser/pagseguro.min.js';

    /** Unidades federativas por nome (sem acento, maiúsculo) — o holder do boleto exige a sigla. */
    private const UF_BY_NAME = [
        'ACRE'           => 'AC', 'ALAGOAS' => 'AL', 'AMAPA' => 'AP', 'AMAZONAS' => 'AM', 'BAHIA' => 'BA',
        'CEARA'          => 'CE', 'DISTRITO FEDERAL' => 'DF', 'ESPIRITO SANTO' => 'ES', 'GOIAS' => 'GO',
        'MARANHAO'       => 'MA', 'MATO GROSSO' => 'MT', 'MATO GROSSO DO SUL' => 'MS', 'MINAS GERAIS' => 'MG',
        'PARA'           => 'PA', 'PARAIBA' => 'PB', 'PARANA' => 'PR', 'PERNAMBUCO' => 'PE', 'PIAUI' => 'PI',
        'RIO DE JANEIRO' => 'RJ', 'RIO GRANDE DO NORTE' => 'RN', 'RIO GRANDE DO SUL' => 'RS',
        'RONDONIA'       => 'RO', 'RORAIMA' => 'RR', 'SANTA CATARINA' => 'SC', 'SAO PAULO' => 'SP',
        'SERGIPE'        => 'SE', 'TOCANTINS' => 'TO',
    ];

    public function code(): string
    {
        return 'pagbank';
    }

    // ── Cliente ──────────────────────────────────────────────────────────────

    /**
     * A API de Pedidos não tem cadastro de cliente: o comprador (customer) vai
     * dentro de cada pedido. O id local da empresa serve de referência.
     * https://developer.pagbank.com.br/reference/objeto-order.
     */
    public function upsertCustomer(CustomerDTO $customer): string
    {
        return $customer->entityId;
    }

    // ── Assinatura (renovação local) ─────────────────────────────────────────

    /**
     * Sem recorrência no gateway: externalSubscriptionId nulo faz a
     * renovação local emitir cada ciclo (ver PaymentGatewayInterface). A API
     * de Pagamentos Recorrentes do PagBank fica em outro host
     * (api.assinaturas.pagseguro.com), cobra só cartão e é restrita a PJ —
     * o antigo /pre-approvals era a API v2 legada.
     * https://developer.pagbank.com.br/docs/pagamentos-recorrentes.
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

    /** Nada a cancelar no gateway: a recorrência é local. */
    public function cancelSubscription(CancelSubscriptionDTO $payload): CancelSubscriptionResultDTO
    {
        return new CancelSubscriptionResultDTO(
            success: true,
            status: 'cancelled',
            rawResponse: ['note' => 'managed_locally'],
        );
    }

    // ── Cobrança (pedido) ────────────────────────────────────────────────────

    /**
     * Cria o pedido com uma cobrança (boleto ou Pix).
     * externalPaymentId = id do pedido (ORDE_…): é o `id` de toda notificação
     * do pedido, inclusive no Pix antigo, em que a cobrança só nasce no
     * pagamento.
     */
    public function createCharge(CreateChargeDTO $payload): CreateChargeResultDTO
    {
        // tax_id do comprador é obrigatório no pedido (CPF 11 / CNPJ 14).
        // https://developer.pagbank.com.br/reference/criar-pedido
        $taxId = $this->digits($payload->metadata['document'] ?? null);

        if (! in_array(strlen($taxId), [11, 14], true)) {
            return new CreateChargeResultDTO(
                success: false,
                externalPaymentId: null,
                status: null,
                amount: null,
                rawResponse: [],
                errorCode: '422',
                errorMessage: 'PagBank: CPF/CNPJ do pagador ausente ou inválido (customer.tax_id é obrigatório).',
            );
        }

        $response = $this->request(
            method: 'POST',
            endpointKey: 'orders',
            payload: $this->buildChargePayload($payload),
            idempotencyKey: $this->idempotencyKey($payload->idempotencyKey),
        );

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
        $charge = $this->firstCharge($order);

        return new CreateChargeResultDTO(
            success: true,
            externalPaymentId: is_string($order['id'] ?? null) ? $order['id'] : null,
            // Sem cobrança ainda (Pix por qr_codes no pedido): aguardando.
            status: $charge === [] ? 'pending' : $this->paymentStatus($charge),
            amount: $this->amountOf($order, $charge),
            rawResponse: $this->sanitizePayload($order),
            paymentUrl: $this->paymentUrlFrom($order),
        );
    }

    /**
     * Sem cancelamento documentado para boleto/Pix pendente: o "Cancelar
     * pagamento" (POST /charges/{id}/cancel) desfaz pré-autorização ou estorna
     * pagamento capturado — não cancela cobrança em aberto
     * (https://developer.pagbank.com.br/reference/cancelar-pagamento). O Pix
     * expira sozinho (expiration_date) e o boleto vence; o chamador avisa o time.
     */
    public function cancelCharge(string $externalPaymentId): bool
    {
        return false;
    }

    /**
     * Pedido (ORDE_…) → GET /orders/{id}; cobrança (CHAR_…) → GET /charges/{id}.
     * https://developer.pagbank.com.br/reference/consultar-pedido
     * https://developer.pagbank.com.br/reference/consultar-pagamento.
     */
    public function fetchPayment(string $externalPaymentId): array
    {
        $endpoint = str_starts_with(strtoupper($externalPaymentId), 'CHAR_') ? 'payments' : 'order_show';
        $response = $this->request('GET', $endpoint, [], ['id' => rawurlencode($externalPaymentId)]);

        if (! $response->successful()) {
            throw GatewayIntegrationException::fromHttpStatus($this->code(), $response->status(), $response->body());
        }

        return $this->sanitizePayload($response->json() ?? []);
    }

    /**
     * Corpo do POST /orders.
     *
     * - Boleto exige holder com endereço completo (rua, número, bairro,
     *   cidade, UF, país, CEP); sem endereço da empresa, a cobrança sai em
     *   Pix, que só pede o comprador.
     *   https://developer.pagbank.com.br/reference/criar-pagar-pedido-com-boleto
     * - Pix: payment_method.type PIX com pix.expiration_date (o QR Code não
     *   pago expira e a cobrança vira CANCELED). Vale até o fim do dia do
     *   vencimento, no horário de Brasília.
     *   https://developer.pagbank.com.br/reference/criar-pedido-com-qr-code-pix-v2
     * - reference_id do pedido e da cobrança = id da nossa fatura (volta em
     *   toda notificação).
     */
    protected function buildChargePayload(CreateChargeDTO $payload): array
    {
        $amountCents = (int) round($payload->amount * 100);
        $document    = $this->digits($payload->metadata['document'] ?? null);
        $name        = $this->limit((string) ($payload->metadata['customer_name'] ?? ''), 120) ?: 'Cliente';
        $email       = $this->limit((string) ($payload->metadata['email'] ?? ''), 255);
        $address     = $this->resolveHolderAddress($payload);
        $useBoleto   = strtolower((string) $payload->paymentMethod) !== 'pix' && $address !== null;

        $customer = array_filter([
            'name'   => $name,
            'email'  => $email !== '' ? $email : null,
            'tax_id' => $document,
            'phones' => $this->phones($payload->metadata['phone'] ?? null),
        ], fn ($value) => $value !== null);

        $paymentMethod = $useBoleto
            ? [
                'type'   => 'BOLETO',
                'boleto' => [
                    'due_date'          => $this->dueDate($payload)->toDateString(),
                    'instruction_lines' => [
                        'line_1' => $this->limit("Pagamento: {$payload->description}", 75),
                        'line_2' => 'Assinatura EasyEye',
                    ],
                    'holder' => array_filter([
                        'name'    => $name,
                        'tax_id'  => $document,
                        'email'   => $email !== '' ? $email : null,
                        'address' => $address,
                    ], fn ($value) => $value !== null),
                ],
            ]
            : [
                'type' => 'PIX',
                'pix'  => ['expiration_date' => $this->pixExpiration($payload)],
            ];

        if (! $useBoleto && strtolower((string) $payload->paymentMethod) !== 'pix') {
            Log::info('PagBank: cobrança emitida em Pix — boleto exige o endereço completo do pagador.', [
                'invoice_id' => $payload->invoiceId,
                'entity_id'  => $payload->entityId,
            ]);
        }

        return array_filter([
            'reference_id' => $payload->invoiceId,
            'customer'     => $customer,
            'items'        => [[
                'reference_id' => $payload->invoiceId,
                'name'         => $this->limit($payload->description, 64) ?: 'Assinatura EasyEye',
                'quantity'     => 1,
                'unit_amount'  => $amountCents,
            ]],
            'charges' => [[
                'reference_id'   => $payload->invoiceId,
                'description'    => $this->limit($payload->description, 64),
                'amount'         => ['value' => $amountCents, 'currency' => 'BRL'],
                'payment_method' => $paymentMethod,
            ]],
            'notification_urls' => $this->notificationUrls(),
        ], fn ($value) => $value !== null);
    }

    // ── Checkout transparente ────────────────────────────────────────────────

    public function transparentMethods(): array
    {
        return ['pix', 'boleto', 'credit_card'];
    }

    /**
     * Pix: charges[].qr_code.text (Pix v2 —
     * https://developer.pagbank.com.br/reference/criar-pedido-com-qr-code-pix-v2)
     * ou qr_codes[].text (pedido antigo), link QRCODE.PNG e expiration_date
     * (https://developer.pagbank.com.br/reference/criar-pedido-pedido-com-qr-code);
     * boleto: payment_method.boleto.formatted_barcode/barcode/due_date e o
     * link application/pdf da cobrança
     * (https://developer.pagbank.com.br/reference/criar-pagar-pedido-com-boleto).
     * Do pedido guardado na emissão; sem os dados, GET /orders/{id}.
     */
    public function paymentInstructions(string $method, string $externalPaymentId, array $chargePayload = []): ?PaymentInstructionsDTO
    {
        $instructions = $this->instructionsFromOrder($method, $chargePayload);

        if ($instructions === null && str_starts_with(strtoupper($externalPaymentId), 'ORDE_')) {
            $instructions = $this->instructionsFromOrder($method, $this->fetchPayment($externalPaymentId));
        }

        return $instructions;
    }

    private function instructionsFromOrder(string $method, array $order): ?PaymentInstructionsDTO
    {
        [, $charge] = $this->orderAndCharge($order);

        if ($method === 'pix') {
            $qr    = is_array($order['qr_codes'][0] ?? null) ? $order['qr_codes'][0] : (is_array($order['qr_code'][0] ?? null) ? $order['qr_code'][0] : []);
            $pix   = (array) Arr::get($charge, 'payment_method.pix', []);
            $links = array_merge((array) ($qr['links'] ?? []), (array) ($charge['links'] ?? []));

            if ($qr === [] && strtoupper((string) Arr::get($charge, 'payment_method.type', '')) !== 'PIX') {
                return null;
            }

            return PaymentInstructionsDTO::pix(
                // Pix v2 (charges[].payment_method PIX): charges[].qr_code.text.
                copyPaste: $this->extractString($qr, ['text']) ?? $this->extractString($charge, ['qr_code.text']) ?? $this->extractString($pix, ['text']),
                qrImageUrl: $this->linkHref($links, 'QRCODE.PNG'),
                expiresAt: $this->extractString($qr, ['expiration_date']) ?? $this->extractString($pix, ['expiration_date']),
            );
        }

        if ($method === 'boleto' && strtoupper((string) Arr::get($charge, 'payment_method.type', '')) === 'BOLETO') {
            $boleto = (array) Arr::get($charge, 'payment_method.boleto', []);
            $pdf    = null;

            foreach ((array) ($charge['links'] ?? []) as $link) {
                if (is_array($link) && strtolower((string) ($link['media'] ?? '')) === 'application/pdf') {
                    $pdf = $this->httpUrl($link['href'] ?? null);

                    break;
                }
            }

            return PaymentInstructionsDTO::boleto(
                digitableLine: $this->extractString($boleto, ['formatted_barcode']),
                barcode: $this->extractString($boleto, ['barcode']),
                pdfUrl: $pdf,
                dueDate: $this->extractString($boleto, ['due_date']),
            );
        }

        return null;
    }

    private function linkHref(array $links, string $rel): ?string
    {
        foreach ($links as $link) {
            if (is_array($link) && strtoupper((string) ($link['rel'] ?? '')) === $rel) {
                return $this->httpUrl($link['href'] ?? null);
            }
        }

        return null;
    }

    /**
     * PagSeguro.encryptCard({publicKey, holder, number, expMonth, expYear,
     * securityCode}) criptografa o cartão no navegador. Chave pública:
     * credencial/config ou POST /public-keys {"type":"card"} (não expira;
     * guardada em cache) — https://developer.pagbank.com.br/reference/criar-chave-publica.
     */
    public function cardCheckoutConfig(): ?CardCheckoutConfigDTO
    {
        return new CardCheckoutConfigDTO(
            gateway: $this->code(),
            publicKey: $this->publicKey() ?? $this->fetchPublicKey(),
            sdkUrl: self::SDK_URL,
            tokenization: 'encrypted_card',
            maxInstallments: $this->cardMaxInstallments(),
        );
    }

    /** Chave pública configurada ou obtida na API (POST /public-keys, em cache). */
    protected function hasCardPublicKey(): bool
    {
        return $this->publicKey() !== null || $this->fetchPublicKey() !== null;
    }

    /**
     * Sem troca avulsa do cartão: o cartão só é guardado (store) junto de um
     * pagamento (POST /orders) — o novo cartão entra no próximo pagamento.
     */
    public function supportsCardReplacement(): bool
    {
        return false;
    }

    private function fetchPublicKey(): ?string
    {
        $cacheKey = 'billing:pagbank:public_key:' . hash('sha256', (string) $this->resolveSecret());

        $key = Cache::get($cacheKey);

        if (is_string($key) && $key !== '') {
            return $key;
        }

        try {
            $response = $this->request('POST', 'public_keys', ['type' => 'card']);
        } catch (GatewayIntegrationException) {
            return null;
        }

        $key = $response->successful() ? $response->json('public_key') : null;

        if (! is_string($key) || $key === '') {
            Log::warning('PagBank: não foi possível obter a chave pública do cartão.', ['status' => $response->status()]);

            return null;
        }

        Cache::put($cacheKey, $key, now()->addDay());

        return $key;
    }

    /**
     * POST /orders com charges[].payment_method CREDIT_CARD (capture true,
     * parcelas sem juros = valor original e installments N —
     * https://developer.pagbank.com.br/reference/criar-transacao-com-repasse-de-taxa).
     * Cartão criptografado (card.encrypted) com store=true devolve card.id;
     * recorrência: 1ª INITIAL, renovação SUBSEQUENT com card.id sem CVV
     * (https://developer.pagbank.com.br/reference/criar-pagar-pedido-com-recorrencia).
     */
    public function chargeCard(CardChargeDTO $payload): CreateChargeResultDTO
    {
        $taxId       = $this->digits($payload->payer->document);
        $amountCents = (int) round($payload->amount * 100);

        if (! in_array(strlen($taxId), [11, 14], true)) {
            return new CreateChargeResultDTO(
                success: false,
                externalPaymentId: null,
                status: null,
                amount: null,
                rawResponse: [],
                errorCode: '422',
                errorMessage: 'PagBank: CPF/CNPJ do pagador ausente ou inválido (customer.tax_id é obrigatório).',
            );
        }

        $card = filled($payload->savedCardId)
            ? ['id' => $payload->savedCardId, 'store' => true, 'holder' => ['name' => $this->limit($payload->payer->name, 30), 'tax_id' => $taxId]]
            : ['encrypted' => (string) $payload->cardToken, 'store' => $payload->saveCard];

        $recurring = match (true) {
            $payload->offSession => ['type' => 'SUBSEQUENT'],
            $payload->saveCard   => ['type' => 'INITIAL'],
            default              => null,
        };

        $order = array_filter([
            'reference_id' => $payload->invoiceId,
            'customer'     => array_filter([
                'name'   => $this->limit($payload->payer->name, 120) ?: 'Cliente',
                'email'  => $payload->payer->email ? $this->limit($payload->payer->email, 255) : null,
                'tax_id' => $taxId,
                'phones' => $this->phones($payload->payer->phone),
            ], fn ($value) => $value !== null),
            'items' => [[
                'reference_id' => $payload->invoiceId,
                'name'         => $this->limit($payload->description, 64) ?: 'Assinatura EasyEye',
                'quantity'     => 1,
                'unit_amount'  => $amountCents,
            ]],
            'charges' => [array_filter([
                'reference_id'   => $payload->invoiceId,
                'description'    => $this->limit($payload->description, 64),
                'amount'         => ['value' => $amountCents, 'currency' => 'BRL'],
                'payment_method' => [
                    'type'            => 'CREDIT_CARD',
                    'installments'    => $payload->offSession ? 1 : max(1, $payload->installments),
                    'capture'         => true,
                    'soft_descriptor' => 'EASYEYE',
                    'card'            => $card,
                ],
                'recurring' => $recurring,
            ], fn ($value) => $value !== null)],
            'notification_urls' => $this->notificationUrls(),
        ], fn ($value) => $value !== null);

        $response = $this->request('POST', 'orders', $order, [], $this->idempotencyKey($payload->idempotencyKey));

        if (! $response->successful()) {
            return $this->cardFailure($response, $this->errorMessage($response));
        }

        $json     = $response->json() ?? [];
        $charge   = $this->firstCharge($json);
        $status   = $charge === [] ? 'pending' : $this->paymentStatus($charge);
        $cardData = (array) Arr::get($charge, 'payment_method.card', []);

        return $this->cardResult(
            response: $response,
            externalId: is_string($json['id'] ?? null) ? $json['id'] : null,
            status: $status,
            amount: $this->amountOf($json, $charge),
            json: $json,
            savedCard: SavedCardDTO::make($cardData['id'] ?? $payload->savedCardId, $cardData['brand'] ?? null, $cardData['last_digits'] ?? null),
            declineMessage: $status === 'failed' ? $this->limit((string) Arr::get($charge, 'payment_response.message') ?: __('checkout.decline.generic'), 255) : null,
        );
    }

    // ── Webhook ──────────────────────────────────────────────────────────────

    /**
     * x-authenticity-token = sha256("{token}-{corpo cru}"), com o token da
     * conta PagBank (o mesmo do Bearer). Aceita o webhook_secret configurado
     * ou, sem ele, o token da API. Sem token ou sem header: rejeita.
     * https://developer.pagbank.com.br/reference/confirmar-autenticidade-da-notificacao.
     */
    public function validateWebhookSignature(GatewayWebhookInputDTO $payload): bool
    {
        $provided = $payload->headers['x-authenticity-token']
            ?? $payload->headers['X-Authenticity-Token']
            ?? null;

        if (! is_string($provided) || trim($provided) === '') {
            return false;
        }

        $provided = strtolower(trim($provided));
        $tokens   = array_unique(array_filter(
            [$this->resolveWebhookSecret(), $this->resolveSecret()],
            fn ($token) => is_string($token) && $token !== '',
        ));

        foreach ($tokens as $token) {
            if (hash_equals(hash('sha256', $token . '-' . $payload->body), $provided)) {
                return true;
            }
        }

        return false;
    }

    /**
     * O corpo da notificação é o pedido (mesmo formato da resposta da API),
     * sem campo de tipo de evento: o status da cobrança diz o que aconteceu.
     * https://developer.pagbank.com.br/reference/webhooks.
     */
    public function parseWebhook(GatewayWebhookInputDTO $payload): NormalizedWebhookEventDTO
    {
        $data               = $payload->payload;
        [$orderId, $charge] = $this->orderAndCharge($data);

        $eventType   = $charge === [] ? ($orderId !== null ? 'created' : 'unknown') : $this->eventType($charge);
        $referenceId = $data['reference_id'] ?? $charge['reference_id'] ?? null;

        return new NormalizedWebhookEventDTO(
            gatewayCode: $payload->gatewayCode,
            eventType: $eventType,
            externalEventId: $payload->externalEventId,
            externalSubscriptionId: null,
            externalPaymentId: $orderId,
            externalInvoiceId: null,
            status: $charge === [] ? ($orderId !== null ? 'pending' : null) : $this->paymentStatus($charge),
            amount: $this->amountOf($data, $charge),
            currency: 'BRL',
            metadata: is_string($referenceId) && $referenceId !== '' ? ['invoice_id' => $referenceId] : [],
            rawPayload: $payload->payload,
            occurredAt: $this->occurredAt($charge, $data),
            dueDate: $this->chargeDueDate($charge),
            paymentUrl: $this->paymentUrlFrom($data),
        );
    }

    /**
     * Sem id de evento: o id do pedido se repete em cada mudança (WAITING →
     * PAID → estorno). A chave junta pedido, cobrança, status e valor
     * estornado — mudanças diferentes entram; o reenvio da mesma, não.
     */
    public function webhookEventKey(array $payload): ?string
    {
        [$orderId, $charge] = $this->orderAndCharge($payload);

        return $this->composeWebhookEventKey([
            $orderId,
            $charge['id'] ?? null,
            $charge['status'] ?? null,
            $charge['amount']['summary']['refunded'] ?? null,
        ]);
    }

    // ── Infraestrutura ───────────────────────────────────────────────────────

    protected function buildEndpoint(string $endpointKey, array $replacements = []): string
    {
        $baseUrl  = rtrim((string) $this->gatewayConfig('base_url'), '/');
        $endpoint = (string) (Arr::get((array) $this->gatewayConfig('endpoints'), $endpointKey) ?: (self::ENDPOINTS[$endpointKey] ?? ''));

        foreach ($replacements as $key => $value) {
            $endpoint = str_replace('{' . $key . '}', (string) $value, $endpoint);
        }

        return $baseUrl . $endpoint;
    }

    /**
     * x-idempotency-key: só letras, números, "_" e "-", até 200 caracteres
     * (o PagBank responde 40002 "must match the pattern [\w-]+" a outro
     * formato; chave repetida em até 48 h = 409/40005). Nossas chaves têm ":"
     * — viram o sha256 delas, estável entre tentativas.
     * https://developer.pagbank.com.br/reference/criar-assinatura (parâmetro x-idempotency-key)
     * https://developer.pagbank.com.br/reference/codigos-de-erro-order.
     */
    private function idempotencyKey(?string $key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }

        return preg_match('/^[A-Za-z0-9_-]{1,200}$/', $key) === 1 ? $key : hash('sha256', $key);
    }

    /**
     * URL do webhook (uma só, HTTPS). billing.gateways.pagbank.notification_url
     * sobrescreve a rota billing.webhooks; URL sem https não vai (o PagBank
     * exige SSL) e o pedido fica sem notificação.
     */
    private function notificationUrls(): ?array
    {
        $url = (string) ($this->gatewayConfig('notification_url') ?? '');

        if ($url === '' && Route::has('billing.webhooks')) {
            $url = route('billing.webhooks', ['gateway' => $this->code()]);
        }

        return str_starts_with(strtolower($url), 'https://') ? [$url] : null;
    }

    /**
     * Mensagem legível de error_messages[] (padrão Order: error/description;
     * padrão Charge: code/description/parameter_name).
     * https://developer.pagbank.com.br/reference/codigos-de-erro-order.
     */
    private function errorMessage(Response $response): string
    {
        $messages = $response->json('error_messages');

        if (is_array($messages) && $messages !== []) {
            $parts = array_map(function ($error): string {
                $error = is_array($error) ? $error : [];
                $text  = trim(implode(' ', array_filter([
                    $error['code'] ?? null,
                    $error['error'] ?? null,
                    $error['description'] ?? null,
                ], 'is_scalar')));

                return isset($error['parameter_name']) && is_scalar($error['parameter_name'])
                    ? "{$text} ({$error['parameter_name']})"
                    : $text;
            }, $messages);

            return mb_substr("HTTP {$response->status()}: " . implode('; ', array_filter($parts)), 0, 1000);
        }

        return mb_substr($response->body(), 0, 1000);
    }

    // ── Leitura do pedido ────────────────────────────────────────────────────

    /**
     * [id do pedido, 1ª cobrança]. O corpo pode ser o pedido (ORDE_…) ou,
     * na API de cobrança avulsa, a própria cobrança (CHAR_…, com o pedido em
     * metadata.ps_order_id).
     *
     * @return array{0: ?string, 1: array<string, mixed>}
     */
    private function orderAndCharge(array $data): array
    {
        $id = is_string($data['id'] ?? null) ? $data['id'] : null;

        if ($id !== null && str_starts_with(strtoupper($id), 'CHAR_')) {
            $orderId = $data['metadata']['ps_order_id'] ?? null;

            return [is_string($orderId) && $orderId !== '' ? $orderId : $id, $data];
        }

        return [$id, $this->firstCharge($data)];
    }

    private function firstCharge(array $order): array
    {
        $charge = $order['charges'][0] ?? null;

        return is_array($charge) ? $charge : [];
    }

    /**
     * Status da cobrança → status interno (PaymentStatus::fromGatewayStatus).
     * CANCELED com valor estornado é estorno; sem, cobrança não paga
     * (expirada/cancelada). Estorno parcial mantém PAID.
     * https://developer.pagbank.com.br/reference/objeto-charge
     * https://developer.pagbank.com.br/reference/cancelar-pagamento.
     */
    private function paymentStatus(array $charge): string
    {
        return match (strtoupper((string) ($charge['status'] ?? ''))) {
            'PAID'       => 'paid',
            'AUTHORIZED' => 'authorized',
            'DECLINED'   => 'failed',
            'CANCELED'   => $this->refundedCents($charge) > 0 ? 'refunded' : 'cancelled',
            default      => 'pending', // WAITING, IN_ANALYSIS
        };
    }

    /** Tipo normalizado (ver AbstractHttpGateway::eventTypeMap). */
    private function eventType(array $charge): string
    {
        $status   = strtoupper((string) ($charge['status'] ?? ''));
        $refunded = $this->refundedCents($charge);

        return match ($status) {
            // Estorno parcial: segue PAID com summary.refunded > 0 — só
            // registra (não cancela a fatura paga).
            'PAID'       => $refunded > 0 ? 'unknown' : 'paid',
            'AUTHORIZED' => 'authorized',
            'DECLINED'   => 'failed',
            'CANCELED'   => $refunded > 0 ? 'refunded' : 'payment_cancelled',
            'WAITING', 'IN_ANALYSIS' => 'created',
            default => 'unknown',
        };
    }

    private function refundedCents(array $charge): int
    {
        $refunded = $charge['amount']['summary']['refunded'] ?? 0;

        return is_numeric($refunded) ? (int) $refunded : 0;
    }

    /** Valores em centavos: a cobrança, senão o QR Code do pedido. */
    private function amountOf(array $order, array $charge): ?float
    {
        $cents = $charge['amount']['value']
            ?? $order['qr_codes'][0]['amount']['value']
            ?? $order['qr_code'][0]['amount']['value']
            ?? null;

        return is_numeric($cents) ? ((float) $cents) / 100 : null;
    }

    /** Vencimento do boleto, ou a data (Brasília) de expiração do Pix. */
    private function chargeDueDate(array $charge): ?string
    {
        $method = $charge['payment_method'] ?? [];
        $value  = $method['boleto']['due_date'] ?? $method['pix']['expiration_date'] ?? null;

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->setTimezone('America/Sao_Paulo')->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function occurredAt(array $charge, array $order): string
    {
        $value = $charge['paid_at'] ?? $charge['created_at'] ?? $order['created_at'] ?? null;

        try {
            return is_string($value) && $value !== ''
                ? CarbonImmutable::parse($value)->toIso8601String()
                : now()->toIso8601String();
        } catch (Throwable) {
            return now()->toIso8601String();
        }
    }

    /**
     * Link de pagamento: o PDF do boleto (links da cobrança com media
     * application/pdf) ou a imagem do QR Code do Pix (rel QRCODE.PNG, nos
     * links da cobrança no Pix atual ou em qr_codes[].links no antigo). Os
     * demais links (SELF/PAY/CHARGE.CANCEL) são da API e exigem o token.
     */
    private function paymentUrlFrom(array $order): ?string
    {
        [, $charge] = $this->orderAndCharge($order);

        $groups = [
            $charge['links'] ?? [],
            $order['qr_codes'][0]['links'] ?? [],
            $order['qr_code'][0]['links'] ?? [],
        ];

        foreach (['pdf', 'png'] as $wanted) {
            foreach ($groups as $links) {
                foreach (is_array($links) ? $links : [] as $link) {
                    if (! is_array($link)) {
                        continue;
                    }

                    $matches = $wanted === 'pdf'
                        ? strtolower((string) ($link['media'] ?? '')) === 'application/pdf'
                        : strtoupper((string) ($link['rel'] ?? '')) === 'QRCODE.PNG';
                    $url = $matches ? $this->httpUrl($link['href'] ?? null) : null;

                    if ($url !== null) {
                        return $url;
                    }
                }
            }
        }

        return null;
    }

    // ── Montagem do pedido ───────────────────────────────────────────────────

    /**
     * Endereço do pagador do boleto, de metadata['address'] (chaves
     * street/number/complement/locality|district/city/region_code|state/
     * postal_code|zipcode) ou do cadastro da empresa. Incompleto = null.
     */
    private function resolveHolderAddress(CreateChargeDTO $payload): ?array
    {
        $source = $payload->metadata['address'] ?? null;

        if (! is_array($source)) {
            $source = $this->entityAddress($payload->entityId);
        }

        if ($source === null) {
            return null;
        }

        $uf      = $this->uf($source['region_code'] ?? $source['state'] ?? null);
        $address = [
            'street'      => $this->limit((string) ($source['street'] ?? $source['address'] ?? ''), 160),
            'number'      => $this->limit((string) ($source['number'] ?? ''), 20),
            'complement'  => $this->limit((string) ($source['complement'] ?? ''), 40),
            'locality'    => $this->limit((string) ($source['locality'] ?? $source['district'] ?? ''), 60),
            'city'        => $this->limit((string) ($source['city'] ?? ''), 90),
            'region'      => $uf,
            'region_code' => $uf,
            'country'     => 'BRA',
            'postal_code' => $this->digits($source['postal_code'] ?? $source['zipcode'] ?? null),
        ];

        foreach (['street', 'number', 'locality', 'city'] as $required) {
            if ($address[$required] === '') {
                return null;
            }
        }

        if ($uf === null || strlen($address['postal_code']) !== 8) {
            return null;
        }

        if ($address['complement'] === '') {
            unset($address['complement']);
        }

        return $address;
    }

    private function entityAddress(string $entityId): ?array
    {
        try {
            $entity = Entity::query()->find($entityId);
        } catch (Throwable) {
            return null;
        }

        return $entity ? [
            'street'      => $entity->address,
            'number'      => $entity->number,
            'complement'  => $entity->complement,
            'locality'    => $entity->district,
            'city'        => $entity->city,
            'state'       => $entity->state,
            'postal_code' => $entity->zipcode,
        ] : null;
    }

    private function uf(mixed $value): ?string
    {
        $value = strtoupper(trim((string) $value));
        $plain = strtoupper((string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value));

        if (in_array($value, self::UF_BY_NAME, true)) {
            return $value;
        }

        return self::UF_BY_NAME[$plain] ?? null;
    }

    /** Telefone BR em DDD + número (8–9 dígitos); senão, sem phones. */
    private function phones(mixed $phone): ?array
    {
        $digits = $this->digits($phone);

        if (strlen($digits) >= 12 && str_starts_with($digits, '55')) {
            $digits = substr($digits, 2);
        }

        if (! in_array(strlen($digits), [10, 11], true)) {
            return null;
        }

        return [[
            'country' => '55',
            'area'    => substr($digits, 0, 2),
            'number'  => substr($digits, 2),
            'type'    => strlen($digits) === 11 ? 'MOBILE' : 'BUSINESS',
        ]];
    }

    private function dueDate(CreateChargeDTO $payload): CarbonImmutable
    {
        $today = CarbonImmutable::today('America/Sao_Paulo');

        try {
            $due = $payload->dueDate ? CarbonImmutable::parse($payload->dueDate, 'America/Sao_Paulo')->startOfDay() : null;
        } catch (Throwable) {
            $due = null;
        }

        return $due !== null && $due->greaterThanOrEqualTo($today) ? $due : $today->addDays(3);
    }

    /** Fim do dia do vencimento (Brasília) — nunca no passado. */
    private function pixExpiration(CreateChargeDTO $payload): string
    {
        $expires = $this->dueDate($payload)->endOfDay();
        $minimum = CarbonImmutable::now('America/Sao_Paulo')->addHour();

        return ($expires->lessThan($minimum) ? $minimum : $expires)->format('Y-m-d\TH:i:sP');
    }

    private function digits(mixed $value): string
    {
        return preg_replace('/\D/', '', is_scalar($value) ? (string) $value : '') ?? '';
    }

    private function limit(string $value, int $max): string
    {
        return mb_substr(trim($value), 0, $max);
    }
}
