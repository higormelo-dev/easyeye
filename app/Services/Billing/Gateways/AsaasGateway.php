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
    GatewayHealthDTO,
    GatewayRecurrenceChargeDTO,
    GatewayRecurrenceDTO,
    GatewayWebhookInputDTO,
    HostedCheckoutDTO,
    HostedCheckoutResultDTO,
    NormalizedWebhookEventDTO,
    PaymentInstructionsDTO,
    RefundRequestDTO,
    RefundResultDTO,
};
use App\Enums\BillingCycle;
use App\Exceptions\Billing\GatewayIntegrationException;
use App\Models\Billing\GatewayCustomer;
use App\Services\Billing\GatewayAlertService;
use App\Support\Billing\{HostedCheckoutReference, PaymentUrl};
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Integração completa com a API Asaas v3.
 *
 * Documentação: https://docs.asaas.com
 * Autenticação (https://docs.asaas.com/docs/autenticação-1): headers
 * "access_token", "Content-Type: application/json" e "User-Agent" (obrigatório
 * para contas raiz criadas a partir de 13/06/2024). Base: https://api.asaas.com/v3
 * (produção, chave $aact_prod_…) e https://api-sandbox.asaas.com/v3
 * (sandbox, chave $aact_hmlg_…) — os endpoints já trazem o /v3, então
 * ASAAS_BASE_URL vai sem ele (ver buildEndpoint).
 *
 * Notificações do Asaas (e-mail/SMS/WhatsApp/voz/Correios ao pagador)
 * ficam desligadas em todo cliente nosso (notificationDisabled —
 * https://docs.asaas.com/docs/notificacoes): só a régua do EasyEye fala com
 * a clínica.
 */
class AsaasGateway extends AbstractHttpGateway implements QueriesGatewayRecurrences
{
    /**
     * Caminhos da API usados aqui (config/billing.php sobrescreve). Os que
     * faltarem no config saem daqui — sem eles a chamada iria para a raiz.
     */
    private const ENDPOINTS = [
        'customers'               => '/v3/customers',
        'customer_show'           => '/v3/customers/{id}',
        'subscriptions'           => '/v3/subscriptions',
        'subscription_cancel'     => '/v3/subscriptions/{id}',
        'subscription_show'       => '/v3/subscriptions/{id}',
        'subscription_update'     => '/v3/subscriptions/{id}',
        'subscription_payments'   => '/v3/subscriptions/{id}/payments',
        'charges'                 => '/v3/payments',
        'payments'                => '/v3/payments/{id}',
        'payment_pix_qr_code'     => '/v3/payments/{id}/pixQrCode',
        'payment_boleto_line'     => '/v3/payments/{id}/identificationField',
        'payment_refund'          => '/v3/payments/{id}/refund',
        'payment_bankslip_refund' => '/v3/payments/{id}/bankSlip/refund',
        'checkouts'               => '/v3/checkouts',
        'checkout_cancel'         => '/v3/checkouts/{id}/cancel',
        'account_status'          => '/v3/myAccount/status/',
    ];

    /** Status da cobrança que já é dinheiro recebido (enum "status" de "Recuperar uma única cobrança"). */
    private const PAID_STATUSES = ['RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH', 'DUNNING_RECEIVED'];

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
     * Os endpoints já começam com /v3. A doc mostra a base com o /v3
     * (https://api-sandbox.asaas.com/v3): ASAAS_BASE_URL copiado assim daria
     * /v3/v3/… (404 em tudo). O /v3 repetido é removido da base.
     */
    protected function buildEndpoint(string $endpointKey, array $replacements = []): string
    {
        $configured = Arr::get((array) $this->gatewayConfig('endpoints'), $endpointKey);
        $endpoint   = is_string($configured) && $configured !== '' ? $configured : (self::ENDPOINTS[$endpointKey] ?? '');

        foreach ($replacements as $key => $value) {
            $endpoint = str_replace('{' . $key . '}', rawurlencode((string) $value), $endpoint);
        }

        $url = rtrim((string) $this->gatewayConfig('base_url'), '/') . $endpoint;

        return (string) preg_replace('#/v3/v3(/|$)#', '/v3$1', $url, 1);
    }

    /**
     * 401/403 em qualquer chamada: chave inválida/expirada/desabilitada, de
     * outro ambiente (invalid_environment) ou IP fora da whitelist — alerta
     * ao time (no máximo uma vez por hora). O chamador recebe a resposta.
     */
    protected function request(
        string $method,
        string $endpointKey,
        array $payload = [],
        array $replacements = [],
        ?string $idempotencyKey = null,
    ): Response {
        $response = parent::request($method, $endpointKey, $payload, $replacements, $idempotencyKey);

        if (in_array($response->status(), [401, 403], true)) {
            app(GatewayAlertService::class)->credentialRejected($this->code(), $response->status(), $this->errorMessage($response));
        }

        return $response;
    }

    // ── Clientes ─────────────────────────────────────────────────────────────

    /**
     * Upsert: o Asaas aceita cliente duplicado e deixa a prevenção com a
     * integração (https://docs.asaas.com/reference/criar-novo-cliente). Busca
     * por CPF/CNPJ (sem documento, pela nossa externalReference) e reaproveita
     * o cliente não removido — de preferência o que tem a nossa referência.
     * Cliente reaproveitado com as notificações do Asaas ligadas tem elas
     * desligadas (PUT /v3/customers/{id} notificationDisabled).
     */
    public function upsertCustomer(CustomerDTO $customer): string
    {
        $cpfCnpj = preg_replace('/\D/', '', (string) $customer->document);

        $existing = $this->findExistingCustomer($cpfCnpj, $customer->externalReference);

        if ($existing !== null) {
            $this->ensureNotificationsDisabled((string) $existing['id'], $existing, $customer->entityId);

            return (string) $existing['id'];
        }

        $response = $this->post('customers', $this->buildCustomerPayload($customer));

        if (! $response->successful() || blank($response->json('id'))) {
            throw GatewayIntegrationException::fromHttpStatus(
                $this->code(),
                $response->status(),
                $this->errorMessage($response),
            );
        }

        $id = (string) $response->json('id');

        if ($response->json('notificationDisabled') !== false) {
            GatewayCustomer::markNotificationsDisabled($this->code(), $id, $customer->entityId, ['source' => 'created']);
        }

        return $id;
    }

    /**
     * Desliga as notificações do Asaas para o cliente (idempotente): já
     * marcado do nosso lado, nada; o cadastro já com notificationDisabled,
     * só marca; senão PUT /v3/customers/{id} {"notificationDisabled": true}
     * (o update aceita o campo — https://docs.asaas.com/reference/atualizar-cliente-existente).
     * $customerRow: o cadastro já consultado (evita outro GET). Falha não
     * impede a cobrança (nunca lança) — o comando
     * billing:asaas-disable-notifications refaz.
     *
     * @return 'already'|'disabled'|'failed'|'not_found'
     */
    public function ensureNotificationsDisabled(string $customerId, ?array $customerRow = null, ?string $entityId = null, bool $dryRun = false): string
    {
        try {
            if (GatewayCustomer::notificationsDisabled($this->code(), $customerId)) {
                return 'already';
            }

            if ($customerRow === null) {
                $show = $this->get('customer_show', [], ['id' => $customerId]);

                if ($show->status() === 404) {
                    return 'not_found';
                }

                if (! $show->successful()) {
                    return 'failed';
                }

                $customerRow = (array) ($show->json() ?? []);
            }

            if (($customerRow['notificationDisabled'] ?? null) === true) {
                if (! $dryRun) {
                    GatewayCustomer::markNotificationsDisabled($this->code(), $customerId, $entityId, ['source' => 'already_disabled']);
                }

                return 'already';
            }

            if ($dryRun) {
                return 'disabled';
            }

            $update = $this->request('PUT', 'customer_show', ['notificationDisabled' => true], ['id' => $customerId]);

            if (! $update->successful()) {
                Log::warning('Asaas: não foi possível desligar as notificações do cliente.', ['customer' => $customerId, 'status' => $update->status()]);

                return 'failed';
            }

            GatewayCustomer::markNotificationsDisabled($this->code(), $customerId, $entityId, ['source' => 'updated']);

            return 'disabled';
        } catch (Throwable $e) {
            Log::warning('Asaas: falha ao desligar as notificações do cliente.', ['customer' => $customerId, 'error' => $e->getMessage()]);

            return 'failed';
        }
    }

    /**
     * GET /v3/customers?cpfCnpj= (ou ?externalReference=). Falha na busca não
     * impede a criação (no pior caso, um cliente duplicado — nunca cobrança).
     *
     * @return array<string, mixed>|null o cadastro encontrado
     */
    private function findExistingCustomer(string $cpfCnpj, ?string $externalReference): ?array
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

        return (filled($externalReference) ? $rows->first(fn (array $row) => ($row['externalReference'] ?? null) === $externalReference) : null)
            ?? $rows->first();
    }

    /**
     * POST /v3/customers: name e cpfCnpj obrigatórios. Celular (DDD + 9 + 8
     * dígitos) vai em mobilePhone; fixo (DDD + 8), em phone — fixo em
     * mobilePhone é recusado e derrubaria a contratação.
     * notificationDisabled: true — o Asaas não manda nada ao pagador.
     */
    protected function buildCustomerPayload(CustomerDTO $customer): array
    {
        return array_filter([
            'name'    => $customer->name,
            'email'   => $customer->email,
            'cpfCnpj' => preg_replace('/\D/', '', (string) $customer->document),
            ...$this->phoneFields($customer->phone),
            'externalReference'    => $customer->externalReference,
            'notificationDisabled' => true,
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
     * for it is automatically generated" — https://docs.asaas.com/docs/assinaturas).
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
     * POST /v3/subscriptions (https://docs.asaas.com/reference/criar-nova-assinatura):
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
     * habilitadas na conta — boleto, Pix ou cartão. A doc não promete que um
     * pagamento no cartão por essa fatura passe a cobrar o cartão nos ciclos
     * seguintes; recorrência no cartão é pelo Asaas Checkout (RECURRENT —
     * createHostedCheckout). billing.gateways.asaas.billing_type força uma
     * forma só (BOLETO, PIX, CREDIT_CARD).
     */
    private function defaultBillingType(): string
    {
        $configured = strtoupper(trim((string) $this->gatewayConfig('billing_type')));

        return in_array($configured, ['UNDEFINED', 'BOLETO', 'PIX', 'CREDIT_CARD'], true) ? $configured : 'UNDEFINED';
    }

    // ── Consulta da recorrência (QueriesGatewayRecurrences) ──────────────────

    /**
     * GET /v3/subscriptions/{id} e GET /v3/subscriptions/{id}/payments
     * (https://docs.asaas.com/reference/listar-cobrancas-de-uma-assinatura).
     * 404 = não existe. A recorrência só cobra com status ACTIVE e
     * deleted=false.
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
     * PUT /v3/subscriptions/{id} {"nextDueDate": …}: muda o vencimento da
     * próxima cobrança a ser gerada (as já geradas não mudam —
     * https://docs.asaas.com/reference/atualizar-assinatura-existente). Em
     * assinatura no cartão a doc exige a tokenização habilitada na conta:
     * sem ela o Asaas recusa e o chamador avisa o time.
     */
    public function updateRecurrenceNextDueDate(string $externalSubscriptionId, string $nextDueDate): bool
    {
        $response = $this->request('PUT', 'subscription_update', ['nextDueDate' => $nextDueDate], ['id' => $externalSubscriptionId]);

        if (! $response->successful()) {
            Log::warning('Asaas: vencimento da recorrência não alterado.', [
                'subscription' => $externalSubscriptionId,
                'next_due'     => $nextDueDate,
                'error'        => $this->errorMessage($response),
            ]);

            return false;
        }

        return true;
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
                    status: match (true) {
                        in_array($raw, self::PAID_STATUSES, true)                   => GatewayRecurrenceChargeDTO::PAID,
                        in_array($raw, ['PENDING', 'AWAITING_RISK_ANALYSIS'], true) => GatewayRecurrenceChargeDTO::PENDING,
                        in_array($raw, ['OVERDUE', 'DUNNING_REQUESTED'], true)      => GatewayRecurrenceChargeDTO::OVERDUE,
                        default                                                     => GatewayRecurrenceChargeDTO::OTHER,
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

    /**
     * Ciclo da recorrência do Asaas → ciclo vendido. BIMONTHLY, WEEKLY e
     * BIWEEKLY existem no Asaas mas não no produto: null (a conciliação
     * trata como "ciclo que o produto não vende").
     */
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
     * DELETE /v3/subscriptions/{id} (https://docs.asaas.com/reference/remover-assinatura):
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

    /**
     * POST /v3/payments. O Asaas não tem chave de idempotência: depois de uma
     * falha sem resposta definitiva (timeout, conexão, 5xx), a cobrança pode
     * ter sido criada mesmo assim. Por isso:
     *  - retentativa depois de falha inconclusiva (lookupBeforeCreate):
     *    antes de criar, procura a cobrança com a nossa referência
     *    (GET /v3/payments?externalReference=) que ainda não conhecemos e a
     *    reaproveita;
     *  - a própria chamada sem resposta definitiva: procura do mesmo jeito
     *    antes de devolver o erro.
     * Nos dois casos as cobranças que a fatura já conhece (knownChargeIds —
     * a vigente, as substituídas) e as vencidas/canceladas/removidas nunca
     * contam como a nova.
     * (https://docs.asaas.com/docs/cobrança-duplicada-após-retry-sem-idempotência).
     */
    public function createCharge(CreateChargeDTO $payload): CreateChargeResultDTO
    {
        $body = $this->buildChargePayload($payload);

        if ($payload->shouldLookupBeforeCreate() && ($existing = $this->findUnknownCharge($payload, $body['billingType'])) !== null) {
            return $this->reusedChargeResult($existing);
        }

        try {
            $response = $this->request(
                method: 'POST',
                endpointKey: 'charges',
                payload: $body,
                idempotencyKey: $payload->idempotencyKey,
            );
        } catch (GatewayIntegrationException $e) {
            if ($e->getTriggerType() === 'timeout' && ($existing = $this->findUnknownCharge($payload, $body['billingType'], quiet: true)) !== null) {
                return $this->reusedChargeResult($existing);
            }

            throw $e;
        }

        if ($response->serverError() && ($existing = $this->findUnknownCharge($payload, $body['billingType'], quiet: true)) !== null) {
            return $this->reusedChargeResult($existing);
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
     * Cobrança da fatura (externalReference = id da fatura) criada no Asaas
     * e que não conhecemos: mesma forma e valor, não removida nem cancelada.
     * Falha na consulta: null (no pior caso, a tentativa segue — o chamador
     * já registra a falha).
     *
     * @return array<string, mixed>|null
     */
    private function findUnknownCharge(CreateChargeDTO $payload, string $billingType, bool $quiet = false): ?array
    {
        if (blank($payload->invoiceId)) {
            return null;
        }

        try {
            $response = $this->get('charges', ['externalReference' => $payload->invoiceId, 'limit' => 100]);
        } catch (GatewayIntegrationException $e) {
            if (! $quiet) {
                Log::warning('Asaas: não foi possível conferir cobrança anterior pela referência.', ['invoice_id' => $payload->invoiceId, 'error' => $e->getMessage()]);
            }

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $known = array_map('strval', (array) $payload->knownChargeIds);

        return collect((array) $response->json('data', []))
            ->filter(fn ($row) => is_array($row)
                && filled($row['id'] ?? null)
                && ! in_array((string) $row['id'], $known, true)
                && ! (bool) ($row['deleted'] ?? false)
                && ($row['externalReference'] ?? null) === $payload->invoiceId
                && strtoupper((string) ($row['billingType'] ?? '')) === $billingType
                && isset($row['value']) && abs((float) $row['value'] - $payload->amount) < 0.005
                // Vencida, cancelada, estornada ou em disputa nunca é "a
                // cobrança que acabou de ser criada" (a vigente vencida da
                // fatura reemitida tem a mesma referência, forma e valor).
                && ! in_array(strtoupper((string) ($row['status'] ?? '')), ['OVERDUE', 'DUNNING_REQUESTED', 'REFUNDED', 'REFUND_REQUESTED', 'REFUND_IN_PROGRESS', 'CHARGEBACK_REQUESTED', 'CHARGEBACK_DISPUTE', 'CANCELLED', 'DELETED'], true))
            ->sortByDesc(fn (array $row) => (string) ($row['dateCreated'] ?? ''))
            ->first();
    }

    /** @param array<string, mixed> $row */
    private function reusedChargeResult(array $row): CreateChargeResultDTO
    {
        Log::info('Asaas: cobrança já criada para a fatura (resposta perdida antes) reaproveitada — nenhuma nova emitida.', [
            'external_charge_id' => $row['id'] ?? null,
            'invoice_id'         => $row['externalReference'] ?? null,
        ]);

        return new CreateChargeResultDTO(
            success: true,
            externalPaymentId: (string) $row['id'],
            status: $this->normalizeAsaasPaymentStatus((string) ($row['status'] ?? '')),
            amount: isset($row['value']) ? (float) $row['value'] : null,
            rawResponse: [...$this->sanitizePayload($row), 'easyeye_reused' => true],
            paymentUrl: $this->extractPaymentUrl($row, ['invoiceUrl', 'bankSlipUrl']),
        );
    }

    /**
     * DELETE /v3/payments/{id} (https://docs.asaas.com/reference/excluir-cobranca):
     * remove a cobrança em aberto ({"deleted": true}); chega depois o
     * PAYMENT_DELETED. Cobrança já paga não é removida (o Asaas recusa). Já
     * removida antes (ex.: junto com a recorrência) conta como cancelada.
     */
    public function cancelCharge(string $externalPaymentId): bool
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $externalPaymentId) !== 1) {
            return false;
        }

        try {
            $response = $this->request('DELETE', 'payments', [], ['id' => $externalPaymentId]);

            if ($response->successful() && $response->json('deleted') === true) {
                return true;
            }

            if ($response->status() !== 404) {
                return false;
            }

            // 404: só conta como cancelada se a consulta confirmar a remoção.
            $show = $this->get('payments', [], ['id' => $externalPaymentId]);

            return $show->successful() && $show->json('deleted') === true;
        } catch (GatewayIntegrationException) {
            return false;
        }
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
     * Pix e boleto na tela do EasyEye. Cartão segue pelo Asaas Checkout
     * (página hospedada — createHostedCheckout): a API só cobra cartão
     * recebendo os dados no servidor (POST /v3/payments com creditCard ou
     * /v3/creditCard/tokenizeCreditCard) — não há tokenização no navegador
     * (https://docs.asaas.com/docs/pci-dss-1).
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
     * PDF em bankSlipUrl.
     *
     * Só 400/404 querem dizer "a cobrança não tem essa forma" (null). 401/403
     * (credencial — o time é avisado), 429 e 5xx são erro do gateway: lança,
     * e a tela diz "não foi possível gerar agora, tente de novo" — nunca
     * "forma indisponível" (https://docs.asaas.com/reference/codigos-http-das-respostas).
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

        if (in_array($response->status(), [400, 404], true)) {
            return null;
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw GatewayIntegrationException::authFailed($this->code(), $response->status(), $this->errorMessage($response));
        }

        if ($response->status() === 429) {
            throw GatewayIntegrationException::rateLimited($this->code(), $this->retryAfterSeconds($response));
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

    // ── Asaas Checkout (cartão na página hospedada) ──────────────────────────

    /** Desligável por ASAAS_HOSTED_CHECKOUT=false (volta ao link da fatura). */
    public function supportsHostedCardCheckout(): bool
    {
        $enabled = $this->gatewayConfig('hosted_checkout.enabled');

        return $enabled === null || filter_var($enabled, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) !== false;
    }

    /**
     * POST /v3/checkouts (https://docs.asaas.com/reference/criar-novo-checkout):
     * billingTypes [CREDIT_CARD]; chargeTypes RECURRENT (com subscription
     * {cycle, nextDueDate} — https://docs.asaas.com/docs/checkout-com-assinatura-recorrente)
     * ou DETACHED (https://docs.asaas.com/docs/checkout-para-cartão-de-crédito);
     * callback {successUrl, cancelUrl, expiredUrl}; minutesToExpire entre 10
     * e 1440; items (name até 30, description até 150); customer = o cliente
     * já cadastrado (https://docs.asaas.com/docs/como-informar-os-dados-do-cliente);
     * externalReference até 200 caracteres.
     *
     * O link é o devolvido em "link"; sem ele, o formato documentado
     * https://asaas.com/checkoutSession/show?id={id} (sandbox:
     * https://sandbox.asaas.com/…). A confirmação é sempre pelo webhook —
     * o successUrl só traz o pagador de volta.
     */
    public function createHostedCheckout(HostedCheckoutDTO $payload): HostedCheckoutResultDTO
    {
        $minutes = max(10, min(1440, (int) ($payload->minutesToExpire ?? $this->gatewayConfig('hosted_checkout.minutes_to_expire') ?? 60)));

        $item = array_filter([
            'name'        => mb_substr($payload->itemName, 0, 30),
            'description' => mb_substr($payload->description, 0, 150),
            'quantity'    => 1,
            'value'       => round($payload->amount, 2),
            'imageBase64' => $this->checkoutItemImage(),
        ], fn ($value) => $value !== null && $value !== '');

        $body = array_filter([
            'billingTypes'      => ['CREDIT_CARD'],
            'chargeTypes'       => [$payload->isRecurrent() ? 'RECURRENT' : 'DETACHED'],
            'minutesToExpire'   => $minutes,
            'externalReference' => mb_substr($payload->externalReference, 0, 200),
            'callback'          => [
                'successUrl' => $payload->successUrl,
                'cancelUrl'  => $payload->cancelUrl,
                'expiredUrl' => $payload->expiredUrl,
            ],
            'items'        => [$item],
            'customer'     => $payload->customerId,
            'subscription' => $payload->isRecurrent() ? [
                'cycle'       => $this->asaasCycle((string) $payload->cycle),
                'nextDueDate' => $payload->nextDueDate ?? CarbonImmutable::today()->toDateString(),
            ] : null,
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);

        $response = $this->post('checkouts', $body);

        if ($response->status() === 429) {
            throw GatewayIntegrationException::rateLimited($this->code(), $this->retryAfterSeconds($response));
        }

        $json = is_array($response->json()) ? $response->json() : [];

        if (! $response->successful() || blank($json['id'] ?? null)) {
            return new HostedCheckoutResultDTO(
                success: false,
                externalCheckoutId: null,
                url: null,
                rawResponse: $json,
                errorMessage: $this->errorMessage($response),
                httpStatus: $response->status(),
            );
        }

        $id = (string) $json['id'];

        return new HostedCheckoutResultDTO(
            success: true,
            externalCheckoutId: $id,
            url: $this->checkoutUrl($id, $json['link'] ?? null),
            status: isset($json['status']) ? strtoupper((string) $json['status']) : 'ACTIVE',
            minutesToExpire: isset($json['minutesToExpire']) && is_numeric($json['minutesToExpire']) ? (int) $json['minutesToExpire'] : $minutes,
            rawResponse: $json,
        );
    }

    /** POST /v3/checkouts/{id}/cancel (https://docs.asaas.com/reference/cancelar-um-checkout). */
    public function cancelHostedCheckout(string $externalCheckoutId): bool
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $externalCheckoutId) !== 1) {
            return false;
        }

        try {
            $response = $this->post('checkout_cancel', [], ['id' => $externalCheckoutId]);
        } catch (GatewayIntegrationException) {
            return false;
        }

        return $response->successful() && in_array(strtoupper((string) $response->json('status')), ['CANCELED', 'CANCELLED', 'EXPIRED', 'PAID', ''], true);
    }

    /**
     * Link do checkout: o "link" da resposta (só https num domínio do Asaas);
     * senão o formato documentado (https://docs.asaas.com/docs/introdução-1).
     */
    private function checkoutUrl(string $id, mixed $link): string
    {
        $safe = PaymentUrl::safe($link);

        if ($safe !== null && preg_match('#^https://([a-z0-9-]+\.)*asaas\.com/#i', $safe) === 1) {
            return $safe;
        }

        $host = str_contains((string) $this->gatewayConfig('base_url'), 'sandbox') ? 'https://sandbox.asaas.com' : 'https://asaas.com';

        return $host . '/checkoutSession/show?id=' . rawurlencode($id);
    }

    /**
     * Imagem do item (imageBase64): a referência marca o campo como
     * obrigatório, mas os exemplos da própria doc criam o checkout sem ele.
     * Só vai quando ASAAS_CHECKOUT_ITEM_IMAGE aponta um PNG/JPG legível.
     */
    private function checkoutItemImage(): ?string
    {
        $path = (string) $this->gatewayConfig('hosted_checkout.item_image');

        if ($path === '') {
            return null;
        }

        $full = str_starts_with($path, '/') ? $path : base_path($path);

        return is_file($full) && is_readable($full) && filesize($full) <= 1_000_000
            ? base64_encode((string) file_get_contents($full))
            : null;
    }

    // ── Estorno ──────────────────────────────────────────────────────────────

    public function supportsRefund(): bool
    {
        return true;
    }

    /** Cartão e Pix aceitam parcial (Pix: vários parciais até o total); boleto só total. */
    public function supportsPartialRefund(): bool
    {
        return true;
    }

    /**
     * Cartão/Pix: POST /v3/payments/{id}/refund {value?, description}
     * (https://docs.asaas.com/reference/estornar-cobranca) — sem value, total.
     * Boleto: POST /v3/payments/{id}/bankSlip/refund (só total), que devolve
     * requestUrl — o pagador informa a conta para receber
     * (https://docs.asaas.com/reference/estornar-boleto). Concluído só com o
     * item de refunds[] em DONE ou a cobrança REFUNDED
     * (https://docs.asaas.com/docs/estornos); senão "requested", e o webhook
     * (PAYMENT_REFUNDED / PAYMENT_PARTIALLY_REFUNDED) confirma depois.
     */
    public function refund(RefundRequestDTO $payload): RefundResultDTO
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $payload->externalPaymentId) !== 1) {
            return RefundResultDTO::failed('Id de cobrança inválido.');
        }

        $show = $this->get('payments', [], ['id' => $payload->externalPaymentId]);

        if (! $show->successful()) {
            return RefundResultDTO::failed($this->errorMessage($show), $show->status());
        }

        $payment     = (array) ($show->json() ?? []);
        $billingType = strtoupper((string) ($payment['billingType'] ?? ''));
        $before      = $this->refundedTotal($payment) ?? 0.0;

        if ($billingType === 'BOLETO') {
            if ($payload->isPartial() && abs((float) $payload->amount - (float) ($payment['value'] ?? 0)) >= 0.005) {
                return RefundResultDTO::failed(__('manager_subscriptions.refund.errors.boleto_partial'));
            }

            try {
                $response = $this->post('payment_bankslip_refund', [], ['id' => $payload->externalPaymentId]);
            } catch (GatewayIntegrationException $e) {
                if ($e->isRateLimit()) {
                    throw $e;
                }

                return RefundResultDTO::inconclusive($e->getMessage(), $e->httpStatus());
            }

            if ($response->status() === 429) {
                throw GatewayIntegrationException::rateLimited($this->code(), $this->retryAfterSeconds($response));
            }

            if ($response->serverError()) {
                return RefundResultDTO::inconclusive($this->errorMessage($response), $response->status());
            }

            if (! $response->successful()) {
                return RefundResultDTO::failed($this->errorMessage($response), $response->status(), $this->sanitizePayload((array) ($response->json() ?? [])));
            }

            return new RefundResultDTO(
                success: true,
                status: RefundResultDTO::STATUS_REQUESTED,
                amount: (float) ($payment['value'] ?? 0),
                requestUrl: PaymentUrl::safe($response->json('requestUrl')),
                rawResponse: $this->sanitizePayload((array) ($response->json() ?? [])),
            );
        }

        // Sem resposta definitiva (timeout, conexão, 5xx): o Asaas não tem
        // chave de idempotência — o estorno pode ter sido feito. Inconclusivo:
        // conferido em refunds[] (refundStatus) antes de qualquer outro pedido.
        try {
            $response = $this->post('payment_refund', array_filter([
                'value'       => $payload->isPartial() ? round((float) $payload->amount, 2) : null,
                'description' => mb_substr($payload->description, 0, 500),
            ], fn ($value) => $value !== null && $value !== ''), ['id' => $payload->externalPaymentId]);
        } catch (GatewayIntegrationException $e) {
            if ($e->isRateLimit()) {
                throw $e;
            }

            return RefundResultDTO::inconclusive($e->getMessage(), $e->httpStatus());
        }

        if ($response->status() === 429) {
            throw GatewayIntegrationException::rateLimited($this->code(), $this->retryAfterSeconds($response));
        }

        if ($response->serverError()) {
            return RefundResultDTO::inconclusive($this->errorMessage($response), $response->status());
        }

        if (! $response->successful()) {
            return RefundResultDTO::failed($this->errorMessage($response), $response->status(), $this->sanitizePayload((array) ($response->json() ?? [])));
        }

        $json  = (array) ($response->json() ?? []);
        $after = $this->refundedTotal($json);
        $value = $payload->isPartial() ? round((float) $payload->amount, 2) : (float) ($json['value'] ?? $payment['value'] ?? 0);
        $done  = strtoupper((string) ($json['status'] ?? '')) === 'REFUNDED'
            || ($after !== null && $after - $before >= $value - 0.005);

        return new RefundResultDTO(
            success: true,
            status: $done ? RefundResultDTO::STATUS_DONE : RefundResultDTO::STATUS_REQUESTED,
            amount: $value,
            rawResponse: $this->sanitizePayload($json),
        );
    }

    /**
     * Conferência do pedido de estorno: GET /v3/payments/{id} e o array
     * refunds[] (https://docs.asaas.com/docs/estornos — o estorno só vale com
     * status DONE; PENDING em processamento; CANCELLED não devolveu). O
     * Asaas não devolve id do estorno: o pedido é o item com o mesmo valor
     * criado a partir do pedido (dateCreated, horário de Brasília). Cobrança
     * REFUNDED = devolvida inteira. Boleto em REFUND_REQUESTED = aguardando a
     * conta do pagador (https://docs.asaas.com/reference/estornar-boleto).
     */
    public function refundStatus(string $externalPaymentId, float $amount, ?string $externalRefundId, string $since): ?RefundResultDTO
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $externalPaymentId) !== 1) {
            return null;
        }

        $response = $this->get('payments', [], ['id' => $externalPaymentId]);

        if ($response->status() === 404) {
            return RefundResultDTO::checked(RefundResultDTO::STATUS_NOT_FOUND);
        }

        if ($response->status() === 429) {
            throw GatewayIntegrationException::rateLimited($this->code(), $this->retryAfterSeconds($response));
        }

        if (! $response->successful()) {
            throw GatewayIntegrationException::fromHttpStatus($this->code(), $response->status(), $this->errorMessage($response));
        }

        $payment = (array) ($response->json() ?? []);
        $status  = strtoupper((string) ($payment['status'] ?? ''));
        // Tolerância de relógio entre o EasyEye e o Asaas.
        $from    = CarbonImmutable::parse($since)->subMinutes(10);
        $matches = collect(is_array($payment['refunds'] ?? null) ? $payment['refunds'] : [])
            ->filter(fn ($refund) => is_array($refund)
                && abs((float) ($refund['value'] ?? 0) - $amount) < 0.005
                && ($created = $this->asaasDate($refund['dateCreated'] ?? null)) !== null
                && $created->greaterThanOrEqualTo($from))
            ->map(fn (array $refund) => strtoupper((string) ($refund['status'] ?? '')));

        return match (true) {
            $matches->contains('DONE')       => RefundResultDTO::checked(RefundResultDTO::STATUS_DONE, amount: $amount),
            $matches->contains('PENDING')    => RefundResultDTO::checked(RefundResultDTO::STATUS_REQUESTED, 'in_progress', $amount),
            $status === 'REFUND_REQUESTED'   => RefundResultDTO::checked(RefundResultDTO::STATUS_REQUESTED, 'awaiting_payer_account', $amount),
            $status === 'REFUND_IN_PROGRESS' => RefundResultDTO::checked(RefundResultDTO::STATUS_REQUESTED, 'in_progress', $amount),
            $status === 'REFUNDED'           => RefundResultDTO::checked(RefundResultDTO::STATUS_DONE, amount: $amount),
            $matches->isNotEmpty()           => RefundResultDTO::checked(RefundResultDTO::STATUS_FAILED, 'cancelled', $amount),
            default                          => RefundResultDTO::checked(RefundResultDTO::STATUS_NOT_FOUND),
        };
    }

    /** Data do Asaas ("2022-02-21 10:28:40", horário de Brasília). */
    private function asaasDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, 'America/Sao_Paulo');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Soma dos estornos concluídos (refunds[].status DONE) da cobrança; null
     * quando a resposta não traz refunds (não dá para saber).
     */
    public function refundedTotal(array $payment): ?float
    {
        if (! array_key_exists('refunds', $payment) || ! is_array($payment['refunds'])) {
            return null;
        }

        return round((float) collect($payment['refunds'])
            ->filter(fn ($refund) => is_array($refund) && strtoupper((string) ($refund['status'] ?? '')) === 'DONE')
            ->sum(fn (array $refund) => (float) ($refund['value'] ?? 0)), 2);
    }

    // ── Conferência e saúde ──────────────────────────────────────────────────

    /**
     * GET /v3/payments/{id} (https://docs.asaas.com/reference/recuperar-uma-unica-cobranca)
     * como o evento do webhook equivalente ao status atual: paga →
     * PAYMENT_RECEIVED, vencida → PAYMENT_OVERDUE, estornada →
     * PAYMENT_REFUNDED, removida → PAYMENT_DELETED. Outros status (pendente,
     * em análise): null — nada a aplicar.
     */
    public function paymentStatusEvent(string $externalPaymentId): ?NormalizedWebhookEventDTO
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $externalPaymentId) !== 1) {
            return null;
        }

        $response = $this->get('payments', [], ['id' => $externalPaymentId]);

        if ($response->status() === 404) {
            return null;
        }

        if ($response->status() === 429) {
            throw GatewayIntegrationException::rateLimited($this->code(), $this->retryAfterSeconds($response));
        }

        if (! $response->successful()) {
            throw GatewayIntegrationException::fromHttpStatus($this->code(), $response->status(), $this->errorMessage($response));
        }

        $payment = (array) ($response->json() ?? []);
        $status  = strtoupper((string) ($payment['status'] ?? ''));

        $event = match (true) {
            (bool) ($payment['deleted'] ?? false)        => 'PAYMENT_DELETED',
            in_array($status, self::PAID_STATUSES, true) => 'PAYMENT_RECEIVED',
            $status === 'OVERDUE'                        => 'PAYMENT_OVERDUE',
            $status === 'REFUNDED'                       => 'PAYMENT_REFUNDED',
            default                                      => null,
        };

        if ($event === null) {
            return null;
        }

        $body = ['event' => $event, 'payment' => $payment];

        return $this->parseWebhook(new GatewayWebhookInputDTO(
            gatewayCode: $this->code(),
            headers: [],
            body: (string) json_encode($body),
            payload: $body,
            externalEventId: null,
        ));
    }

    /**
     * Health check real: GET /v3/myAccount/status/ (chamada leve e
     * autenticada — https://docs.asaas.com/reference/consultar-situacao-cadastral-da-conta).
     * Também mantém a chave em uso (sem uso por 3 meses ela é desabilitada e
     * por 6, expirada — https://docs.asaas.com/docs/chaves-de-api). Confere
     * o prefixo da chave ($aact_prod_ em produção, $aact_hmlg_ no sandbox —
     * https://docs.asaas.com/docs/autenticação-1) contra a base configurada.
     */
    public function healthCheck(): GatewayHealthDTO
    {
        $baseUrl = (string) $this->gatewayConfig('base_url');
        $secret  = trim((string) $this->resolveSecret());

        if ($baseUrl === '' || $secret === '') {
            return new GatewayHealthDTO(
                healthy: false,
                message: 'Asaas sem configuração mínima (ASAAS_BASE_URL ou chave ausente).',
                status: GatewayHealthDTO::STATUS_NOT_CONFIGURED,
            );
        }

        $sandbox = str_contains($baseUrl, 'sandbox');
        $details = ['environment' => $sandbox ? 'sandbox' : 'production'];
        $prefix  = match (true) {
            str_starts_with($secret, '$aact_prod_') => 'production',
            str_starts_with($secret, '$aact_hmlg_') => 'sandbox',
            default                                 => null,
        };
        $details['key_environment'] = $prefix;

        if ($prefix !== null && $prefix !== $details['environment']) {
            return new GatewayHealthDTO(
                healthy: false,
                message: "Chave do Asaas de {$prefix} configurada com a base de {$details['environment']} ({$baseUrl}).",
                status: GatewayHealthDTO::STATUS_ENVIRONMENT_MISMATCH,
                details: $details,
            );
        }

        $started = microtime(true);

        try {
            $response = $this->get('account_status');
        } catch (GatewayIntegrationException $e) {
            return new GatewayHealthDTO(
                healthy: false,
                message: $e->getMessage(),
                status: $e->isRateLimit() ? GatewayHealthDTO::STATUS_RATE_LIMITED : GatewayHealthDTO::STATUS_UNREACHABLE,
                details: $details,
            );
        }

        $latency = (int) round((microtime(true) - $started) * 1000);
        $codes   = collect((array) $response->json('errors', []))->pluck('code')->filter()->values()->all();

        if (in_array($response->status(), [401, 403], true)) {
            $environment = in_array('invalid_environment', $codes, true);

            return new GatewayHealthDTO(
                healthy: false,
                httpStatus: $response->status(),
                message: $this->errorMessage($response),
                latencyMs: $latency,
                status: $environment ? GatewayHealthDTO::STATUS_ENVIRONMENT_MISMATCH : GatewayHealthDTO::STATUS_AUTH_ERROR,
                details: [...$details, 'error_codes' => $codes],
            );
        }

        if (! $response->successful()) {
            return new GatewayHealthDTO(
                healthy: false,
                httpStatus: $response->status(),
                message: $this->errorMessage($response),
                latencyMs: $latency,
                status: $response->status() === 429 ? GatewayHealthDTO::STATUS_RATE_LIMITED : GatewayHealthDTO::STATUS_UNREACHABLE,
                details: $details,
            );
        }

        return new GatewayHealthDTO(
            healthy: true,
            httpStatus: $response->status(),
            message: 'Asaas respondeu com a chave configurada.',
            latencyMs: $latency,
            status: GatewayHealthDTO::STATUS_OK,
            details: [...$details, 'account_status' => $response->json('general')],
        );
    }

    // ── Webhooks ─────────────────────────────────────────────────────────────

    /**
     * O Asaas manda o token cadastrado no webhook (painel ou API, 32 a 255
     * caracteres) no header "asaas-access-token"
     * (https://docs.asaas.com/docs/receba-eventos-do-asaas-no-seu-endpoint-de-webhook).
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

        if (is_array($provided)) {
            $provided = $provided[0] ?? null;
        }

        if (! is_string($provided) || $provided === '') {
            return false;
        }

        return hash_equals(trim($secret), trim($provided));
    }

    /**
     * Normaliza a notificação. Além do tipo (eventTypeMap), o metadata leva:
     *  - subscription_id / invoice_id: a nossa referência (externalReference
     *    da cobrança, ou a do checkout — HostedCheckoutReference);
     *  - checkout_session: o checkout que originou a cobrança/assinatura
     *    (campo checkoutSession — https://docs.asaas.com/reference/recuperar-uma-unica-cobranca)
     *    ou o próprio checkout nos eventos CHECKOUT_*;
     *  - card: bandeira e 4 últimos da cobrança no cartão (creditCard);
     *  - refunded_total: soma dos estornos concluídos (refunds[] DONE);
     *  - invalidate_instructions: Pix/linha digitável guardados deixam de valer
     *    (PAYMENT_UPDATED muda valor/vencimento; PAYMENT_BANK_SLIP_CANCELLED
     *    cancela o registro do boleto vencido);
     *  - recurrence: valor, ciclo e próximo vencimento (SUBSCRIPTION_*);
     *  - access_token: dados da chave (ACCESS_TOKEN_* —
     *    https://docs.asaas.com/docs/eventos-para-chaves-de-api).
     * occurredAt = dateCreated do evento (horário de Brasília), quando vier.
     */
    public function parseWebhook(GatewayWebhookInputDTO $payload): NormalizedWebhookEventDTO
    {
        $body         = $payload->payload;
        $event        = (string) ($body['event'] ?? 'unknown');
        $payment      = is_array($body['payment'] ?? null) ? $body['payment'] : [];
        $subscription = is_array($body['subscription'] ?? null) ? $body['subscription'] : [];
        $checkout     = is_array($body['checkout'] ?? null) ? $body['checkout'] : [];
        $accessToken  = is_array($body['accessToken'] ?? null) ? $body['accessToken'] : [];

        $normalizedType = $this->normalizeEventType($event);
        // Eventos de cobrança trazem payment.subscription; os da assinatura
        // (SUBSCRIPTION_*), o objeto subscription.
        $externalSubscriptionId = $payment['subscription'] ?? ($subscription['id'] ?? null);
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

        if (is_string($externalRef) && ($parsed = HostedCheckoutReference::parse($externalRef)) !== null) {
            // Cobrança de uma recorrência (payment.subscription) com a
            // referência do checkout: as cobranças seguintes podem herdá-la —
            // a fatura dela é a do período (pelo vencimento), nunca a que o
            // checkout pagou (a 1ª cobrança se liga pelo checkoutSession).
            if (filled($payment['subscription'] ?? null)) {
                unset($parsed['invoice_id']);
            }

            $metadata = [...$metadata, ...$parsed];
        } elseif (is_string($externalRef) && $externalRef !== '') {
            $metadata[filled($payment['subscription'] ?? null) ? 'subscription_id' : 'invoice_id'] = $externalRef;
        }

        // Cobrança apagada que voltou: o processamento reabre pagamento e fatura.
        if ($event === 'PAYMENT_RESTORED') {
            $metadata['restored'] = true;
        }

        $checkoutSession = $payment['checkoutSession'] ?? ($subscription['checkoutSession'] ?? ($checkout['id'] ?? null));

        if (is_string($checkoutSession) && $checkoutSession !== '') {
            $metadata['checkout_session'] = $checkoutSession;
        }

        if ($checkout !== []) {
            $metadata['checkout'] = array_filter([
                'status'             => isset($checkout['status']) ? strtoupper((string) $checkout['status']) : null,
                'external_reference' => $checkout['externalReference'] ?? null,
                'customer'           => is_string($checkout['customer'] ?? null) ? $checkout['customer'] : null,
            ], fn ($value) => $value !== null && $value !== '');

            if (is_string($checkout['externalReference'] ?? null) && ($parsed = HostedCheckoutReference::parse($checkout['externalReference'])) !== null) {
                $metadata = [...$metadata, ...$parsed];
            }
        }

        $card = is_array($payment['creditCard'] ?? null) ? $payment['creditCard'] : [];

        if (filled($card['creditCardNumber'] ?? null) || filled($card['creditCardBrand'] ?? null)) {
            $metadata['card'] = array_filter([
                'brand' => isset($card['creditCardBrand']) ? strtolower((string) $card['creditCardBrand']) : null,
                'last4' => isset($card['creditCardNumber']) ? substr((string) preg_replace('/\D/', '', (string) $card['creditCardNumber']), -4) : null,
            ], fn ($value) => $value !== null && $value !== '');
        }

        if (filled($payment['billingType'] ?? null)) {
            $metadata['billing_type'] = strtoupper((string) $payment['billingType']);
        }

        if (($refunded = $this->refundedTotal($payment)) !== null) {
            $metadata['refunded_total'] = $refunded;
        }

        if (in_array($event, ['PAYMENT_UPDATED', 'PAYMENT_BANK_SLIP_CANCELLED'], true)) {
            $metadata['invalidate_instructions'] = true;
        }

        if ($subscription !== [] && str_starts_with($event, 'SUBSCRIPTION_')) {
            $metadata['recurrence'] = array_filter([
                'value'         => isset($subscription['value']) && is_numeric($subscription['value']) ? (float) $subscription['value'] : null,
                'cycle'         => $subscription['cycle'] ?? null,
                'next_due_date' => $subscription['nextDueDate'] ?? null,
                'status'        => $subscription['status'] ?? null,
                'billing_type'  => $subscription['billingType'] ?? null,
                'deleted'       => isset($subscription['deleted']) ? (bool) $subscription['deleted'] : null,
            ], fn ($value) => $value !== null);
        }

        // O objeto accessToken é guardado sem os dados (PayloadSanitizer trata
        // "accessToken" como segredo): no reprocessamento só o nome do evento.
        if (str_starts_with($event, 'ACCESS_TOKEN_')) {
            $metadata['access_token'] = array_filter([
                'event'           => $event,
                'id'              => $accessToken['id'] ?? null,
                'name'            => $accessToken['name'] ?? null,
                'enabled'         => isset($accessToken['enabled']) ? (bool) $accessToken['enabled'] : null,
                'disable_reason'  => $accessToken['disableReason'] ?? null,
                'expiration_date' => $accessToken['expirationDate'] ?? null,
                'expires_by_lack' => $accessToken['projectedExpirationDateByLackOfUse'] ?? null,
            ], fn ($value) => $value !== null);
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
            occurredAt: $this->occurredAt($body['dateCreated'] ?? null),
            dueDate: $this->extractString($payment, ['dueDate']),
            // invoiceUrl: página da fatura (boleto, Pix ou cartão); bankSlipUrl: PDF do boleto.
            paymentUrl: $this->extractPaymentUrl($payment, ['invoiceUrl', 'bankSlipUrl']),
        );
    }

    /** dateCreated do evento ("2024-06-12 16:45:03", horário de Brasília); sem ele, agora. */
    private function occurredAt(mixed $dateCreated): string
    {
        if (is_string($dateCreated) && trim($dateCreated) !== '') {
            try {
                return CarbonImmutable::parse($dateCreated, 'America/Sao_Paulo')->setTimezone(config('app.timezone'))->toIso8601String();
            } catch (Throwable) {
                // formato inesperado: cai no horário do recebimento
            }
        }

        return now()->toIso8601String();
    }

    // ── Mapa de eventos ───────────────────────────────────────────────────────

    /**
     * Eventos de cobrança: https://docs.asaas.com/docs/webhook-para-cobrancas;
     * de assinatura: https://docs.asaas.com/docs/eventos-para-assinaturas; de
     * checkout: https://docs.asaas.com/docs/eventos-para-checkout; de chave
     * de API: https://docs.asaas.com/docs/eventos-para-chaves-de-api. Todos os
     * documentados estão aqui — 'unknown' é explícito (só registro).
     *
     *  - PAYMENT_CONFIRMED (pago, saldo ainda não disponível) e
     *    PAYMENT_RECEIVED (saldo disponível) chegam os dois para a mesma
     *    cobrança: o 2º é duplicado (idempotência por Payment).
     *  - PAYMENT_AUTHORIZED: cartão autorizado aguardando captura — só existe
     *    em pré-autorização, que não usamos; não é pagamento.
     *  - PAYMENT_DELETED apaga só a cobrança (a assinatura segue cobrando);
     *    PAYMENT_RESTORED a traz de volta (volta a ser uma cobrança emitida).
     *  - PAYMENT_PARTIALLY_REFUNDED: estorno de parte do valor — registra o
     *    devolvido; a fatura segue paga e o acesso não muda.
     *  - PAYMENT_REFUND_IN_PROGRESS / _DENIED: o estorno só vale no REFUNDED;
     *    em andamento mantém o pedido do manager "solicitado", negado (só
     *    boleto) o marca recusado e libera um novo pedido (RefundService).
     *  - PAYMENT_BANK_SLIP_CANCELLED: registro do boleto vencido cancelado —
     *    a linha digitável guardada deixa de valer (a cobrança segue).
     *  - PAYMENT_AWAITING_CHARGEBACK_REVERSAL: disputa ganha pelo lojista.
     *  - PAYMENT_RECEIVED_IN_CASH_UNDONE: desfeita a baixa manual — o
     *    pagamento registrado deixa de valer.
     *  - SUBSCRIPTION_CREATED: liga a assinatura criada pelo Asaas Checkout;
     *    SUBSCRIPTION_UPDATED: confere valor/ciclo/vencimento alterados no painel.
     *  - CHECKOUT_*: situação do checkout hospedado (a confirmação financeira
     *    é a da cobrança).
     *  - ACCESS_TOKEN_EXPIRING_SOON / DISABLED / EXPIRED / DELETED: alerta ao time.
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
            'PAYMENT_PARTIALLY_REFUNDED'                   => 'partially_refunded',
            'PAYMENT_REFUND_IN_PROGRESS'                   => 'refund_in_progress',
            'PAYMENT_REFUND_DENIED'                        => 'refund_denied',
            'PAYMENT_RECEIVED_IN_CASH_UNDONE'              => 'refunded',
            'PAYMENT_CHARGEBACK_REQUESTED'                 => 'chargeback',
            'PAYMENT_CHARGEBACK_DISPUTE'                   => 'chargeback',
            'PAYMENT_AWAITING_CHARGEBACK_REVERSAL'         => 'unknown',
            'PAYMENT_DUNNING_REQUESTED'                    => 'unknown',
            'PAYMENT_BANK_SLIP_CANCELLED'                  => 'instructions_invalidated',
            'PAYMENT_BANK_SLIP_VIEWED'                     => 'unknown',
            'PAYMENT_CHECKOUT_VIEWED'                      => 'unknown',
            'PAYMENT_SPLIT_CANCELLED'                      => 'unknown',
            'PAYMENT_SPLIT_DIVERGENCE_BLOCK'               => 'unknown',
            'PAYMENT_SPLIT_DIVERGENCE_BLOCK_FINISHED'      => 'unknown',
            'SUBSCRIPTION_CREATED'                         => 'subscription_created',
            'SUBSCRIPTION_UPDATED'                         => 'subscription_updated',
            'SUBSCRIPTION_INACTIVATED'                     => 'cancelled',
            'SUBSCRIPTION_DELETED'                         => 'cancelled',
            'SUBSCRIPTION_SPLIT_DISABLED'                  => 'unknown',
            'SUBSCRIPTION_SPLIT_DIVERGENCE_BLOCK'          => 'unknown',
            'SUBSCRIPTION_SPLIT_DIVERGENCE_BLOCK_FINISHED' => 'unknown',
            'CHECKOUT_CREATED'                             => 'checkout_created',
            'CHECKOUT_PAID'                                => 'checkout_paid',
            'CHECKOUT_CANCELED'                            => 'checkout_canceled',
            'CHECKOUT_EXPIRED'                             => 'checkout_expired',
            'ACCESS_TOKEN_CREATED'                         => 'unknown',
            'ACCESS_TOKEN_ENABLED'                         => 'unknown',
            'ACCESS_TOKEN_DISABLED'                        => 'access_token_alert',
            'ACCESS_TOKEN_DELETED'                         => 'access_token_alert',
            'ACCESS_TOKEN_EXPIRING_SOON'                   => 'access_token_alert',
            'ACCESS_TOKEN_EXPIRED'                         => 'access_token_alert',
        ];
    }

    // ── Helpers privados ─────────────────────────────────────────────────────

    /**
     * Status da cobrança (enum "status" de https://docs.asaas.com/reference/recuperar-uma-unica-cobranca).
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
     * (https://docs.asaas.com/reference/codigos-http-das-respostas). Sem esse formato, o corpo.
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

    /**
     * Intervalo do produto → cycle do Asaas (enum: WEEKLY, BIWEEKLY, MONTHLY,
     * BIMONTHLY, QUARTERLY, SEMIANNUALLY, YEARLY —
     * https://docs.asaas.com/reference/criar-nova-assinatura). Ciclo que não
     * existe lá é erro — nunca vira mensal em silêncio (cobraria o valor do
     * período todo a cada mês).
     */
    private function resolveBillingCycleFromInterval(string $interval, int $count): string
    {
        $cycle = match (true) {
            $interval === 'year' && $count === 1   => 'YEARLY',
            $interval === 'month' && $count === 1  => 'MONTHLY',
            $interval === 'month' && $count === 2  => 'BIMONTHLY',
            $interval === 'month' && $count === 3  => 'QUARTERLY',
            $interval === 'month' && $count === 6  => 'SEMIANNUALLY',
            $interval === 'month' && $count === 12 => 'YEARLY',
            default                                => null,
        };

        if ($cycle === null) {
            throw new GatewayIntegrationException(
                "[{$this->code()}] Ciclo sem equivalente no Asaas: {$count} {$interval}.",
                'invalid_request',
            );
        }

        return $cycle;
    }

    /** Ciclo do produto (BillingCycle::value) → cycle do Asaas para o checkout recorrente. */
    private function asaasCycle(string $cycle): string
    {
        $enum = BillingCycle::tryFrom($cycle);

        if ($enum === null || $enum->months() <= 0) {
            throw new GatewayIntegrationException("[{$this->code()}] Ciclo sem equivalente no Asaas: {$cycle}.", 'invalid_request');
        }

        return $this->resolveBillingCycleFromInterval($enum->intervalName(), $enum->intervalCount());
    }
}
