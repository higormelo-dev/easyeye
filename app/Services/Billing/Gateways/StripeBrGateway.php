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
use Illuminate\Http\Client\{ConnectionException, Response};
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Stripe (conta Stripe no Brasil) — renovação local.
 *
 * Cada cobrança do EasyEye é uma fatura avulsa da Stripe (Invoice com
 * collection_method=send_invoice): rascunho + item + finalização. A fatura
 * finalizada tem a página hospedada (hosted_invoice_url), um link pagável com
 * as formas de pagamento habilitadas no modelo de fatura do Dashboard (cartão,
 * boleto…), válido até 30 dias depois do vencimento. Um PaymentIntent
 * confirmado no servidor não serve aqui: o boleto exige endereço completo e
 * CPF/CNPJ do pagador (o CustomerDTO não traz endereço) e o Pix expira em no
 * máximo 3 dias — a renovação emite a cobrança antes do vencimento.
 *
 * - A fatura (in_…) é o pagamento registrado (externalPaymentId). Eventos
 *   invoice.* são os canônicos; payment_intent.* e charge.* do mesmo pagamento
 *   não contam de novo.
 * - Estorno e contestação chegam no Charge/Dispute: ligados à fatura pelo
 *   InvoicePayment (API 2025-03-31.basil+) ou por charge.invoice (versões
 *   anteriores do endpoint de webhook).
 * - Corpo application/x-www-form-urlencoded, header Idempotency-Key em todo
 *   POST e Stripe-Version fixa (o formato da resposta não depende da versão
 *   padrão da conta).
 *
 * Docs: https://docs.stripe.com/api (form-encoded),
 * https://docs.stripe.com/invoicing/hosted-invoice-page,
 * https://docs.stripe.com/webhooks?verify=verify-manually,
 * https://docs.stripe.com/api/idempotent_requests
 */
class StripeBrGateway extends AbstractHttpGateway
{
    /**
     * Versão da API enviada em toda requisição (config
     * billing.gateways.stripe_br.api_version sobrescreve). A partir da Basil,
     * Invoice/Charge/PaymentIntent não se apontam mais e o elo é o
     * InvoicePayment — https://docs.stripe.com/changelog/basil/2025-03-31/add-support-for-multiple-partial-payments-on-invoices.
     */
    public const API_VERSION = '2025-03-31.basil';

    /** Tolerância do timestamp do Stripe-Signature (a mesma das bibliotecas oficiais). */
    public const WEBHOOK_TOLERANCE_SECONDS = 300;

    /** Marca nas faturas criadas pelo EasyEye (metadata). */
    private const CHARGE_KIND = 'invoice';

    /** Vencimentos são datas de calendário do Brasil (o boleto vence 23:59 de São Paulo). */
    private const TIMEZONE = 'America/Sao_Paulo';

    private const DEFAULT_BASE_URL = 'https://api.stripe.com';

    /**
     * Endpoints usados (config billing.gateways.stripe_br.endpoints sobrescreve
     * por chave). https://docs.stripe.com/api.
     */
    private const ENDPOINTS = [
        'customers'             => '/v1/customers',
        'customer_search'       => '/v1/customers/search',
        'subscription_cancel'   => '/v1/subscriptions/{id}',
        'invoices'              => '/v1/invoices',
        'invoice'               => '/v1/invoices/{id}',
        'invoice_finalize'      => '/v1/invoices/{id}/finalize',
        'invoice_void'          => '/v1/invoices/{id}/void',
        'invoice_items'         => '/v1/invoiceitems',
        'invoice_payments'      => '/v1/invoice_payments',
        'payment_intent'        => '/v1/payment_intents/{id}',
        'payment_intents'       => '/v1/payment_intents',
        'payment_intent_cancel' => '/v1/payment_intents/{id}/cancel',
        'setup_intents'         => '/v1/setup_intents',
        'setup_intent'          => '/v1/setup_intents/{id}',
    ];

    /** Marca nos PaymentIntents do checkout transparente (metadata). */
    private const CHECKOUT_KIND = 'payment_intent';

    /**
     * Stripe.js da mesma versão da API fixada (basil) —
     * https://docs.stripe.com/sdks/stripejs-versioning.
     */
    public const SDK_URL = 'https://js.stripe.com/basil/stripe.js';

    /** Pix: até 3 dias de validade (https://docs.stripe.com/payments/pix/accept-a-payment). */
    private const PIX_MAX_SECONDS = 259200;

    public function code(): string
    {
        return 'stripe_br';
    }

    /** Página da cobrança do gateway aceita cartão (cartão sem transparente vai por ela). */
    public function supportsCardLink(): bool
    {
        return true;
    }

    // ── Clientes ─────────────────────────────────────────────────────────────

    /**
     * Reaproveita o cliente da empresa (metadata entity_id) — nunca pelo
     * e-mail, que pode ser o mesmo em duas clínicas. A busca não é
     * read-after-write (o índice pode atrasar ~1 min); a criação usa
     * Idempotency-Key derivada da empresa e dos dados, então um reenvio em
     * seguida devolve o mesmo cliente.
     * https://docs.stripe.com/api/customers/search.
     */
    public function upsertCustomer(CustomerDTO $customer): string
    {
        $existing = $this->findCustomerByEntity($customer->entityId);

        if ($existing !== null) {
            return $existing;
        }

        $payload  = $this->buildCustomerPayload($customer);
        $response = $this->request('POST', 'customers', $payload, [], $this->idempotencyKeyFor('customer', [$customer->entityId, $payload]));

        // CPF/CNPJ recusado pela validação de formato da Stripe: o cliente é
        // criado sem o ID fiscal (a página da fatura pede o documento no boleto).
        if (! $response->successful() && isset($payload['tax_id_data'])
            && str_starts_with((string) $response->json('error.param'), 'tax_id_data')) {
            unset($payload['tax_id_data']);
            $response = $this->request('POST', 'customers', $payload, [], $this->idempotencyKeyFor('customer', [$customer->entityId, $payload]));
        }

        if (! $response->successful()) {
            throw GatewayIntegrationException::fromHttpStatus($this->code(), $response->status(), $this->errorSummary($response));
        }

        $id = $response->json('id');

        if (! is_string($id) || $id === '') {
            throw GatewayIntegrationException::unavailable($this->code(), 'Resposta sem id do cliente.');
        }

        return $id;
    }

    private function findCustomerByEntity(string $entityId): ?string
    {
        if ($entityId === '') {
            return null;
        }

        $response = $this->request('GET', 'customer_search', [
            'query' => "metadata['entity_id']:'" . $this->searchLiteral($entityId) . "'",
            'limit' => 1,
        ]);

        // Busca indisponível (ex.: conta na Índia) ou erro: segue para a criação.
        if (! $response->successful()) {
            return null;
        }

        $id = $response->json('data.0.id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Só o necessário: nome, e-mail, telefone, idioma da página/e-mails e o
     * CPF/CNPJ como ID fiscal (sai na fatura). O documento não vai para o
     * metadata — https://docs.stripe.com/api/metadata ("don't store sensitive
     * information") e https://docs.stripe.com/billing/customer/tax-ids.
     */
    protected function buildCustomerPayload(CustomerDTO $customer): array
    {
        $phone = preg_replace('/[^\d+]/', '', (string) $customer->phone);

        return array_filter([
            'name'              => $customer->name,
            'email'             => $customer->email ?: null,
            'phone'             => $phone !== '' ? $phone : null,
            'preferred_locales' => ['pt-BR'],
            'tax_id_data'       => $this->taxIdData($customer->document),
            'metadata'          => ['entity_id' => $customer->entityId],
        ], fn ($value) => $value !== null && $value !== []);
    }

    /** @return list<array{type: string, value: string}>|null */
    private function taxIdData(?string $document): ?array
    {
        $digits = preg_replace('/\D/', '', (string) $document);

        return match (strlen($digits)) {
            11      => [['type' => 'br_cpf', 'value' => vsprintf('%s%s%s.%s%s%s.%s%s%s-%s%s', str_split($digits))]],
            14      => [['type' => 'br_cnpj', 'value' => vsprintf('%s%s.%s%s%s.%s%s%s/%s%s%s%s-%s%s', str_split($digits))]],
            default => null,
        };
    }

    // ── Assinaturas ──────────────────────────────────────────────────────────

    /**
     * Renovação local: a recorrência não fica na Stripe. Uma Subscription da
     * Stripe sem cartão salvo nasce "incomplete" e não cobra sozinha
     * (https://docs.stripe.com/billing/subscriptions/overview), então o
     * contrato manda devolver null e o RenewSubscriptionJob emite cada ciclo.
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
     * Só assinaturas antigas criadas na Stripe têm id externo: DELETE
     * /v1/subscriptions/{id} cancela na hora — https://docs.stripe.com/api/subscriptions/cancel.
     */
    public function cancelSubscription(CancelSubscriptionDTO $payload): CancelSubscriptionResultDTO
    {
        if (blank($payload->externalSubscriptionId)) {
            return new CancelSubscriptionResultDTO(success: true, status: 'cancelled', rawResponse: []);
        }

        $response = $this->request('DELETE', 'subscription_cancel', [], ['id' => $payload->externalSubscriptionId]);

        // Já não existe na Stripe: nada a cancelar.
        if ($response->status() === 404) {
            return new CancelSubscriptionResultDTO(success: true, status: 'cancelled', rawResponse: $response->json() ?? []);
        }

        if (! $response->successful()) {
            return new CancelSubscriptionResultDTO(
                success: false,
                status: null,
                rawResponse: $response->json() ?? [],
                errorMessage: $this->errorSummary($response),
            );
        }

        return new CancelSubscriptionResultDTO(success: true, status: 'cancelled', rawResponse: $response->json() ?? []);
    }

    // ── Cobranças ────────────────────────────────────────────────────────────

    /**
     * Fatura avulsa: POST /v1/invoices (rascunho, send_invoice, vencimento) →
     * POST /v1/invoiceitems (valor) → POST /v1/invoices/{id}/finalize (gera o
     * hosted_invoice_url). auto_advance=false: a Stripe não manda e-mail nem
     * cobra sozinha — a régua do EasyEye envia o link.
     * https://docs.stripe.com/api/invoices/create
     * https://docs.stripe.com/api/invoiceitems/create
     * https://docs.stripe.com/api/invoices/finalize.
     */
    public function createCharge(CreateChargeDTO $payload): CreateChargeResultDTO
    {
        // Checkout transparente: Pix/boleto na tela do EasyEye (PaymentIntent
        // com next_action). Sem forma escolhida, a fatura hospedada (abaixo).
        if (in_array($payload->paymentMethod, ['pix', 'boleto'], true)) {
            return $this->createAsyncPaymentIntent($payload);
        }

        $baseKey = $payload->idempotencyKey
            ?: 'charge:' . $payload->invoiceId . ':' . hash('sha256', $payload->amount . '|' . $payload->dueDate);

        $created = $this->request('POST', 'invoices', $this->buildChargePayload($payload), [], $this->stepKey($baseKey, 'invoice'));

        if (! $created->successful()) {
            return $this->chargeFailure($created);
        }

        $invoiceId = $created->json('id');

        if (! is_string($invoiceId) || $invoiceId === '') {
            return $this->chargeFailure($created, 'Resposta sem id da fatura.');
        }

        $item = $this->request('POST', 'invoice_items', [
            'customer'    => $payload->customerId,
            'invoice'     => $invoiceId,
            'amount'      => $this->toMinorUnits($payload->amount),
            'currency'    => strtolower($payload->currency),
            'description' => $payload->description,
            'metadata'    => $this->chargeMetadata($payload),
        ], [], $this->stepKey($baseKey, 'item'));

        if (! $item->successful()) {
            $this->discardDraft($invoiceId, $item);

            return $this->chargeFailure($item);
        }

        $final = $this->request('POST', 'invoice_finalize', ['auto_advance' => false], ['id' => $invoiceId], $this->stepKey($baseKey, 'finalize'));

        if (! $final->successful()) {
            $this->discardDraft($invoiceId, $final);

            return $this->chargeFailure($final);
        }

        $json = $final->json() ?? [];

        return new CreateChargeResultDTO(
            success: true,
            externalPaymentId: (string) ($json['id'] ?? $invoiceId),
            status: $this->normalizeInvoiceStatus((string) ($json['status'] ?? '')),
            amount: $this->fromMinorUnits($json['amount_due'] ?? null),
            rawResponse: $this->sanitizePayload($json),
            paymentUrl: $this->extractPaymentUrl($json, ['hosted_invoice_url']),
        );
    }

    /**
     * POST /v1/invoices/{id}/void (https://docs.stripe.com/api/invoices/void):
     * a fatura aberta deixa de ser cobrável (o hosted_invoice_url para de
     * aceitar pagamento). Só in_… finalizada e em aberto.
     */
    public function cancelCharge(string $externalPaymentId): bool
    {
        // PaymentIntent do checkout (Pix): POST /v1/payment_intents/{id}/cancel
        // (https://docs.stripe.com/api/payment_intents/cancel). Boleto não se
        // cancela antes do vencimento — a Stripe recusa e o chamador avisa.
        if (str_starts_with($externalPaymentId, 'pi_')) {
            try {
                $response = $this->request('POST', 'payment_intent_cancel', [], ['id' => $externalPaymentId], 'cancel:' . $externalPaymentId);
            } catch (GatewayIntegrationException) {
                return false;
            }

            return $response->successful() && $response->json('status') === 'canceled';
        }

        if (! str_starts_with($externalPaymentId, 'in_')) {
            return false;
        }

        try {
            $response = $this->request('POST', 'invoice_void', [], ['id' => $externalPaymentId], 'void:' . $externalPaymentId);
        } catch (GatewayIntegrationException) {
            return false;
        }

        return $response->successful() && $response->json('status') === 'void';
    }

    // ── Checkout transparente ────────────────────────────────────────────────

    public function transparentMethods(): array
    {
        return ['pix', 'boleto', 'credit_card'];
    }

    /**
     * Pix/boleto por PaymentIntent confirmado no servidor
     * (https://docs.stripe.com/payments/pix/accept-a-payment?payment-ui=direct-api,
     * https://docs.stripe.com/payments/boleto/accept-a-payment?payment-ui=direct-api):
     * a resposta vem em requires_action com next_action pix_display_qr_code
     * ou boleto_display_details. Boleto exige nome, e-mail, endereço e
     * CPF/CNPJ do pagador; Pix vale no máximo 3 dias.
     */
    private function createAsyncPaymentIntent(CreateChargeDTO $payload): CreateChargeResultDTO
    {
        $method  = (string) $payload->paymentMethod;
        $billing = array_filter([
            'name'  => $payload->metadata['customer_name'] ?? null,
            'email' => $payload->metadata['email'] ?? null,
        ], fn ($value) => is_string($value) && $value !== '');

        $params = [
            'amount'               => $this->toMinorUnits($payload->amount),
            'currency'             => strtolower($payload->currency),
            'customer'             => $payload->customerId,
            'description'          => $payload->description,
            'confirm'              => true,
            'payment_method_types' => [$method],
            'payment_method_data'  => ['type' => $method, 'billing_details' => $billing],
            'metadata'             => [...$this->chargeMetadata($payload), 'easyeye_charge' => self::CHECKOUT_KIND],
        ];

        $dueAt = $this->dueTimestamp($payload->dueDate) ?? CarbonImmutable::now(self::TIMEZONE)->endOfDay()->getTimestamp();

        if ($method === 'pix') {
            $params['payment_method_options'] = ['pix' => [
                'expires_after_seconds' => max(3600, min(self::PIX_MAX_SECONDS, $dueAt - time())),
            ]];
        } else {
            $address = $this->boletoAddress($payload->metadata['address'] ?? null);
            $taxId   = preg_replace('/\D/', '', (string) ($payload->metadata['document'] ?? ''));

            if ($address === null || ! in_array(strlen((string) $taxId), [11, 14], true) || ! isset($billing['name'], $billing['email'])) {
                return new CreateChargeResultDTO(
                    success: false,
                    externalPaymentId: null,
                    status: null,
                    amount: null,
                    rawResponse: [],
                    errorCode: 'invalid_request',
                    errorMessage: 'Stripe: boleto exige nome, e-mail, endereço completo e CPF/CNPJ do pagador.',
                );
            }

            $params['payment_method_data']['billing_details']['address'] = $address;
            $params['payment_method_data']['boleto']                     = ['tax_id' => $taxId];
            $params['payment_method_options']                            = ['boleto' => [
                'expires_after_days' => max(1, min(60, (int) ceil(($dueAt - time()) / 86400))),
            ]];
        }

        $key      = $this->limitKey('easyeye:' . ($payload->idempotencyKey ?: 'pi:' . $payload->invoiceId . ':' . $method));
        $response = $this->request('POST', 'payment_intents', $params, [], $key);

        if (! $response->successful()) {
            return $this->chargeFailure($response);
        }

        $json = $response->json() ?? [];

        return new CreateChargeResultDTO(
            success: true,
            externalPaymentId: $this->idOf($json['id'] ?? null),
            status: $this->normalizePiStatus((string) ($json['status'] ?? '')),
            amount: $this->fromMinorUnits($json['amount'] ?? null),
            rawResponse: $this->sanitizePayload($json),
            paymentUrl: $this->extractPaymentUrl($json, [
                'next_action.pix_display_qr_code.hosted_instructions_url',
                'next_action.boleto_display_details.hosted_voucher_url',
            ]),
        );
    }

    /** billing_details.address do boleto (UF de 2 letras, CEP de 8 dígitos). */
    private function boletoAddress(mixed $address): ?array
    {
        if (! is_array($address)) {
            return null;
        }

        $value = fn (string $key): string => is_scalar($address[$key] ?? null) ? trim((string) $address[$key]) : '';
        $zip   = (string) preg_replace('/\D/', '', $value('zipcode'));
        $state = strtoupper($value('state'));

        if (strlen($zip) !== 8 || strlen($state) !== 2 || $value('street') === '' || $value('city') === '') {
            return null;
        }

        return array_filter([
            'line1'       => mb_substr(trim("{$value('street')}, {$value('number')}"), 0, 200),
            'line2'       => mb_substr(trim("{$value('complement')} {$value('district')}"), 0, 200) ?: null,
            'city'        => $value('city'),
            'state'       => $state,
            'postal_code' => $zip,
            'country'     => 'BR',
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * next_action.pix_display_qr_code {data, image_url_png, expires_at,
     * hosted_instructions_url} e next_action.boleto_display_details {number,
     * pdf, hosted_voucher_url, expires_at}
     * (https://docs.stripe.com/api/payment_intents/object#payment_intent_object-next_action).
     */
    public function paymentInstructions(string $method, string $externalPaymentId, array $chargePayload = []): ?PaymentInstructionsDTO
    {
        if (! str_starts_with($externalPaymentId, 'pi_')) {
            return null;
        }

        $intent = is_array($chargePayload['next_action'] ?? null) ? $chargePayload : $this->fetchPayment($externalPaymentId);
        $date   = fn (mixed $ts): ?string => is_numeric($ts) ? CarbonImmutable::createFromTimestamp((int) $ts, self::TIMEZONE)->toIso8601String() : null;

        if ($method === 'pix' && is_array($pix = Arr::get($intent, 'next_action.pix_display_qr_code'))) {
            return PaymentInstructionsDTO::pix(
                copyPaste: $this->extractString($pix, ['data']),
                qrImageUrl: $this->extractString($pix, ['image_url_png']),
                expiresAt: $date($pix['expires_at'] ?? null),
                paymentUrl: $this->extractString($pix, ['hosted_instructions_url']),
            );
        }

        if ($method === 'boleto' && is_array($boleto = Arr::get($intent, 'next_action.boleto_display_details'))) {
            return PaymentInstructionsDTO::boleto(
                digitableLine: $this->extractString($boleto, ['number']),
                pdfUrl: $this->extractString($boleto, ['pdf']),
                dueDate: is_numeric($boleto['expires_at'] ?? null) ? CarbonImmutable::createFromTimestamp((int) $boleto['expires_at'], self::TIMEZONE)->toDateString() : null,
                paymentUrl: $this->extractString($boleto, ['hosted_voucher_url']),
            );
        }

        return null;
    }

    /**
     * Parcelamento: a Stripe não documenta parcelas para conta BR
     * (https://docs.stripe.com/payments/installments) — só à vista.
     */
    public function cardMaxInstallments(): int
    {
        return 1;
    }

    /**
     * Stripe.js (Payment Element + stripe.createConfirmationToken; pm_ do
     * fluxo legado também é aceito). Só à vista (cardMaxInstallments).
     */
    public function cardCheckoutConfig(): ?CardCheckoutConfigDTO
    {
        return new CardCheckoutConfigDTO(
            gateway: $this->code(),
            publicKey: $this->publicKey(),
            sdkUrl: self::SDK_URL,
            tokenization: 'confirmation_token',
            maxInstallments: $this->cardMaxInstallments(),
            extra: ['elements_options' => ['mode' => 'payment', 'currency' => 'brl', 'setupFutureUsage' => 'off_session', 'paymentMethodTypes' => ['card']]],
        );
    }

    /**
     * PaymentIntent no cartão confirmado no servidor
     * (https://docs.stripe.com/payments/finalize-payments-on-the-server):
     * confirmation_token (ctoken_) ou payment_method (pm_), customer e
     * setup_future_usage=off_session para guardar o cartão. Renovação:
     * payment_method salvo + off_session=true
     * (https://docs.stripe.com/payments/save-and-reuse). 3DS → requires_action
     * com o client_secret para stripe.handleNextAction no navegador. Recusa
     * vem como HTTP 402 card_error com o PaymentIntent no erro.
     */
    public function chargeCard(CardChargeDTO $payload): CreateChargeResultDTO
    {
        $token  = (string) ($payload->savedCardId ?: $payload->cardToken);
        $params = [
            'amount'               => $this->toMinorUnits($payload->amount),
            'currency'             => strtolower($payload->currency),
            'customer'             => $payload->customerId,
            'description'          => $payload->description,
            'confirm'              => true,
            'payment_method_types' => ['card'],
            'expand'               => ['payment_method'],
            'metadata'             => array_filter([
                'invoice_id'      => $payload->invoiceId,
                'subscription_id' => $payload->subscriptionId,
                'entity_id'       => $payload->entityId,
                'easyeye_charge'  => self::CHECKOUT_KIND,
            ]),
        ];

        if (str_starts_with($token, 'ctoken_')) {
            $params['confirmation_token'] = $token;
        } elseif (str_starts_with($token, 'pm_')) {
            $params['payment_method'] = $token;
        } else {
            return new CreateChargeResultDTO(success: true, externalPaymentId: null, status: 'failed', amount: null, rawResponse: [], errorCode: 'card_declined', errorMessage: __('checkout.decline.card_invalid'));
        }

        if ($payload->offSession) {
            $params['off_session'] = true;
        } elseif ($payload->saveCard) {
            $params['setup_future_usage'] = 'off_session';
        }

        $response = $this->request('POST', 'payment_intents', $params, [], $this->limitKey('easyeye:' . ($payload->idempotencyKey ?: 'card:' . $payload->invoiceId)));

        // Recusa (402 card_error): o PaymentIntent vem dentro do erro.
        if ($response->status() === 402 && is_array($response->json('error'))) {
            $intent = (array) ($response->json('error.payment_intent') ?? []);

            return $this->cardResult(
                response: $response,
                externalId: $this->idOf($intent['id'] ?? null),
                status: 'failed',
                amount: $this->fromMinorUnits($intent['amount'] ?? null),
                json: ['error' => Arr::only((array) $response->json('error'), ['type', 'code', 'decline_code', 'message'])],
                declineMessage: $this->declineMessage((array) $response->json('error')),
            );
        }

        if (! $response->successful()) {
            return $this->cardFailure($response, $this->errorSummary($response));
        }

        $json   = $response->json() ?? [];
        $status = (string) ($json['status'] ?? '');

        return $this->cardResult(
            response: $response,
            externalId: $this->idOf($json['id'] ?? null),
            status: $status === 'requires_payment_method' ? 'failed' : $this->normalizePiStatus($status),
            amount: $this->fromMinorUnits($json['amount'] ?? null),
            json: $json,
            savedCard: $this->savedCardOf($json['payment_method'] ?? null),
            nextAction: $status === 'requires_action' && is_string($json['client_secret'] ?? null)
                ? ['type' => 'stripe_handle_next_action', 'client_secret' => $json['client_secret']]
                : null,
            declineMessage: $status === 'requires_payment_method' ? $this->declineMessage((array) ($json['last_payment_error'] ?? [])) : null,
        );
    }

    /**
     * Motivo da recusa no idioma da requisição a partir de code/decline_code
     * do erro da Stripe (https://docs.stripe.com/declines/codes); a mensagem
     * da Stripe vem em inglês e não vai para a tela.
     */
    private function declineMessage(array $error): string
    {
        $code = strtolower((string) ($error['decline_code'] ?? $error['code'] ?? ''));

        $key = match ($code) {
            'insufficient_funds', 'card_velocity_exceeded', 'withdrawal_count_limit_exceeded' => 'insufficient_funds',
            'incorrect_cvc', 'invalid_cvc', 'incorrect_number', 'invalid_number', 'invalid_expiry_month', 'invalid_expiry_year', 'incorrect_zip' => 'invalid_data',
            'expired_card' => 'expired_card',
            'call_issuer', 'authentication_required', 'approve_with_id' => 'call_for_authorize',
            'card_not_supported', 'currency_not_supported', 'restricted_card', 'lost_card', 'stolen_card', 'pickup_card' => 'card_disabled',
            'fraudulent', 'merchant_blacklist', 'security_violation' => 'high_risk',
            default => 'generic',
        };

        return __("checkout.decline.{$key}");
    }

    /**
     * Troca do cartão sem cobrar: SetupIntent (usage off_session) confirmado
     * no servidor com o ConfirmationToken/PaymentMethod
     * (https://docs.stripe.com/api/setup_intents/create). 3DS → next_action
     * com client_secret e a referência seti_ para concluir: o front chama de
     * novo com o seti_ e a troca é lida em GET /v1/setup_intents/{id}.
     */
    public function saveCard(string $customerId, string $cardToken, CustomerDTO $payer): SaveCardResultDTO
    {
        if (str_starts_with($cardToken, 'seti_')) {
            $response = $this->request('GET', 'setup_intent', ['expand' => ['payment_method']], ['id' => $cardToken]);
        } else {
            $params = [
                'customer'             => $customerId,
                'usage'                => 'off_session',
                'confirm'              => true,
                'payment_method_types' => ['card'],
                'expand'               => ['payment_method'],
            ];

            if (str_starts_with($cardToken, 'ctoken_')) {
                $params['confirmation_token'] = $cardToken;
            } elseif (str_starts_with($cardToken, 'pm_')) {
                $params['payment_method'] = $cardToken;
            } else {
                return new SaveCardResultDTO(success: false, errorMessage: __('checkout.decline.card_invalid'));
            }

            $response = $this->request('POST', 'setup_intents', $params, [], $this->limitKey('easyeye:seti:' . hash('sha256', $customerId . '|' . $cardToken)));
        }

        if (! $response->successful()) {
            return new SaveCardResultDTO(success: false, errorMessage: (string) ($response->json('error.message') ?? $this->errorSummary($response)));
        }

        $json = $response->json() ?? [];

        if ($this->idOf(Arr::get($json, 'customer')) !== null && $this->idOf(Arr::get($json, 'customer')) !== $customerId) {
            return new SaveCardResultDTO(success: false, errorMessage: __('checkout.decline.foreign_card_reference'));
        }

        return match ((string) ($json['status'] ?? '')) {
            'succeeded' => ($card = $this->savedCardOf($json['payment_method'] ?? null)) !== null
                ? new SaveCardResultDTO(success: true, card: $card)
                : new SaveCardResultDTO(success: false, errorMessage: __('checkout.decline.not_returned', ['gateway' => 'Stripe'])),
            'requires_action' => new SaveCardResultDTO(success: false, nextAction: [
                'type'          => 'stripe_handle_next_action',
                'client_secret' => (string) ($json['client_secret'] ?? ''),
                'reference'     => (string) ($json['id'] ?? ''),
            ]),
            default => new SaveCardResultDTO(success: false, errorMessage: (string) (Arr::get($json, 'last_setup_error.message') ?? 'Cartão recusado.')),
        };
    }

    private function savedCardOf(mixed $paymentMethod): ?SavedCardDTO
    {
        if (is_string($paymentMethod)) {
            return SavedCardDTO::make($paymentMethod);
        }

        if (! is_array($paymentMethod)) {
            return null;
        }

        return SavedCardDTO::make($paymentMethod['id'] ?? null, Arr::get($paymentMethod, 'card.brand'), Arr::get($paymentMethod, 'card.last4'));
    }

    /** Parâmetros do POST /v1/invoices. */
    protected function buildChargePayload(CreateChargeDTO $payload): array
    {
        $params = [
            'customer'                       => $payload->customerId,
            'collection_method'              => 'send_invoice',
            'auto_advance'                   => false,
            'pending_invoice_items_behavior' => 'exclude',
            'currency'                       => strtolower($payload->currency),
            'description'                    => $payload->description,
            'metadata'                       => $this->chargeMetadata($payload),
        ];

        $dueAt = $this->dueTimestamp($payload->dueDate);

        // due_date só no futuro; sem data (ou já passada), vence em 1 dia.
        if ($dueAt !== null) {
            $params['due_date'] = $dueAt;
        } else {
            $params['days_until_due'] = 1;
        }

        return $params;
    }

    /**
     * Referência externa: ids do EasyEye (fatura, assinatura, empresa) — nada de
     * CPF/CNPJ, e-mail ou nome no metadata.
     */
    private function chargeMetadata(CreateChargeDTO $payload): array
    {
        return array_filter([
            'invoice_id'      => $payload->invoiceId,
            'subscription_id' => $payload->subscriptionId,
            'entity_id'       => $payload->entityId,
            'attempt_number'  => isset($payload->metadata['attempt_number']) ? (string) $payload->metadata['attempt_number'] : null,
            'easyeye_charge'  => self::CHARGE_KIND,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /** Fim do dia (São Paulo) do vencimento, se ainda no futuro. */
    private function dueTimestamp(?string $dueDate): ?int
    {
        if (blank($dueDate)) {
            return null;
        }

        $dueAt = CarbonImmutable::parse($dueDate, self::TIMEZONE)->endOfDay()->getTimestamp();

        return $dueAt > time() + 60 ? $dueAt : null;
    }

    /**
     * Rascunho que não chegou a ser finalizado por erro definitivo (4xx): apaga
     * para não sobrar fatura sem uso. Timeout/5xx ficam — o reenvio com as
     * mesmas chaves de idempotência continua do ponto em que parou.
     * https://docs.stripe.com/api/invoices/delete.
     */
    private function discardDraft(string $invoiceId, Response $failure): void
    {
        if ($failure->status() < 400 || $failure->status() >= 500 || $failure->status() === 429 || $failure->status() === 409) {
            return;
        }

        try {
            $this->request('DELETE', 'invoice', [], ['id' => $invoiceId]);
        } catch (GatewayIntegrationException) {
            // Melhor esforço: rascunho não é enviado nem cobrado.
        }
    }

    private function chargeFailure(Response $response, ?string $message = null): CreateChargeResultDTO
    {
        return new CreateChargeResultDTO(
            success: false,
            externalPaymentId: null,
            status: null,
            amount: null,
            rawResponse: $this->sanitizePayload($response->json() ?? []),
            errorCode: (string) $response->status(),
            errorMessage: $message ?? $this->errorSummary($response),
        );
    }

    public function fetchPayment(string $externalPaymentId): array
    {
        $endpoint = str_starts_with($externalPaymentId, 'in_') ? 'invoice' : 'payment_intent';
        $response = $this->request('GET', $endpoint, [], ['id' => $externalPaymentId]);

        if (! $response->successful()) {
            throw GatewayIntegrationException::fromHttpStatus($this->code(), $response->status(), $this->errorSummary($response));
        }

        return $this->sanitizePayload($response->json() ?? []);
    }

    // ── Webhooks ─────────────────────────────────────────────────────────────

    /**
     * Stripe-Signature: t=timestamp,v1=hmac[,v1=…][,v0=…]. HMAC-SHA256 de
     * "{t}.{corpo bruto}" com o whsec_; só o esquema v1 vale (v0 é ignorado
     * contra downgrade); várias v1 durante a rotação do segredo; comparação em
     * tempo constante; timestamp a no máximo 5 min (replay).
     * https://docs.stripe.com/webhooks?verify=verify-manually.
     */
    public function validateWebhookSignature(GatewayWebhookInputDTO $payload): bool
    {
        $secret = $this->resolveWebhookSecret();

        // Sem webhook_secret não há como validar: falha FECHADO.
        if ($secret === null || $secret === '') {
            return false;
        }

        $header = $this->headerValue($payload->headers, 'stripe-signature');

        if ($header === null) {
            return false;
        }

        $timestamp  = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$prefix, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($prefix === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($prefix === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === [] || abs(time() - $timestamp) > self::WEBHOOK_TOLERANCE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload->body, $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Evento → tipo normalizado. A fatura (in_) é o pagamento: invoice.paid e
     * payment_intent.succeeded/charge.succeeded do mesmo dinheiro não contam
     * duas vezes. https://docs.stripe.com/api/events/types.
     */
    public function parseWebhook(GatewayWebhookInputDTO $payload): NormalizedWebhookEventDTO
    {
        $event     = $payload->payload;
        $eventType = (string) ($event['type'] ?? 'unknown');
        $obj       = is_array($event['data']['object'] ?? null) ? $event['data']['object'] : [];
        $objType   = (string) ($obj['object'] ?? '');

        $normalizedType         = $this->normalizeEventType($eventType);
        $externalPaymentId      = null;
        $externalSubscriptionId = null;
        $metadata               = is_array($obj['metadata'] ?? null) ? $obj['metadata'] : [];
        $status                 = null;
        $amount                 = null;
        $dueDate                = null;
        $paymentUrl             = null;

        switch ($objType) {
            case 'invoice':
                $externalPaymentId      = $this->idOf($obj['id'] ?? null);
                $externalSubscriptionId = $this->idOf($obj['subscription'] ?? null)
                    ?? $this->idOf(Arr::get($obj, 'parent.subscription_details.subscription'));
                $metadata += (array) (Arr::get($obj, 'parent.subscription_details.metadata') ?? []);
                $status     = $this->normalizeInvoiceStatus((string) ($obj['status'] ?? ''));
                $amount     = $this->fromMinorUnits($eventType === 'invoice.paid' ? ($obj['amount_paid'] ?? null) : ($obj['amount_due'] ?? null));
                $dueDate    = $this->dateFromTimestamp($obj['due_date'] ?? null);
                $paymentUrl = $this->extractPaymentUrl($obj, ['hosted_invoice_url']);

                break;

            case 'payment_intent':
                // PaymentIntent da fatura (sem a nossa referência ou marcado
                // como fatura): o invoice.* é o evento canônico. Só o PI
                // avulso criado pelo EasyEye antes das faturas conta.
                if (blank($metadata['invoice_id'] ?? null) || ($metadata['easyeye_charge'] ?? null) === self::CHARGE_KIND) {
                    $normalizedType = 'unknown';
                }

                $externalPaymentId = $this->idOf($obj['id'] ?? null);
                $status            = $this->normalizePiStatus((string) ($obj['status'] ?? ''));
                $amount            = $this->fromMinorUnits($eventType === 'payment_intent.succeeded' ? ($obj['amount_received'] ?? $obj['amount'] ?? null) : ($obj['amount'] ?? null));
                $paymentUrl        = $this->extractPaymentUrl($obj, [
                    'next_action.boleto_display_details.hosted_voucher_url',
                    'next_action.pix_display_qr_code.hosted_instructions_url',
                ]);

                break;

            case 'charge':
                // charge.refunded também dispara no estorno parcial; só o total
                // (refunded=true) estorna a fatura. https://docs.stripe.com/api/charges/object
                if ($eventType === 'charge.refunded' && ($obj['refunded'] ?? false) !== true) {
                    $normalizedType = 'unknown';
                }

                $status = $normalizedType === 'refunded' ? 'refunded' : strtolower((string) ($obj['status'] ?? ''));
                $amount = $this->fromMinorUnits($eventType === 'charge.refunded' ? ($obj['amount_refunded'] ?? null) : ($obj['amount'] ?? null));

                $externalPaymentId = $normalizedType === 'unknown'
                    ? $this->idOf($obj['payment_intent'] ?? null) ?? $this->idOf($obj['id'] ?? null)
                    : $this->paymentIdForChargeOrDispute($obj);

                break;

            case 'dispute':
                $status = $normalizedType === 'chargeback' ? 'chargeback' : strtolower((string) ($obj['status'] ?? ''));
                $amount = $this->fromMinorUnits($obj['amount'] ?? null);

                $externalPaymentId = $normalizedType === 'unknown'
                    ? $this->idOf($obj['payment_intent'] ?? null) ?? $this->idOf($obj['charge'] ?? null)
                    : $this->paymentIdForChargeOrDispute($obj);

                break;

            case 'subscription':
                $externalSubscriptionId = $this->idOf($obj['id'] ?? null);
                $status                 = strtolower((string) ($obj['status'] ?? ''));

                break;
        }

        $created = $event['created'] ?? null;

        return new NormalizedWebhookEventDTO(
            gatewayCode: $payload->gatewayCode,
            eventType: $normalizedType,
            externalEventId: $this->idOf($event['id'] ?? null) ?? $payload->externalEventId,
            externalSubscriptionId: $externalSubscriptionId,
            externalPaymentId: $externalPaymentId,
            externalInvoiceId: null,
            status: $status,
            amount: $amount,
            currency: strtoupper((string) ($obj['currency'] ?? 'brl')),
            metadata: $metadata,
            rawPayload: $event,
            occurredAt: is_numeric($created)
                ? CarbonImmutable::createFromTimestamp((int) $created)->toIso8601String()
                : now()->toIso8601String(),
            dueDate: $dueDate,
            paymentUrl: $paymentUrl,
        );
    }

    /**
     * Tipos tratados. Ficam de fora (unknown, só registro) por serem o mesmo
     * pagamento de um evento já mapeado ou não mudarem nada aqui:
     * invoice.payment_succeeded (= invoice.paid), charge.succeeded/failed (=
     * invoice.paid/payment_failed), payment_intent.processing,
     * invoice.marked_uncollectible (ainda pagável; a régua local decide) e
     * charge.dispute.closed.
     */
    protected function eventTypeMap(): array
    {
        return [
            'invoice.finalized'      => 'created',
            'invoice.paid'           => 'paid',
            'invoice.payment_failed' => 'failed',
            'invoice.overdue'        => 'overdue',
            'invoice.voided'         => 'payment_cancelled',
            // PaymentIntent avulso do EasyEye (legado) — ver parseWebhook.
            'payment_intent.requires_action' => 'created',
            'payment_intent.succeeded'       => 'paid',
            'payment_intent.payment_failed'  => 'failed',
            'payment_intent.canceled'        => 'payment_cancelled',
            'charge.refunded'                => 'refunded',
            'charge.dispute.created'         => 'chargeback',
            // Assinaturas antigas criadas na Stripe.
            'customer.subscription.deleted' => 'cancelled',
        ];
    }

    /**
     * Pagamento (Payment.external_payment_id) do Charge/Dispute: a fatura do
     * PaymentIntent. charge.invoice existe nas versões anteriores à Basil; da
     * Basil em diante, GET /v1/invoice_payments?payment[payment_intent]=pi_….
     * Sem fatura, o próprio PaymentIntent (cobrança avulsa legada).
     * https://docs.stripe.com/api/invoice-payment/list.
     */
    private function paymentIdForChargeOrDispute(array $obj): ?string
    {
        $invoiceId = $this->idOf($obj['invoice'] ?? null);

        if ($invoiceId !== null) {
            return $invoiceId;
        }

        $paymentIntentId = $this->idOf($obj['payment_intent'] ?? null);

        if ($paymentIntentId !== null) {
            return $this->invoiceIdForPaymentIntent($paymentIntentId) ?? $paymentIntentId;
        }

        return ($obj['object'] ?? null) === 'charge'
            ? $this->idOf($obj['id'] ?? null)
            : $this->idOf($obj['charge'] ?? null);
    }

    /**
     * Timeout, 429 ou 5xx lançam (o job do webhook tenta de novo — perder um
     * estorno em silêncio seria pior); 4xx ou lista vazia, null.
     */
    private function invoiceIdForPaymentIntent(string $paymentIntentId): ?string
    {
        $response = $this->request('GET', 'invoice_payments', [
            'payment' => ['type' => 'payment_intent', 'payment_intent' => $paymentIntentId],
            'limit'   => 1,
        ]);

        if ($response->status() === 429 || $response->serverError()) {
            throw GatewayIntegrationException::fromHttpStatus($this->code(), $response->status(), $this->errorSummary($response));
        }

        if (! $response->successful()) {
            return null;
        }

        return $this->idOf($response->json('data.0.invoice'));
    }

    // ── HTTP ─────────────────────────────────────────────────────────────────

    /**
     * A API da Stripe recebe application/x-www-form-urlencoded (não JSON),
     * booleanos como "true"/"false", e o header de idempotência é
     * Idempotency-Key (só em POST). https://docs.stripe.com/api,
     * https://docs.stripe.com/api/idempotent_requests.
     */
    protected function request(
        string $method,
        string $endpointKey,
        array $payload = [],
        array $replacements = [],
        ?string $idempotencyKey = null,
    ): Response {
        $method = strtoupper($method);
        $url    = $this->buildEndpoint($endpointKey, $replacements);
        $params = $this->formParams($payload);

        try {
            $client = Http::timeout(30)
                ->acceptJson()
                ->withHeaders($this->authHeaders());

            if ($method === 'GET') {
                // Parâmetros na query string (ex.: payment[payment_intent]=pi_…).
                return $client->get($url, $params);
            }

            $client = $client->asForm();

            if ($method === 'POST' && filled($idempotencyKey)) {
                $client = $client->withHeaders(['Idempotency-Key' => $this->limitKey((string) $idempotencyKey)]);
            }

            return $method === 'DELETE'
                ? $client->delete($url, $params)
                : $client->post($url, $params);
        } catch (ConnectionException $e) {
            throw GatewayIntegrationException::timeout($this->code(), $e->getMessage());
        }
    }

    /** Bearer sk_…/rk_… e versão da API fixa. https://docs.stripe.com/api/versioning */
    protected function authHeaders(): array
    {
        return [
            'Authorization'  => 'Bearer ' . $this->resolveSecret(),
            'Stripe-Version' => (string) ($this->gatewayConfig('api_version') ?: self::API_VERSION),
        ];
    }

    protected function buildEndpoint(string $endpointKey, array $replacements = []): string
    {
        $baseUrl  = rtrim((string) ($this->gatewayConfig('base_url') ?: self::DEFAULT_BASE_URL), '/');
        $endpoint = (string) (Arr::get((array) $this->gatewayConfig('endpoints'), $endpointKey) ?: (self::ENDPOINTS[$endpointKey] ?? ''));

        if ($endpoint === '') {
            throw new InvalidArgumentException("Endpoint Stripe desconhecido: {$endpointKey}.");
        }

        foreach ($replacements as $key => $value) {
            $endpoint = str_replace('{' . $key . '}', rawurlencode((string) $value), $endpoint);
        }

        return $baseUrl . $endpoint;
    }

    /** Nulos fora; booleanos como "true"/"false" (form-encoding da Stripe). */
    private function formParams(array $params): array
    {
        $out = [];

        foreach ($params as $key => $value) {
            if ($value === null) {
                continue;
            }

            $out[$key] = match (true) {
                is_bool($value)  => $value ? 'true' : 'false',
                is_array($value) => $this->formParams($value),
                default          => $value,
            };
        }

        return $out;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** Chave de idempotência de um passo da cobrança (até 255 caracteres). */
    private function stepKey(string $baseKey, string $step): string
    {
        return $this->limitKey("easyeye:{$baseKey}:{$step}");
    }

    private function idempotencyKeyFor(string $prefix, array $parts): string
    {
        return "easyeye:{$prefix}:" . hash('sha256', (string) json_encode($parts));
    }

    private function limitKey(string $key): string
    {
        return strlen($key) <= 255 ? $key : 'easyeye:' . hash('sha256', $key);
    }

    /** Mensagem curta do erro da Stripe: tipo/código e message. https://docs.stripe.com/api/errors */
    private function errorSummary(Response $response): string
    {
        $error = $response->json('error');

        if (! is_array($error)) {
            return mb_substr($response->body(), 0, 1000);
        }

        $label = implode('/', array_filter([$error['type'] ?? null, $error['code'] ?? null, $error['decline_code'] ?? null]));

        return mb_substr(trim(($label !== '' ? "[{$label}] " : '') . ($error['message'] ?? '') . (isset($error['param']) ? " (param: {$error['param']})" : '')), 0, 1000);
    }

    private function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) === $name) {
                $value = is_array($value) ? ($value[0] ?? null) : $value;

                return is_string($value) && trim($value) !== '' ? trim($value) : null;
            }
        }

        return null;
    }

    /** Id de um campo que pode vir como string ou expandido em objeto. */
    private function idOf(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value['id'] ?? null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function searchLiteral(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }

    /** BRL tem duas casas decimais: valor em centavos. https://docs.stripe.com/currencies */
    private function toMinorUnits(float $amount): int
    {
        return (int) round($amount * 100);
    }

    private function fromMinorUnits(mixed $amount): ?float
    {
        return is_numeric($amount) ? ((float) $amount) / 100 : null;
    }

    private function dateFromTimestamp(mixed $timestamp): ?string
    {
        return is_numeric($timestamp)
            ? CarbonImmutable::createFromTimestamp((int) $timestamp, self::TIMEZONE)->toDateString()
            : null;
    }

    /** Status da Invoice: draft, open, paid, uncollectible, void. https://docs.stripe.com/api/invoices/object */
    private function normalizeInvoiceStatus(string $status): ?string
    {
        return match ($status) {
            '' => null,
            'draft', 'open' => 'pending',
            'paid'          => 'paid',
            'void'          => 'cancelled',
            'uncollectible' => 'overdue',
            default         => strtolower($status),
        };
    }

    /** https://docs.stripe.com/payments/paymentintents/lifecycle */
    private function normalizePiStatus(string $status): ?string
    {
        return match ($status) {
            ''          => null,
            'succeeded' => 'paid',
            'requires_payment_method', 'requires_confirmation',
            'requires_action', 'requires_capture', 'processing' => 'pending',
            'canceled' => 'cancelled',
            default    => strtolower($status),
        };
    }
}
