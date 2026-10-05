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
use App\Services\Billing\BillingLogService;
use Carbon\{Carbon, CarbonInterface};
use Illuminate\Http\Client\Response;
use Illuminate\Support\{Arr, Str};
use Throwable;

/**
 * Mercado Pago (API de Pagamentos, Checkout Transparente).
 *
 * Uso no EasyEye: renovação local — cada ciclo é um Pix avulso
 * (POST /v1/payments) e o webhook (tópico "payment") só traz o id; status,
 * valor e fatura (external_reference) vêm de GET /v1/payments/{id}.
 *
 * Documentação oficial usada em cada chamada:
 * - Clientes: https://www.mercadopago.com.br/developers/pt/reference/customers/_customers/post
 *   e https://www.mercadopago.com.br/developers/pt/reference/customers/_customers_search/get
 * - Pagamento: https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-api-payments/create-payment/post
 * - Pix: https://www.mercadopago.com.br/developers/en/docs/checkout-api-payments/integration-configuration/integrate-pix
 * - Webhooks/assinatura: https://www.mercadopago.com.br/developers/pt/docs/your-integrations/notifications/webhooks
 * - Cancelamento/estorno: https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-api-payments/update-payment/put
 *   e https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-api-payments/create-refund/post
 * - Preapproval (assinaturas antigas): https://www.mercadopago.com.br/developers/pt/reference/online-payments/subscriptions/update-preapproval/put
 *   e https://www.mercadopago.com.br/developers/pt/reference/online-payments/subscriptions/get-preapproval/get
 */
class MercadoPagoGateway extends AbstractHttpGateway
{
    /**
     * Caminhos da API (config/billing.php sobrescreve). Os que faltam no
     * config (busca de cliente, estorno, consulta de preapproval) saem
     * daqui — sem eles a chamada ia para a raiz da API.
     */
    private const ENDPOINTS = [
        'customers'           => '/v1/customers',
        'customer_search'     => '/v1/customers/search',
        'charges'             => '/v1/payments',
        'payments'            => '/v1/payments/{id}',
        'payment_refunds'     => '/v1/payments/{id}/refunds',
        'subscription_show'   => '/preapproval/{id}',
        'subscription_cancel' => '/preapproval/{id}',
        'customer_cards'      => '/v1/customers/{id}/cards',
        'card_tokens'         => '/v1/card_tokens',
    ];

    /** MercadoPago.js v2 (Card Payment Brick / Core Methods). */
    public const SDK_URL = 'https://sdk.mercadopago.com/js/v2';

    /**
     * Pix: vencimento entre 30 minutos e 30 dias da emissão. Boleto: entre 1
     * e 30 dias. Margens para o relógio do Mercado Pago.
     */
    private const PIX_MIN_EXPIRATION_MINUTES = 35;

    private const MAX_EXPIRATION_DAYS = 30;

    /** Dado pessoal não vai para o metadata do pagamento (já vai em payer). */
    private const PII_METADATA_KEYS = ['email', 'customer_name', 'document', 'phone', 'name', 'cpf', 'cnpj', 'address'];

    public function code(): string
    {
        return 'mercadopago';
    }

    // ── Clientes ─────────────────────────────────────────────────────────────

    /**
     * Busca o cliente pelo e-mail (GET /v1/customers/search) e cria se não
     * houver (POST /v1/customers). E-mail é obrigatório nos dois.
     */
    public function upsertCustomer(CustomerDTO $customer): string
    {
        $email = $this->validEmail($customer->email);

        if ($email === null) {
            throw new GatewayIntegrationException(
                "[{$this->code()}] O Mercado Pago exige o e-mail do cliente (pagador do Pix). Cadastre um e-mail válido na empresa.",
                'invalid_request',
            );
        }

        if (($existing = $this->findCustomerIdByEmail($email)) !== null) {
            return $existing;
        }

        $response = $this->post('customers', $this->buildCustomerPayload($customer));

        if ($response->successful() && filled($response->json('id'))) {
            return (string) $response->json('id');
        }

        // 101 "the customer already exist": criado em paralelo — busca de novo.
        if ($response->status() === 400 && $this->hasErrorCause($response, '101')
            && ($existing = $this->findCustomerIdByEmail($email)) !== null) {
            return $existing;
        }

        throw GatewayIntegrationException::fromHttpStatus($this->code(), $response->status(), $response->body());
    }

    private function findCustomerIdByEmail(string $email): ?string
    {
        $search = $this->get('customer_search', ['email' => $email]);

        // Credencial inválida, limite ou instabilidade: criar às cegas
        // duplicaria o cliente (ou falharia igual). Outro 4xx: segue para criar.
        if ($search->status() === 401 || $search->status() === 403 || $search->status() === 429 || $search->serverError()) {
            throw GatewayIntegrationException::fromHttpStatus($this->code(), $search->status(), $search->body());
        }

        if (! $search->successful()) {
            return null;
        }

        foreach ((array) $search->json('results', []) as $result) {
            if (is_array($result) && filled($result['id'] ?? null)) {
                return (string) $result['id'];
            }
        }

        return null;
    }

    protected function buildCustomerPayload(CustomerDTO $customer): array
    {
        return array_filter([
            'email'          => $this->validEmail($customer->email),
            'first_name'     => $this->cleanName($customer->name),
            'phone'          => $this->phonePayload($customer->phone),
            'identification' => $this->identificationPayload($customer->document),
            'metadata'       => ['entity_id' => $customer->entityId],
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);
    }

    // ── Assinaturas (renovação local) ────────────────────────────────────────

    /**
     * Sem recorrência no Mercado Pago: cada ciclo é cobrado por um Pix/boleto
     * avulso (createCharge) — o 1º pela ativação e os seguintes pela
     * renovação local (RenewSubscriptionJob), no valor e no ciclo contratados.
     *
     * Não cria preapproval: sem meio de pagamento ele nasce 'pending' e só
     * cobra depois que o pagador o conclui no checkout (init_point), que o
     * sistema não mostra. O id dele marcaria uma recorrência que não cobra
     * (a assinatura sairia da renovação local e nenhum ciclo seguinte seria
     * cobrado) e, se o pagador o concluísse, cobraria em duplicidade com o Pix.
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
     * Só para preapproval gravado em assinatura antiga (antes da renovação
     * local): PUT /preapproval/{id} com status "canceled" (grafia da
     * referência atual da API; estado irreversível).
     */
    public function cancelSubscription(CancelSubscriptionDTO $payload): CancelSubscriptionResultDTO
    {
        if (blank($payload->externalSubscriptionId)) {
            return new CancelSubscriptionResultDTO(success: true, status: 'cancelled', rawResponse: []);
        }

        $response = $this->request('PUT', 'subscription_cancel', ['status' => 'canceled'], ['id' => $payload->externalSubscriptionId]);

        if (! $response->successful()) {
            return new CancelSubscriptionResultDTO(
                success: false,
                status: null,
                rawResponse: $response->json() ?? [],
                errorMessage: $this->errorMessage($response),
            );
        }

        return new CancelSubscriptionResultDTO(success: true, status: 'cancelled', rawResponse: $response->json() ?? []);
    }

    protected function cancelMethod(): string
    {
        return 'PUT';
    }

    // ── Cobranças ────────────────────────────────────────────────────────────

    /**
     * POST /v1/payments (Pix por padrão). X-Idempotency-Key é obrigatório:
     * a mesma chave devolve o mesmo pagamento em vez de criar outro.
     */
    public function createCharge(CreateChargeDTO $payload): CreateChargeResultDTO
    {
        if (strtoupper($payload->currency) !== 'BRL') {
            return new CreateChargeResultDTO(
                success: false,
                externalPaymentId: null,
                status: null,
                amount: null,
                rawResponse: [],
                errorCode: 'unsupported_currency',
                errorMessage: "[{$this->code()}] Pix/boleto só em BRL (recebido: {$payload->currency}).",
            );
        }

        $response = $this->request(
            method: 'POST',
            endpointKey: 'charges',
            payload: $this->buildChargePayload($payload),
            idempotencyKey: $payload->idempotencyKey ?: (string) Str::uuid(),
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

        $json = $response->json() ?? [];

        return new CreateChargeResultDTO(
            success: true,
            externalPaymentId: filled($json['id'] ?? null) ? (string) $json['id'] : null,
            status: $this->normalizeMpPaymentStatus((string) ($json['status'] ?? '')),
            amount: isset($json['transaction_amount']) ? (float) $json['transaction_amount'] : null,
            rawResponse: $this->sanitizePayload($json),
            // Pix: página com QR Code e copia-e-cola; boleto: external_resource_url.
            paymentUrl: $this->extractPaymentUrl($json, [
                'point_of_interaction.transaction_data.ticket_url',
                'transaction_details.external_resource_url',
            ]),
        );
    }

    /**
     * Sem notification_url: as notificações vêm da URL configurada em "Suas
     * integrações" (tópico Pagamentos), a única com assinatura secreta
     * documentada — uma notification_url no pagamento teria precedência
     * sobre ela.
     */
    protected function buildChargePayload(CreateChargeDTO $payload): array
    {
        $methodId = $this->mapPaymentMethodId($payload->paymentMethod);

        $name = $this->cleanName($payload->metadata['customer_name'] ?? null);

        $payer = array_filter([
            'email' => $this->validEmail($payload->metadata['email'] ?? null),
            // Boleto exige first_name e last_name (separados do nome do cadastro).
            'first_name'     => $methodId === 'bolbradesco' ? $this->nameParts($name)[0] : $name,
            'last_name'      => $methodId === 'bolbradesco' ? $this->nameParts($name)[1] : null,
            'identification' => $this->identificationPayload($payload->metadata['document'] ?? null),
            // Boleto: endereço do pagador (zip_code, street_name, street_number,
            // neighborhood, city, federal_unit), quando o cadastro o tem.
            // https://www.mercadopago.com.br/developers/pt/docs/checkout-api/integration-configuration/other-payment-methods
            'address' => $methodId === 'bolbradesco' ? $this->boletoAddress($payload->metadata['address'] ?? null) : null,
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);

        $metadata = Arr::except($payload->metadata, self::PII_METADATA_KEYS);

        return array_filter([
            'transaction_amount' => round($payload->amount, 2),
            'description'        => $payload->description,
            'payment_method_id'  => $methodId,
            'payer'              => $payer,
            // Até 64 caracteres: letras, números, hífen e sublinhado (UUID da fatura).
            'external_reference' => $this->validExternalReference($payload->invoiceId),
            'date_of_expiration' => $this->expirationFor($payload->dueDate, $methodId),
            'metadata'           => array_merge($metadata, [
                'entity_id'       => $payload->entityId,
                'invoice_id'      => $payload->invoiceId,
                'subscription_id' => $payload->subscriptionId,
            ]),
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);
    }

    /**
     * Fim do dia do vencimento (fuso da aplicação) no formato
     * yyyy-MM-dd'T'HH:mm:ss.SSSXXX, dentro da janela aceita: Pix de 30 min a
     * 30 dias; boleto de 1 a 30 dias.
     */
    private function expirationFor(?string $dueDate, string $methodId): string
    {
        $now = Carbon::now();
        $due = $dueDate
            ? Carbon::parse($dueDate)->endOfDay()
            : $now->copy()->addDays(3)->endOfDay();

        $min = $methodId === 'pix'
            ? $now->copy()->addMinutes(self::PIX_MIN_EXPIRATION_MINUTES)
            : $now->copy()->addDay()->endOfDay();
        $max = $now->copy()->addDays(self::MAX_EXPIRATION_DAYS)->subMinutes(5);

        return $this->formatMpDate($due->max($min)->min($max));
    }

    private function formatMpDate(CarbonInterface $date): string
    {
        return $date->copy()->startOfSecond()->format('Y-m-d\TH:i:s.vP');
    }

    /**
     * Cancela um pagamento ainda pendente/em processo (ex.: Pix não pago de
     * assinatura encerrada): PUT /v1/payments/{id} com status "cancelled".
     * Devolve o status normalizado.
     *
     * @throws GatewayIntegrationException
     */
    public function cancelPayment(string $externalPaymentId): string
    {
        $this->assertPaymentId($externalPaymentId);

        $response = $this->request('PUT', 'payments', ['status' => 'cancelled'], ['id' => $externalPaymentId]);

        if (! $response->successful()) {
            throw GatewayIntegrationException::fromHttpStatus($this->code(), $response->status(), $this->errorMessage($response));
        }

        return $this->normalizeMpPaymentStatus((string) $response->json('status', 'cancelled'));
    }

    /**
     * Cancelamento da cobrança em aberto pela interface comum (cancelPayment):
     * true só com o pagamento cancelado no Mercado Pago.
     */
    public function cancelCharge(string $externalPaymentId): bool
    {
        try {
            return $this->cancelPayment($externalPaymentId) === 'cancelled';
        } catch (GatewayIntegrationException) {
            return false;
        }
    }

    /**
     * Estorno total (sem $amount) ou parcial de pagamento aprovado (até 180
     * dias): POST /v1/payments/{id}/refunds, X-Idempotency-Key obrigatório.
     * Devolve a resposta (id do estorno, valor, status).
     *
     * @throws GatewayIntegrationException
     */
    public function refundPayment(string $externalPaymentId, string $idempotencyKey, ?float $amount = null): array
    {
        $this->assertPaymentId($externalPaymentId);

        $response = $this->request(
            method: 'POST',
            endpointKey: 'payment_refunds',
            payload: $amount !== null ? ['amount' => round($amount, 2)] : [],
            replacements: ['id' => $externalPaymentId],
            idempotencyKey: $idempotencyKey,
        );

        if (! $response->successful()) {
            throw GatewayIntegrationException::fromHttpStatus($this->code(), $response->status(), $this->errorMessage($response));
        }

        return $this->sanitizePayload($response->json() ?? []);
    }

    // ── Checkout transparente ────────────────────────────────────────────────

    public function transparentMethods(): array
    {
        return ['pix', 'boleto', 'credit_card'];
    }

    /**
     * Pix: point_of_interaction.transaction_data.{qr_code, qr_code_base64,
     * ticket_url} e date_of_expiration
     * (https://www.mercadopago.com.br/developers/pt/docs/checkout-api-payments/integration-configuration/integrate-pix);
     * boleto: transaction_details.external_resource_url (link garantido) e,
     * quando vierem, transaction_details.digitable_line e barcode.content
     * (modelados nos SDKs oficiais — sdk-php TransactionDetails, sdk-go
     * payment/response.go). Do pagamento guardado na emissão; sem os
     * dados, GET /v1/payments/{id}.
     */
    public function paymentInstructions(string $method, string $externalPaymentId, array $chargePayload = []): ?PaymentInstructionsDTO
    {
        $payment  = filled($chargePayload['payment_method_id'] ?? null) ? $chargePayload : $this->fetchPayment($externalPaymentId);
        $methodId = strtolower((string) ($payment['payment_method_id'] ?? ''));

        if ($method === 'pix' && $methodId === 'pix') {
            $data = (array) Arr::get($payment, 'point_of_interaction.transaction_data', []);

            return PaymentInstructionsDTO::pix(
                copyPaste: $this->extractString($data, ['qr_code']),
                qrBase64: $this->extractString($data, ['qr_code_base64']),
                expiresAt: $this->isoOrNull($payment['date_of_expiration'] ?? null),
                paymentUrl: $this->extractString($data, ['ticket_url']),
            );
        }

        if ($method === 'boleto' && $methodId === 'bolbradesco') {
            return PaymentInstructionsDTO::boleto(
                digitableLine: $this->extractString($payment, ['transaction_details.digitable_line']),
                barcode: $this->extractString($payment, ['transaction_details.barcode.content', 'barcode.content']),
                pdfUrl: $this->extractString($payment, ['transaction_details.external_resource_url']),
                dueDate: ($due = $this->isoOrNull($payment['date_of_expiration'] ?? null)) ? Carbon::parse($due)->timezone(config('app.timezone'))->toDateString() : null,
            );
        }

        return null;
    }

    private function isoOrNull(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Card Payment Brick (ou Core Methods) do MercadoPago.js v2 com a public
     * key: o formData traz token, payment_method_id, issuer_id e installments
     * (https://www.mercadopago.com.br/developers/pt/docs/checkout-bricks/card-payment-brick/payment-submission).
     * Parcelas sem juros para o cliente = configuração da conta ("Oferecer
     * parcelamento sem acréscimo") — não há campo no pagamento.
     */
    public function cardCheckoutConfig(): ?CardCheckoutConfigDTO
    {
        return new CardCheckoutConfigDTO(
            gateway: $this->code(),
            publicKey: $this->publicKey(),
            sdkUrl: self::SDK_URL,
            tokenization: 'card_token',
            maxInstallments: 12,
            extra: ['requires' => ['payment_method_id', 'issuer_id']],
        );
    }

    /**
     * POST /v1/payments no cartão
     * (https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-api-payments/create-payment/post).
     *
     * Recorrência (Automatic Payments / credencial em arquivo —
     * https://www.mercadopago.com.br/developers/pt/docs/automatic-payments/recurring-charges):
     *  - 1ª cobrança (cliente presente, token do Brick): point_of_interaction
     *    CREDENTIAL_ON_FILE first_transaction=true; aprovada, o cartão vai
     *    para o cliente (POST /v1/customers/{id}/cards com o token);
     *  - renovação (offSession): token gerado no servidor a partir do card_id
     *    (POST /v1/card_tokens, sem CVV), first_transaction=false, iniciada
     *    pelo lojista, com o network_transaction_id e o id da 1ª cobrança.
     * Automatic Payments exige liberação comercial do Mercado Pago.
     */
    public function chargeCard(CardChargeDTO $payload): CreateChargeResultDTO
    {
        $token = $payload->cardToken;

        if ($payload->offSession) {
            $token = $this->tokenFromSavedCard((string) $payload->savedCardId, $payload->customerId);

            if ($token === null) {
                return new CreateChargeResultDTO(success: true, externalPaymentId: null, status: 'failed', amount: null, rawResponse: [], errorCode: 'card_declined', errorMessage: __('checkout.decline.saved_card_unavailable', ['gateway' => 'Mercado Pago']));
            }
        }

        if (blank($token)) {
            return new CreateChargeResultDTO(success: true, externalPaymentId: null, status: 'failed', amount: null, rawResponse: [], errorCode: 'card_declined', errorMessage: __('checkout.decline.card_invalid'));
        }

        $meta      = $payload->metadata;
        $sequence  = max(1, (int) ($meta['card_sequence'] ?? 1));
        $recurring = $payload->saveCard || $payload->offSession;

        $body = array_filter([
            'transaction_amount'   => round($payload->amount, 2),
            'token'                => $token,
            'description'          => $payload->description,
            'installments'         => $payload->offSession ? 1 : max(1, $payload->installments),
            'payment_method_id'    => $payload->paymentMethodId ?? ($meta['card_payment_method_id'] ?? null),
            'issuer_id'            => $payload->issuerId,
            'binary_mode'          => true,
            'statement_descriptor' => 'EASYEYE',
            'external_reference'   => $this->validExternalReference($payload->invoiceId),
            'payer'                => array_filter([
                'type'           => filled($payload->customerId) ? 'customer' : null,
                'id'             => $payload->customerId ?: null,
                'email'          => $this->validEmail($payload->payer->email),
                'identification' => $this->identificationPayload($payload->payer->document),
            ], fn ($value) => $value !== null),
            'point_of_interaction' => $recurring ? [
                'type'             => 'CREDENTIAL_ON_FILE',
                'sub_type'         => 'recurring',
                'transaction_data' => array_filter([
                    'first_transaction'      => ! $payload->offSession,
                    'storage'                => $payload->offSession ? 'stored' : 'store',
                    'transaction_initiator'  => $payload->offSession ? 'merchant' : 'customer',
                    'network_transaction_id' => $payload->offSession ? ($meta['card_network_transaction_id'] ?? null) : null,
                    'subscription_id'        => $payload->subscriptionId,
                    'subscription_sequence'  => ['number' => $payload->offSession ? $sequence : 1, 'total' => null],
                    'invoice_period'         => ['period' => max(1, (int) ($meta['cycle_months'] ?? 1)), 'type' => 'monthly'],
                    'billing_date'           => now()->toDateString(),
                    'reference'              => $payload->offSession && filled($meta['card_origin_payment_id'] ?? null) ? ['id' => (string) $meta['card_origin_payment_id']] : null,
                ], fn ($value) => $value !== null),
            ] : null,
            'metadata' => [
                'entity_id'       => $payload->entityId,
                'invoice_id'      => $payload->invoiceId,
                'subscription_id' => $payload->subscriptionId,
            ],
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);

        $response = $this->postPayment($body, $payload->idempotencyKey ?: (string) Str::uuid(), expandGateway: $recurring);

        if (! $response->successful()) {
            return $this->cardFailure($response, $this->errorMessage($response));
        }

        $json   = $response->json() ?? [];
        $status = $this->normalizeMpPaymentStatus((string) ($json['status'] ?? ''));
        $saved  = null;

        $this->checkInterestFree($json, $status, $payload->invoiceId);

        if ($payload->offSession) {
            $saved = SavedCardDTO::make($payload->savedCardId, $json['payment_method_id'] ?? null, Arr::get($json, 'card.last_four_digits'));
        } elseif ($payload->saveCard && $status === 'paid' && filled($payload->customerId)) {
            $saved = $this->storeCard($payload->customerId, (string) $token)->card;
        }

        return $this->cardResult(
            response: $response,
            externalId: filled($json['id'] ?? null) ? (string) $json['id'] : null,
            status: $status,
            amount: isset($json['transaction_amount']) ? (float) $json['transaction_amount'] : null,
            json: [
                ...$json,
                // Guardados para a renovação (Automatic Payments).
                'easyeye_card' => array_filter([
                    'network_transaction_id' => Arr::get($json, 'expanded.gateway.reference.network_transaction_id'),
                    'payment_method_id'      => $json['payment_method_id'] ?? null,
                ]),
            ],
            savedCard: $saved,
            declineMessage: $status === 'failed' ? $this->declineMessage((string) ($json['status_detail'] ?? '')) : null,
        );
    }

    /**
     * Troca do cartão no Automatic Payments: o reference.id é o da 1ª cobrança
     * (CIT) DA ASSINATURA e não muda na cadeia — fica, com a sequência; o
     * network_transaction_id é do cartão anterior e nunca vai com outro
     * cartão ("never reuse the TID generated from a payment made with the
     * previous card"); a bandeira volta a sair do cartão salvo.
     * https://www.mercadopago.com.br/developers/en/docs/automatic-payments/recurring-charges.
     */
    public function cardReferencesAfterReplacement(array $references): array
    {
        return array_filter([
            'origin_payment_id' => $references['origin_payment_id'] ?? null,
            'sequence'          => $references['sequence'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /** Troca do cartão: POST /v1/customers/{id}/cards com o token do Brick. */
    public function saveCard(string $customerId, string $cardToken, CustomerDTO $payer): SaveCardResultDTO
    {
        return $this->storeCard($customerId, $cardToken);
    }

    /**
     * POST /v1/customers/{id}/cards {"token"} → id do cartão, last_four_digits,
     * payment_method.id
     * (https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-api-payments/cards/save-card/post).
     */
    private function storeCard(string $customerId, string $token): SaveCardResultDTO
    {
        if ($customerId === '' || $token === '') {
            return new SaveCardResultDTO(success: false, errorMessage: __('checkout.decline.card_invalid'));
        }

        try {
            $response = $this->request('POST', 'customer_cards', ['token' => $token], ['id' => $customerId]);
        } catch (GatewayIntegrationException $e) {
            return new SaveCardResultDTO(success: false, errorMessage: $e->getMessage());
        }

        if (! $response->successful()) {
            return new SaveCardResultDTO(success: false, errorMessage: $this->errorMessage($response));
        }

        $json = $response->json() ?? [];
        $card = SavedCardDTO::make($json['id'] ?? null, Arr::get($json, 'payment_method.id'), $json['last_four_digits'] ?? null);

        return $card ? new SaveCardResultDTO(success: true, card: $card) : new SaveCardResultDTO(success: false, errorMessage: __('checkout.decline.not_returned', ['gateway' => 'Mercado Pago']));
    }

    /** POST /v1/card_tokens {"card_id"} (sem CVV — Automatic Payments). */
    private function tokenFromSavedCard(string $cardId, string $customerId): ?string
    {
        if ($cardId === '') {
            return null;
        }

        try {
            $response = $this->request('POST', 'card_tokens', array_filter(['card_id' => $cardId, 'customer_id' => $customerId ?: null]));
        } catch (GatewayIntegrationException) {
            return null;
        }

        $id = $response->successful() ? $response->json('id') : null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /** POST /v1/payments com X-Idempotency-Key e, na recorrência, X-Expand-Response-Nodes: gateway.reference. */
    private function postPayment(array $body, string $idempotencyKey, bool $expandGateway): Response
    {
        if (! $expandGateway) {
            return $this->request('POST', 'charges', $body, [], $idempotencyKey);
        }

        $this->expandGatewayReference = true;

        try {
            return $this->request('POST', 'charges', $body, [], $idempotencyKey);
        } finally {
            $this->expandGatewayReference = false;
        }
    }

    private bool $expandGatewayReference = false;

    protected function authHeaders(): array
    {
        $headers = parent::authHeaders();

        if ($this->expandGatewayReference) {
            $headers['X-Expand-Response-Nodes'] = 'gateway.reference';
        }

        return $headers;
    }

    /**
     * Parcelado "sem juros" no Mercado Pago depende da CONTA (Seu negócio →
     * Custos → "Parcelamento sem acréscimo" / oferecer parcelas sem juros):
     * a API não tem campo para isso, e com a configuração padrão o
     * comprador paga os juros. Pago a mais que o valor da cobrança
     * (transaction_details.total_paid_amount > transaction_amount) = o
     * cliente pagou juros que o EasyEye prometeu absorver → alerta crítico
     * para o financeiro (devolver a diferença e corrigir a conta).
     * https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-api-payments/get-payment/get
     * https://www.mercadopago.com.br/developers/pt/docs/checkout-api-payments/how-tos/integrate-installments.
     */
    private function checkInterestFree(array $payment, ?string $status, ?string $invoiceId): void
    {
        $amount = (float) ($payment['transaction_amount'] ?? 0);
        $total  = (float) Arr::get($payment, 'transaction_details.total_paid_amount', 0);

        if ($status !== 'paid' || $amount <= 0 || $total <= $amount + 0.01) {
            return;
        }

        app(BillingLogService::class)->log(
            level: 'critical',
            message: 'Mercado Pago cobrou juros do cliente num pagamento que o EasyEye vende sem juros — devolver a diferença ao cliente e ativar "parcelamento sem acréscimo" na conta do Mercado Pago.',
            context: [
                'external_payment_id' => (string) ($payment['id'] ?? ''),
                'invoice_id'          => $invoiceId,
                'installments'        => $payment['installments'] ?? null,
                'transaction_amount'  => $amount,
                'total_paid_amount'   => $total,
                'difference'          => round($total - $amount, 2),
            ],
            gatewayCode: $this->code(),
            correlationId: $this->correlationId,
        );
    }

    /**
     * Motivo da recusa (status_detail cc_rejected_*) em texto para o cliente,
     * no idioma da requisição (lang/{pt_BR,en}/checkout.php → decline).
     */
    private function declineMessage(string $detail): string
    {
        $key = match ($detail) {
            'cc_rejected_insufficient_amount' => 'insufficient_funds',
            'cc_rejected_bad_filled_security_code', 'cc_rejected_bad_filled_date', 'cc_rejected_bad_filled_card_number', 'cc_rejected_bad_filled_other' => 'invalid_data',
            'cc_rejected_call_for_authorize' => 'call_for_authorize',
            'cc_rejected_card_disabled'      => 'card_disabled',
            'cc_rejected_high_risk', 'cc_rejected_blacklist' => 'high_risk',
            default => 'generic',
        };

        return __("checkout.decline.{$key}");
    }

    /** [first_name, last_name] — o boleto exige os dois. */
    private function nameParts(?string $name): array
    {
        $name  = trim((string) $name);
        $parts = preg_split('/\s+/', $name, 2) ?: [];

        return [$parts[0] ?? $name, $parts[1] ?? ($parts[0] ?? $name)];
    }

    // ── Webhooks ─────────────────────────────────────────────────────────────

    /**
     * x-signature: "ts=<timestamp>,v1=<hmac>". O HMAC-SHA256 (hex, chave =
     * assinatura secreta de "Suas integrações") é do manifesto
     * "id:<data.id>;request-id:<x-request-id>;ts:<ts>;" — com o ";" final,
     * data.id em minúsculas e cada par ausente omitido (doc oficial e
     * WebhookSignatureValidator do SDK PHP). O data.id vem do corpo: é o
     * mesmo do query param data.id da URL notificada.
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

        $sigHeader = $this->headerValue($payload->headers, 'x-signature') ?? $payload->signature;

        if (! is_string($sigHeader) || trim($sigHeader) === '') {
            return false;
        }

        $ts     = null;
        $hashes = [];

        foreach (explode(',', $sigHeader) as $part) {
            $pieces = explode('=', $part, 2);

            if (count($pieces) !== 2) {
                continue;
            }

            $key   = strtolower(trim($pieces[0]));
            $value = trim($pieces[1]);

            if ($key === 'ts') {
                $ts = $value;
            } elseif ($key !== '' && $value !== '') {
                $hashes[$key] = $value;
            }
        }

        $v1 = $hashes['v1'] ?? null;

        if ($ts === null || ! ctype_digit($ts) || $v1 === null) {
            return false;
        }

        $requestId = trim((string) $this->headerValue($payload->headers, 'x-request-id'));
        $dataId    = $payload->payload['data']['id'] ?? null;
        $dataId    = is_scalar($dataId) ? strtolower(trim((string) $dataId)) : '';

        $manifest = '';

        if ($dataId !== '') {
            $manifest .= "id:{$dataId};";
        }

        if ($requestId !== '') {
            $manifest .= "request-id:{$requestId};";
        }

        $manifest .= "ts:{$ts};";

        return hash_equals(hash_hmac('sha256', $manifest, $secret), strtolower($v1));
    }

    /**
     * A notificação só traz o id do recurso (data.id): no tópico "payment" o
     * status, o valor e a fatura (external_reference) vêm de
     * GET /v1/payments/{id}; no "subscription_preapproval" (assinaturas
     * antigas), de GET /preapproval/{id}. Falha na consulta lança exceção —
     * o webhook fica 'failed' e o job tenta de novo. Outros tópicos ficam só
     * registrados.
     */
    public function parseWebhook(GatewayWebhookInputDTO $payload): NormalizedWebhookEventDTO
    {
        $action = (string) ($payload->payload['action'] ?? $payload->payload['type'] ?? $payload->payload['topic'] ?? 'unknown');
        $topic  = (string) ($payload->payload['type'] ?? $payload->payload['topic'] ?? '');
        $dataId = $payload->payload['data']['id'] ?? '';
        $dataId = is_scalar($dataId) ? trim((string) $dataId) : '';

        if ($topic === '' && str_contains($action, '.')) {
            $topic = (string) strstr($action, '.', true);
        }

        $normalizedType         = 'unknown';
        $externalPaymentId      = null;
        $externalSubscriptionId = null;
        $amount                 = null;
        $status                 = null;
        $metadata               = ['topic' => $topic, 'action' => $action, 'data_id' => $dataId];
        $dueDate                = null;
        $paymentUrl             = null;

        if ($topic === 'payment' && ctype_digit($dataId)) {
            $mpPayment = $this->fetchPayment($dataId);
            $mpStatus  = strtolower((string) ($mpPayment['status'] ?? ''));

            $externalPaymentId = (string) ($mpPayment['id'] ?? $dataId);
            $status            = $this->normalizeMpPaymentStatus($mpStatus);
            $normalizedType    = $this->eventTypeForPaymentStatus($mpStatus);
            $amount            = isset($mpPayment['transaction_amount']) ? (float) $mpPayment['transaction_amount'] : null;
            $metadata          = array_merge($metadata, array_filter([
                'invoice_id'      => $this->extractString($mpPayment, ['external_reference', 'metadata.invoice_id']),
                'subscription_id' => $this->extractString($mpPayment, ['metadata.subscription_id']),
                'mp_status'       => $mpStatus,
                'status_detail'   => $this->extractString($mpPayment, ['status_detail']),
            ]));
            $this->checkInterestFree($mpPayment, $status, $metadata['invoice_id'] ?? null);

            $expiration = $this->extractString($mpPayment, ['date_of_expiration']);
            $dueDate    = $expiration ? Carbon::parse($expiration)->timezone(config('app.timezone'))->toDateString() : null;
            $paymentUrl = $this->extractPaymentUrl($mpPayment, [
                'point_of_interaction.transaction_data.ticket_url',
                'transaction_details.external_resource_url',
            ]);
        } elseif ($topic === 'subscription_preapproval' && preg_match('/^[A-Za-z0-9_-]+$/', $dataId) === 1) {
            $preapproval = $this->fetchPreapproval($dataId);
            $mpStatus    = strtolower((string) ($preapproval['status'] ?? ''));

            $externalSubscriptionId = $dataId;
            $status                 = $this->normalizeMpStatus($mpStatus);
            // Encerrada lá (irreversível): encerra aqui. Pausa/pendência só registra.
            $normalizedType = $status === 'cancelled' ? 'cancelled' : 'unknown';
            $metadata       = array_merge($metadata, array_filter([
                'mp_status'          => $mpStatus,
                'external_reference' => $this->extractString($preapproval, ['external_reference']),
            ]));
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
            metadata: $metadata,
            rawPayload: $payload->payload,
            occurredAt: now()->toIso8601String(),
            dueDate: $dueDate,
            paymentUrl: $paymentUrl,
        );
    }

    /** GET /v1/payments/{id}; id não numérico nunca vira caminho da API. */
    public function fetchPayment(string $externalPaymentId): array
    {
        $this->assertPaymentId($externalPaymentId);

        return parent::fetchPayment($externalPaymentId);
    }

    /** @throws GatewayIntegrationException */
    private function fetchPreapproval(string $preapprovalId): array
    {
        $response = $this->request('GET', 'subscription_show', [], ['id' => $preapprovalId]);

        if (! $response->successful()) {
            throw GatewayIntegrationException::fromHttpStatus($this->code(), $response->status(), $response->body());
        }

        return $this->sanitizePayload($response->json() ?? []);
    }

    /**
     * Tipo normalizado pelo status do pagamento (o evento payment.* não diz
     * o que aconteceu). Pagamento cancelado (ex.: Pix expirado) cancela só a
     * cobrança, não a assinatura. in_mediation (disputa aberta) só registra:
     * o desfecho chega como charged_back, refunded ou approved.
     */
    private function eventTypeForPaymentStatus(string $status): string
    {
        return match (strtolower($status)) {
            'approved'     => 'paid',
            'rejected'     => 'failed',
            'cancelled'    => 'payment_cancelled',
            'refunded'     => 'refunded',
            'charged_back' => 'chargeback',
            'pending', 'in_process', 'authorized' => 'created',
            default => 'unknown',
        };
    }

    protected function eventTypeMap(): array
    {
        return [
            'payment.created'                  => 'unknown', // status do pagamento determina o tipo
            'payment.updated'                  => 'unknown', // status do pagamento determina o tipo
            'subscription_preapproval.created' => 'unknown', // status do preapproval determina o tipo
            'subscription_preapproval.updated' => 'unknown',
        ];
    }

    // ── Infra ────────────────────────────────────────────────────────────────

    /**
     * Caminho do config/billing.php ou, se faltar, o da documentação
     * (ENDPOINTS). Ids entram codificados no caminho.
     */
    protected function buildEndpoint(string $endpointKey, array $replacements = []): string
    {
        $configured = Arr::get((array) $this->gatewayConfig('endpoints'), $endpointKey);
        $endpoint   = is_string($configured) && $configured !== '' ? $configured : (self::ENDPOINTS[$endpointKey] ?? null);

        if ($endpoint === null) {
            throw new GatewayIntegrationException("[{$this->code()}] Endpoint desconhecido: {$endpointKey}.", 'invalid_request');
        }

        foreach ($replacements as $key => $value) {
            $endpoint = str_replace('{' . $key . '}', rawurlencode((string) $value), $endpoint);
        }

        return rtrim((string) $this->gatewayConfig('base_url'), '/') . $endpoint;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function normalizeMpPaymentStatus(string $status): string
    {
        return match (strtolower($status)) {
            'approved'   => 'paid',
            'authorized' => 'authorized',
            'pending', 'in_process' => 'pending',
            'in_mediation' => 'in_mediation',
            'rejected'     => 'failed',
            'cancelled'    => 'cancelled',
            'refunded'     => 'refunded',
            'charged_back' => 'chargeback',
            default        => strtolower($status),
        };
    }

    /** Status do preapproval: pending | authorized | paused | canceled. */
    private function normalizeMpStatus(string $status): string
    {
        return match (strtolower($status)) {
            'authorized', 'active' => 'active',
            'paused' => 'paused',
            'canceled', 'cancelled' => 'cancelled',
            'pending' => 'pending',
            default   => strtolower($status),
        };
    }

    private function mapPaymentMethodId(?string $method): string
    {
        return match (strtolower((string) $method)) {
            'boleto' => 'bolbradesco',
            default  => 'pix',
        };
    }

    /** Ids de pagamento do Mercado Pago são numéricos. */
    private function assertPaymentId(string $externalPaymentId): void
    {
        if (! ctype_digit($externalPaymentId)) {
            throw new GatewayIntegrationException(
                "[{$this->code()}] Id de pagamento inválido: " . mb_substr($externalPaymentId, 0, 64),
                'invalid_request',
            );
        }
    }

    /** CPF (11 dígitos) ou CNPJ (14); outro tamanho não vai. */
    private function identificationPayload(mixed $document): ?array
    {
        $digits = preg_replace('/\D/', '', (string) (is_scalar($document) ? $document : ''));

        return match (strlen($digits)) {
            11      => ['type' => 'CPF', 'number' => $digits],
            14      => ['type' => 'CNPJ', 'number' => $digits],
            default => null,
        };
    }

    /**
     * payer.address do boleto, do endereço do cadastro (BillingCustomer).
     * Incompleto (sem CEP de 8 dígitos, rua, número, bairro, cidade ou UF)
     * não vai — o Mercado Pago recusa o boleto sem esses campos.
     */
    private function boletoAddress(mixed $address): ?array
    {
        if (! is_array($address)) {
            return null;
        }

        $value = fn (string $key): string => is_scalar($address[$key] ?? null) ? trim((string) $address[$key]) : '';

        $payload = [
            'zip_code'      => preg_replace('/\D/', '', $value('zipcode')),
            'street_name'   => $value('street'),
            'street_number' => $value('number'),
            'neighborhood'  => $value('district'),
            'city'          => $value('city'),
            'federal_unit'  => strtoupper($value('state')),
        ];

        if (strlen($payload['zip_code']) !== 8 || strlen($payload['federal_unit']) !== 2 || in_array('', $payload, true)) {
            return null;
        }

        return $payload;
    }

    /** DDD + número (DDI 55 descartado); fora de 10/11 dígitos não vai. */
    private function phonePayload(?string $phone): ?array
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        if (strlen($digits) >= 12 && str_starts_with($digits, '55')) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) !== 10 && strlen($digits) !== 11) {
            return null;
        }

        return ['area_code' => substr($digits, 0, 2), 'number' => substr($digits, 2)];
    }

    private function validEmail(mixed $email): ?string
    {
        $email = is_string($email) ? trim($email) : '';

        return $email !== '' && strlen($email) < 254 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    private function cleanName(mixed $name): ?string
    {
        $name = is_string($name) ? trim(preg_replace('/\s+/', ' ', $name)) : '';

        return $name !== '' ? $name : null;
    }

    private function validExternalReference(string $reference): ?string
    {
        return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $reference) === 1 ? $reference : null;
    }

    private function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (is_string($key) && strtolower($key) === $name && is_scalar($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    /** Resposta de erro: {"message", "error", "status", "cause": [{"code", "description"}]}. */
    private function hasErrorCause(Response $response, string $code): bool
    {
        foreach ((array) $response->json('cause', []) as $cause) {
            if (is_array($cause) && (string) ($cause['code'] ?? '') === $code) {
                return true;
            }
        }

        return false;
    }

    private function errorMessage(Response $response): string
    {
        $message = $response->json('message');
        $causes  = collect((array) $response->json('cause', []))
            ->filter(fn ($cause) => is_array($cause))
            ->map(fn (array $cause) => trim(($cause['code'] ?? '') . ' ' . ($cause['description'] ?? '')))
            ->filter()
            ->implode('; ');

        $text = is_string($message) && $message !== ''
            ? "HTTP {$response->status()}: {$message}" . ($causes !== '' ? " ({$causes})" : '')
            : $response->body();

        return mb_substr($text, 0, 1000);
    }
}
