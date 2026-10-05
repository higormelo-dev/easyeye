<?php

namespace App\Services\Billing\Gateways;

use App\Contracts\Billing\QueriesGatewayRecurrences;
use App\DTOs\Billing\{
    CancelSubscriptionDTO,
    CancelSubscriptionResultDTO,
    CreateChargeDTO,
    CreateChargeResultDTO,
    CreateSubscriptionDTO,
    CreateSubscriptionResultDTO,
    CustomerDTO,
    GatewayRecurrenceChargeDTO,
    GatewayRecurrenceDTO,
    GatewayWebhookInputDTO,
    NormalizedWebhookEventDTO,
    PaymentInstructionsDTO,
};
use App\Enums\BillingCycle;
use App\Exceptions\Billing\GatewayIntegrationException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;

/**
 * Integração completa com a API Asaas v3.
 *
 * Documentação: https://docs.asaas.com
 * Autenticação (docs.asaas.com/docs/authentication-2): headers
 * "access_token", "Content-Type: application/json" e "User-Agent" (obrigatório
 * nas contas criadas a partir de 06/11/2024). Base: https://api.asaas.com/v3
 * (produção) e https://api-sandbox.asaas.com/v3 (sandbox) — os endpoints do
 * config já trazem o /v3, então ASAAS_BASE_URL vai sem ele (ver buildEndpoint).
 */
class AsaasGateway extends AbstractHttpGateway implements QueriesGatewayRecurrences
{
    public function code(): string
    {
        return 'asaas';
    }

    /** Página da cobrança do gateway aceita cartão (cartão sem transparente vai por ela). */
    public function supportsCardLink(): bool
    {
        return true;
    }

    // ── Autenticação ─────────────────────────────────────────────────────────

    protected function authHeaders(): array
    {
        return [
            // Chave colada com espaço/quebra de linha dá 401 (a doc pede para conferir).
            'access_token' => trim((string) $this->resolveSecret()),
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json',
            'User-Agent'   => $this->userAgent(),
        ];
    }

    private function userAgent(): string
    {
        $app = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) config('app.name', 'EasyEye')) ?: 'EasyEye';

        return trim($app, '-') . '/billing';
    }

    /**
     * Os endpoints do config já começam com /v3. A doc mostra a base com o
     * /v3 (https://api-sandbox.asaas.com/v3): ASAAS_BASE_URL copiado assim
     * daria /v3/v3/… (404 em tudo). O /v3 repetido é removido da base.
     */
    protected function buildEndpoint(string $endpointKey, array $replacements = []): string
    {
        $url = parent::buildEndpoint($endpointKey, $replacements);

        return (string) preg_replace('#/v3/v3(/|$)#', '/v3$1', $url, 1);
    }

    // ── Clientes ─────────────────────────────────────────────────────────────

    /**
     * Upsert: o Asaas aceita cliente duplicado e deixa a prevenção com a
     * integração (docs.asaas.com/reference/create-new-customer). Busca por
     * CPF/CNPJ (sem documento, pela nossa externalReference) e reaproveita o
     * cliente não removido — de preferência o que tem a nossa referência.
     */
    public function upsertCustomer(CustomerDTO $customer): string
    {
        $cpfCnpj = preg_replace('/\D/', '', (string) $customer->document);

        $existing = $this->findExistingCustomer($cpfCnpj, $customer->externalReference);

        if ($existing !== null) {
            return $existing;
        }

        $response = $this->post('customers', $this->buildCustomerPayload($customer));

        if (! $response->successful() || blank($response->json('id'))) {
            throw GatewayIntegrationException::fromHttpStatus(
                $this->code(),
                $response->status(),
                $this->errorMessage($response),
            );
        }

        return (string) $response->json('id');
    }

    /**
     * GET /v3/customers?cpfCnpj= (ou ?externalReference=). Falha na busca não
     * impede a criação (no pior caso, um cliente duplicado — nunca cobrança).
     */
    private function findExistingCustomer(string $cpfCnpj, ?string $externalReference): ?string
    {
        $query = match (true) {
            $cpfCnpj !== ''            => ['cpfCnpj' => $cpfCnpj],
            filled($externalReference) => ['externalReference' => $externalReference],
            default                    => null,
        };

        if ($query === null) {
            return null;
        }

        $search = $this->get('customers', $query);

        if (! $search->successful()) {
            return null;
        }

        $rows = collect((array) $search->json('data', []))
            ->filter(fn ($row) => is_array($row) && filled($row['id'] ?? null) && ! (bool) ($row['deleted'] ?? false));

        $match = (filled($externalReference) ? $rows->first(fn (array $row) => ($row['externalReference'] ?? null) === $externalReference) : null)
            ?? $rows->first();

        return $match !== null ? (string) $match['id'] : null;
    }

    /**
     * POST /v3/customers: name e cpfCnpj obrigatórios. Celular (DDD + 9 + 8
     * dígitos) vai em mobilePhone; fixo (DDD + 8), em phone — fixo em
     * mobilePhone é recusado e derrubaria a contratação.
     */
    protected function buildCustomerPayload(CustomerDTO $customer): array
    {
        return array_filter([
            'name'    => $customer->name,
            'email'   => $customer->email,
            'cpfCnpj' => preg_replace('/\D/', '', (string) $customer->document),
            ...$this->phoneFields($customer->phone),
            'externalReference' => $customer->externalReference,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /** @return array{mobilePhone?: string, phone?: string} */
    private function phoneFields(?string $raw): array
    {
        $digits = (string) preg_replace('/\D/', '', (string) $raw);

        // +55 na frente
        if (strlen($digits) >= 12 && str_starts_with($digits, '55')) {
            $digits = substr($digits, 2);
        }

        return match (true) {
            strlen($digits) === 11 && $digits[2] === '9' => ['mobilePhone' => $digits],
            strlen($digits) === 10                       => ['phone' => $digits],
            default                                      => [],
        };
    }

    // ── Assinaturas ──────────────────────────────────────────────────────────

    /**
     * Ao criar a assinatura o Asaas já gera a 1ª cobrança, com vencimento em
     * nextDueDate ("Whenever you create a new subscription, the first charge
     * for it is automatically generated" — docs.asaas.com/docs/subscriptions).
     */
    public function subscriptionIssuesFirstCharge(): bool
    {
        return true;
    }

    public function createSubscription(CreateSubscriptionDTO $payload): CreateSubscriptionResultDTO
    {
        $response = $this->post('subscriptions', $this->buildSubscriptionPayload($payload));

        if (! $response->successful()) {
            return new CreateSubscriptionResultDTO(
                success: false,
                externalSubscriptionId: null,
                externalCustomerId: null,
                status: null,
                rawResponse: $response->json() ?? [],
                errorMessage: $this->errorMessage($response),
                httpStatus: $response->status(),
            );
        }

        $json = $response->json();

        // 2xx sem id: não dá para saber se a recorrência existe — httpStatus
        // nulo faz o orquestrador procurá-la pela referência e desfazê-la.
        if (! is_array($json) || blank($json['id'] ?? null)) {
            return new CreateSubscriptionResultDTO(
                success: false,
                externalSubscriptionId: null,
                externalCustomerId: null,
                status: null,
                rawResponse: is_array($json) ? $json : [],
                errorMessage: 'Resposta do Asaas sem o id da assinatura: ' . mb_substr($response->body(), 0, 500),
                httpStatus: null,
            );
        }

        return new CreateSubscriptionResultDTO(
            success: true,
            externalSubscriptionId: (string) $json['id'],
            externalCustomerId: (string) ($json['customer'] ?? $payload->customerId),
            status: $this->normalizeStatus($json['status'] ?? null),
            rawResponse: $json ?? [],
        );
    }

    /**
     * POST /v3/subscriptions (docs.asaas.com/reference/create-new-subscription):
     * customer, billingType, value, nextDueDate e cycle obrigatórios;
     * description até 500 caracteres. Desconto/multa/juros são opcionais e
     * não são enviados (o produto não os define).
     */
    protected function buildSubscriptionPayload(CreateSubscriptionDTO $payload): array
    {
        $cycle = $this->resolveBillingCycleFromInterval($payload->interval, $payload->intervalCount);

        return array_filter([
            'customer'          => $payload->customerId,
            'billingType'       => $this->defaultBillingType(),
            'value'             => $payload->amount,
            'nextDueDate'       => $payload->firstDueDate ?? now()->addDays(1)->format('Y-m-d'),
            'cycle'             => $cycle,
            'description'       => mb_substr($payload->description ?? "Assinatura {$payload->planId}", 0, 500),
            'externalReference' => $payload->subscriptionId,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * Forma de pagamento das cobranças. Padrão UNDEFINED ("Pergunte ao
     * cliente"): na fatura (invoiceUrl) o pagador escolhe entre as formas
     * habilitadas na conta — boleto, Pix ou cartão; pago no cartão, os
     * próximos ciclos já saem no cartão (docs.asaas.com/docs/subscriptions).
     * billing.gateways.asaas.billing_type força uma só (BOLETO, PIX, CREDIT_CARD).
     */
    private function defaultBillingType(): string
    {
        $configured = strtoupper(trim((string) $this->gatewayConfig('billing_type')));

        return in_array($configured, ['UNDEFINED', 'BOLETO', 'PIX', 'CREDIT_CARD'], true) ? $configured : 'UNDEFINED';
    }

    // ── Consulta da recorrência (QueriesGatewayRecurrences) ──────────────────

    /**
     * GET /v3/subscriptions/{id} e GET /v3/subscriptions/{id}/payments
     * (docs.asaas.com: "Retrieve a single subscription" e "List payments of a
     * subscription"). 404 = não existe. A recorrência só cobra com status
     * ACTIVE e deleted=false.
     */
    public function fetchRecurrence(string $externalSubscriptionId): ?GatewayRecurrenceDTO
    {
        $response = $this->get('subscription_show', [], ['id' => $externalSubscriptionId]);

        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            throw GatewayIntegrationException::fromHttpStatus($this->code(), $response->status(), $response->body());
        }

        $json   = $response->json() ?? [];
        $status = strtoupper((string) ($json['status'] ?? ''));

        return new GatewayRecurrenceDTO(
            id: (string) ($json['id'] ?? $externalSubscriptionId),
            active: $status === 'ACTIVE' && ! (bool) ($json['deleted'] ?? false),
            status: (bool) ($json['deleted'] ?? false) ? 'DELETED' : $status,
            cycle: $this->billingCycleFromAsaas((string) ($json['cycle'] ?? '')),
            rawCycle: isset($json['cycle']) ? (string) $json['cycle'] : null,
            amount: isset($json['value']) && is_numeric($json['value']) ? (float) $json['value'] : null,
            nextDueDate: $this->extractString($json, ['nextDueDate']),
            charges: $this->recurrenceCharges($externalSubscriptionId),
        );
    }

    /** GET /v3/subscriptions?externalReference= (só as não removidas). */
    public function findRecurrenceIdsByReference(string $externalReference): array
    {
        $response = $this->get('subscriptions', ['externalReference' => $externalReference, 'limit' => 100]);

        if (! $response->successful()) {
            throw GatewayIntegrationException::fromHttpStatus($this->code(), $response->status(), $response->body());
        }

        return collect((array) $response->json('data', []))
            ->filter(fn ($row) => is_array($row) && filled($row['id'] ?? null) && ! (bool) ($row['deleted'] ?? false))
            ->map(fn (array $row) => (string) $row['id'])
            ->values()
            ->all();
    }

    /**
     * Todas as cobranças da recorrência (páginas de 100; teto de segurança
     * de 20 páginas). Removidas ficam de fora.
     *
     * @return list<GatewayRecurrenceChargeDTO>
     */
    private function recurrenceCharges(string $externalSubscriptionId): array
    {
        $charges = [];
        $offset  = 0;

        for ($page = 0; $page < 20; $page++) {
            $response = $this->get('subscription_payments', ['limit' => 100, 'offset' => $offset], ['id' => $externalSubscriptionId]);

            if (! $response->successful()) {
                throw GatewayIntegrationException::fromHttpStatus($this->code(), $response->status(), $response->body());
            }

            foreach ((array) $response->json('data', []) as $row) {
                if (! is_array($row) || blank($row['id'] ?? null) || blank($row['dueDate'] ?? null) || (bool) ($row['deleted'] ?? false)) {
                    continue;
                }

                $raw = strtoupper((string) ($row['status'] ?? ''));

                $charges[] = new GatewayRecurrenceChargeDTO(
                    id: (string) $row['id'],
                    status: match ($raw) {
                        'RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH', 'DUNNING_RECEIVED' => GatewayRecurrenceChargeDTO::PAID,
                        'PENDING', 'AWAITING_RISK_ANALYSIS' => GatewayRecurrenceChargeDTO::PENDING,
                        'OVERDUE', 'DUNNING_REQUESTED' => GatewayRecurrenceChargeDTO::OVERDUE,
                        default => GatewayRecurrenceChargeDTO::OTHER,
                    },
                    rawStatus: $raw,
                    dueDate: (string) $row['dueDate'],
                    paidOn: $this->extractString($row, ['paymentDate', 'clientPaymentDate', 'confirmedDate']),
                    amount: isset($row['value']) && is_numeric($row['value']) ? (float) $row['value'] : null,
                    paymentUrl: $this->extractPaymentUrl($row, ['invoiceUrl', 'bankSlipUrl']),
                );
            }

            if ($response->json('hasMore') !== true) {
                break;
            }

            $offset += 100;
        }

        return $charges;
    }

    private function billingCycleFromAsaas(string $cycle): ?BillingCycle
    {
        return match (strtoupper($cycle)) {
            'MONTHLY'      => BillingCycle::Monthly,
            'QUARTERLY'    => BillingCycle::Quarterly,
            'SEMIANNUALLY' => BillingCycle::Semiannual,
            'YEARLY'       => BillingCycle::Yearly,
            default        => null,
        };
    }

    /**
     * DELETE /v3/subscriptions/{id} (docs.asaas.com/reference/remove-subscription):
     * 200 {"deleted": true, "id": …}; remove também as cobranças pendentes ou
     * vencidas da recorrência (cada uma chega como PAYMENT_DELETED). 404 =
     * não existe na conta: só conta como cancelada se a consulta confirmar que
     * ela já foi removida — um 404 de URL errada nunca vira "cancelada".
     */
    public function cancelSubscription(CancelSubscriptionDTO $payload): CancelSubscriptionResultDTO
    {
        $response = $this->request('DELETE', 'subscription_cancel', [], ['id' => $payload->externalSubscriptionId]);
        $json     = $response->json();

        if ($response->successful() && ! (is_array($json) && ($json['deleted'] ?? null) === false)) {
            return new CancelSubscriptionResultDTO(
                success: true,
                status: 'cancelled',
                rawResponse: is_array($json) ? $json : [],
            );
        }

        if ($response->status() === 404 && $this->isAlreadyRemoved($payload->externalSubscriptionId)) {
            return new CancelSubscriptionResultDTO(
                success: true,
                status: 'cancelled',
                rawResponse: is_array($json) ? $json : [],
            );
        }

        return new CancelSubscriptionResultDTO(
            success: false,
            status: null,
            rawResponse: is_array($json) ? $json : [],
            errorMessage: $this->errorMessage($response),
        );
    }

    /** GET /v3/subscriptions/{id} devolve a removida com deleted=true. */
    private function isAlreadyRemoved(string $externalSubscriptionId): bool
    {
        try {
            $response = $this->get('subscription_show', [], ['id' => $externalSubscriptionId]);
        } catch (GatewayIntegrationException) {
            return false;
        }

        return $response->successful()
            && (string) $response->json('id') === $externalSubscriptionId
            && $response->json('deleted') === true;
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
                errorMessage: $this->errorMessage($response),
            );
        }

        $json = $response->json();

        return new CreateChargeResultDTO(
            success: true,
            externalPaymentId: (string) ($json['id'] ?? ''),
            status: $this->normalizeAsaasPaymentStatus($json['status'] ?? ''),
            amount: isset($json['value']) ? (float) $json['value'] : null,
            rawResponse: $this->sanitizePayload($json ?? []),
            // invoiceUrl: página da fatura (boleto, Pix ou cartão); bankSlipUrl: PDF do boleto.
            paymentUrl: $this->extractPaymentUrl($json ?? [], ['invoiceUrl', 'bankSlipUrl']),
        );
    }

    /**
     * DELETE /v3/payments/{id} (docs.asaas.com/reference/delete-payment):
     * remove a cobrança em aberto ({"deleted": true}); chega depois o
     * PAYMENT_DELETED. Cobrança já paga não é removida (o Asaas recusa).
     */
    public function cancelCharge(string $externalPaymentId): bool
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $externalPaymentId) !== 1) {
            return false;
        }

        try {
            $response = $this->request('DELETE', 'payments', [], ['id' => $externalPaymentId]);
        } catch (GatewayIntegrationException) {
            return false;
        }

        return $response->successful() && $response->json('deleted') === true;
    }

    protected function buildChargePayload(CreateChargeDTO $payload): array
    {
        return array_filter([
            'customer'          => $payload->customerId,
            'billingType'       => $this->mapPaymentMethod($payload->paymentMethod),
            'value'             => $payload->amount,
            'dueDate'           => $payload->dueDate ?? now()->addDays(3)->format('Y-m-d'),
            'description'       => mb_substr($payload->description, 0, 500),
            'externalReference' => $payload->invoiceId,
        ]);
    }

    // ── Checkout transparente ────────────────────────────────────────────────

    /**
     * Pix e boleto na tela do EasyEye. Cartão segue pela fatura do Asaas
     * (invoiceUrl): a API só cobra cartão recebendo os dados no servidor
     * (POST /v3/payments com creditCard ou /v3/creditCard/tokenizeCreditCard)
     * — não há tokenização no navegador (https://docs.asaas.com/docs/pci-dss-1).
     */
    public function transparentMethods(): array
    {
        return ['pix', 'boleto'];
    }

    /**
     * Pix: GET /v3/payments/{id}/pixQrCode → encodedImage (PNG base64),
     * payload (copia-e-cola), expirationDate — vale para cobranças PIX, BOLETO
     * e UNDEFINED (https://docs.asaas.com/reference/obter-qr-code-para-pagamentos-via-pix).
     * Boleto: GET /v3/payments/{id}/identificationField → identificationField
     * (linha digitável) e barCode, para BOLETO e UNDEFINED
     * (https://docs.asaas.com/reference/obter-linha-digitavel-do-boleto), e o
     * PDF em bankSlipUrl. 400/404 = a cobrança não tem essa forma (null).
     */
    public function paymentInstructions(string $method, string $externalPaymentId, array $chargePayload = []): ?PaymentInstructionsDTO
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $externalPaymentId) !== 1) {
            return null;
        }

        $endpoint = match ($method) {
            'pix'    => 'payment_pix_qr_code',
            'boleto' => 'payment_boleto_line',
            default  => null,
        };

        if ($endpoint === null) {
            return null;
        }

        $response = $this->get($endpoint, [], ['id' => $externalPaymentId]);

        if ($response->clientError()) {
            return null;
        }

        if (! $response->successful()) {
            throw GatewayIntegrationException::fromHttpStatus($this->code(), $response->status(), $this->errorMessage($response));
        }

        $json = $response->json() ?? [];

        if ($method === 'pix') {
            $expires = $this->extractString($json, ['expirationDate']);

            return PaymentInstructionsDTO::pix(
                copyPaste: $this->extractString($json, ['payload']),
                qrBase64: $this->extractString($json, ['encodedImage']),
                expiresAt: $expires ? CarbonImmutable::parse($expires, config('app.timezone'))->toIso8601String() : null,
                paymentUrl: $this->extractString($chargePayload, ['invoiceUrl']),
            );
        }

        $pdfUrl = $this->extractString($chargePayload, ['bankSlipUrl']);
        $due    = $this->extractString($chargePayload, ['dueDate']);

        if ($pdfUrl === null) {
            try {
                $payment = $this->fetchPayment($externalPaymentId);
                $pdfUrl  = $this->extractString($payment, ['bankSlipUrl']);
                $due ??= $this->extractString($payment, ['dueDate']);
            } catch (GatewayIntegrationException) {
                // Linha digitável já basta para pagar.
            }
        }

        return PaymentInstructionsDTO::boleto(
            digitableLine: $this->extractString($json, ['identificationField']),
            barcode: $this->extractString($json, ['barCode']),
            pdfUrl: $pdfUrl,
            dueDate: $due,
        );
    }

    // ── Webhooks ─────────────────────────────────────────────────────────────

    /**
     * O Asaas manda o token cadastrado no webhook (painel ou API, 32 a 255
     * caracteres) no header "asaas-access-token"
     * (docs.asaas.com/docs/receive-asaas-events-at-your-webhook-endpoint).
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

        $provided = $payload->headers['asaas-access-token']
            ?? $payload->headers['Asaas-Access-Token']
            ?? null;

        if (! is_string($provided) || $provided === '') {
            return false;
        }

        return hash_equals(trim($secret), trim($provided));
    }

    public function parseWebhook(GatewayWebhookInputDTO $payload): NormalizedWebhookEventDTO
    {
        $event   = (string) ($payload->payload['event'] ?? 'unknown');
        $payment = is_array($payload->payload['payment'] ?? null) ? $payload->payload['payment'] : [];

        $normalizedType = $this->normalizeEventType($event);
        // Eventos de cobrança trazem payment.subscription; os da assinatura
        // (SUBSCRIPTION_*), o objeto subscription.
        $subscriptionObject     = is_array($payload->payload['subscription'] ?? null) ? $payload->payload['subscription'] : [];
        $externalSubscriptionId = $payment['subscription'] ?? ($subscriptionObject['id'] ?? null);
        $externalPaymentId      = $payment['id'] ?? null;
        $amount                 = isset($payment['value']) && is_numeric($payment['value']) ? (float) $payment['value'] : null;
        $status                 = filled($payment['status'] ?? null) ? $this->normalizeAsaasPaymentStatus((string) $payment['status']) : null;

        // externalReference: na cobrança avulsa é o id da fatura; nas
        // cobranças geradas pela assinatura, o da assinatura (herdado dela).
        // Evento SUBSCRIPTION_* só resolve pelo id da recorrência: pela
        // referência, a remoção de uma recorrência duplicada/órfã (mesma
        // externalReference) encerraria a nossa assinatura vigente.
        $metadata    = [];
        $externalRef = $payment['externalReference'] ?? null;

        if (is_string($externalRef) && $externalRef !== '') {
            $metadata[filled($payment['subscription'] ?? null) ? 'subscription_id' : 'invoice_id'] = $externalRef;
        }

        // Cobrança apagada que voltou: o processamento reabre pagamento e fatura.
        if ($event === 'PAYMENT_RESTORED') {
            $metadata['restored'] = true;
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
            metadata: $metadata,
            rawPayload: $payload->payload,
            occurredAt: now()->toIso8601String(),
            dueDate: $this->extractString($payment, ['dueDate']),
            // invoiceUrl: página da fatura (boleto, Pix ou cartão); bankSlipUrl: PDF do boleto.
            paymentUrl: $this->extractPaymentUrl($payment, ['invoiceUrl', 'bankSlipUrl']),
        );
    }

    // ── Mapa de eventos ───────────────────────────────────────────────────────

    /**
     * Eventos de cobrança: docs.asaas.com/docs/payment-events; de assinatura:
     * docs.asaas.com/docs/subscription-events. Todos os documentados estão
     * aqui — 'unknown' é explícito (só registro, sem mudar estado).
     *
     *  - PAYMENT_CONFIRMED (pago, saldo ainda não disponível) e
     *    PAYMENT_RECEIVED (saldo disponível) chegam os dois para a mesma
     *    cobrança: o 2º é duplicado (idempotência por Payment).
     *  - PAYMENT_AUTHORIZED: cartão autorizado aguardando captura — só existe
     *    em pré-autorização, que não usamos; não é pagamento.
     *  - PAYMENT_DELETED apaga só a cobrança (a assinatura segue cobrando);
     *    PAYMENT_RESTORED a traz de volta (volta a ser uma cobrança emitida).
     *  - PAYMENT_PARTIALLY_REFUNDED: estorno parcial não estorna a fatura.
     *  - PAYMENT_REFUND_IN_PROGRESS / _DENIED: o estorno só vale no REFUNDED.
     *  - PAYMENT_AWAITING_CHARGEBACK_REVERSAL: disputa ganha pelo lojista.
     *  - PAYMENT_RECEIVED_IN_CASH_UNDONE: desfeita a baixa manual — o
     *    pagamento registrado deixa de valer.
     */
    protected function eventTypeMap(): array
    {
        return [
            'PAYMENT_CREATED'                              => 'created',
            'PAYMENT_UPDATED'                              => 'created',
            'PAYMENT_RESTORED'                             => 'created',
            'PAYMENT_AWAITING_RISK_ANALYSIS'               => 'unknown',
            'PAYMENT_APPROVED_BY_RISK_ANALYSIS'            => 'unknown',
            'PAYMENT_REPROVED_BY_RISK_ANALYSIS'            => 'failed',
            'PAYMENT_AUTHORIZED'                           => 'unknown',
            'PAYMENT_CONFIRMED'                            => 'paid',
            'PAYMENT_RECEIVED'                             => 'paid',
            'PAYMENT_ANTICIPATED'                          => 'paid',
            'PAYMENT_DUNNING_RECEIVED'                     => 'paid',
            'PAYMENT_CREDIT_CARD_CAPTURE_REFUSED'          => 'failed',
            'PAYMENT_OVERDUE'                              => 'overdue',
            'PAYMENT_DELETED'                              => 'payment_cancelled',
            'PAYMENT_REFUNDED'                             => 'refunded',
            'PAYMENT_PARTIALLY_REFUNDED'                   => 'unknown',
            'PAYMENT_REFUND_IN_PROGRESS'                   => 'unknown',
            'PAYMENT_REFUND_DENIED'                        => 'unknown',
            'PAYMENT_RECEIVED_IN_CASH_UNDONE'              => 'refunded',
            'PAYMENT_CHARGEBACK_REQUESTED'                 => 'chargeback',
            'PAYMENT_CHARGEBACK_DISPUTE'                   => 'chargeback',
            'PAYMENT_AWAITING_CHARGEBACK_REVERSAL'         => 'unknown',
            'PAYMENT_DUNNING_REQUESTED'                    => 'unknown',
            'PAYMENT_BANK_SLIP_CANCELLED'                  => 'unknown',
            'PAYMENT_BANK_SLIP_VIEWED'                     => 'unknown',
            'PAYMENT_CHECKOUT_VIEWED'                      => 'unknown',
            'PAYMENT_SPLIT_CANCELLED'                      => 'unknown',
            'PAYMENT_SPLIT_DIVERGENCE_BLOCK'               => 'unknown',
            'PAYMENT_SPLIT_DIVERGENCE_BLOCK_FINISHED'      => 'unknown',
            'SUBSCRIPTION_CREATED'                         => 'unknown',
            'SUBSCRIPTION_UPDATED'                         => 'unknown',
            'SUBSCRIPTION_INACTIVATED'                     => 'cancelled',
            'SUBSCRIPTION_DELETED'                         => 'cancelled',
            'SUBSCRIPTION_SPLIT_DISABLED'                  => 'unknown',
            'SUBSCRIPTION_SPLIT_DIVERGENCE_BLOCK'          => 'unknown',
            'SUBSCRIPTION_SPLIT_DIVERGENCE_BLOCK_FINISHED' => 'unknown',
        ];
    }

    // ── Helpers privados ─────────────────────────────────────────────────────

    /**
     * Status da cobrança (enum "status" de docs.asaas.com/reference/retrieve-a-single-payment).
     * Pedido/andamento de estorno ainda não é estorno; AWAITING_CHARGEBACK_REVERSAL
     * (disputa ganha) segue como valor bruto.
     */
    private function normalizeAsaasPaymentStatus(string $status): string
    {
        return match (strtoupper($status)) {
            'RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH', 'DUNNING_RECEIVED' => 'paid',
            'PENDING', 'AWAITING_RISK_ANALYSIS', 'REFUND_REQUESTED', 'REFUND_IN_PROGRESS' => 'pending',
            'OVERDUE', 'DUNNING_REQUESTED' => 'failed',
            'REFUNDED' => 'refunded',
            'CHARGEBACK_REQUESTED', 'CHARGEBACK_DISPUTE' => 'chargeback',
            'CANCELLED', 'DELETED' => 'cancelled',
            default => strtolower($status),
        };
    }

    private function mapPaymentMethod(?string $method): string
    {
        return match (strtolower((string) $method)) {
            'pix'         => 'PIX',
            'credit_card' => 'CREDIT_CARD',
            'boleto'      => 'BOLETO',
            default       => $this->defaultBillingType(),
        };
    }

    /**
     * Erro do Asaas: {"errors": [{"code": …, "description": …}]}
     * (docs.asaas.com/reference/http-response-codes). Sem esse formato, o corpo.
     */
    private function errorMessage(Response $response): string
    {
        $errors = $response->json('errors');

        $message = collect(is_array($errors) ? $errors : [])
            ->map(fn ($error) => is_array($error)
                ? implode(': ', array_filter([(string) ($error['code'] ?? ''), (string) ($error['description'] ?? '')], 'strlen'))
                : '')
            ->filter()
            ->implode('; ');

        return mb_substr($message !== '' ? "HTTP {$response->status()} {$message}" : $response->body(), 0, 1000);
    }

    private function resolveBillingCycleFromInterval(string $interval, int $count): string
    {
        if ($interval === 'year') {
            return 'YEARLY';
        }

        return match ($count) {
            1       => 'MONTHLY',
            3       => 'QUARTERLY',
            6       => 'SEMIANNUALLY',
            12      => 'YEARLY',
            default => 'MONTHLY',
        };
    }
}
