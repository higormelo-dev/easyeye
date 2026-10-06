<?php

namespace App\Jobs\Billing;

use App\Contracts\Billing\PaymentGatewayInterface;
use App\DTOs\Billing\{CardChargeDTO, CreateChargeDTO, CreateChargeResultDTO, GatewayCallContext};
use App\Enums\Billing\{BillingEventType, InvoiceStatus, PaymentAttemptStatus, PaymentStatus};
use App\Enums\{SubscriptionBillingMode, SubscriptionStatus};
use App\Exceptions\Billing\GatewayIntegrationException;
use App\Models\Billing\{Gateway, Invoice, Payment, PaymentAttempt};
use App\Models\{Entity, Subscription};
use App\Services\Billing\{BillingLogService, CircuitBreakerService, FinancialEventService, GatewayRegistry, SubscriptionCycleService};
use App\Support\Billing\{BillingCustomer, ChargeIdempotency};
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Illuminate\Support\Facades\{DB, Log};
use Illuminate\Support\Str;
use Throwable;

/**
 * Renovação local: emite no gateway a cobrança do próximo ciclo de uma
 * assinatura cujo gateway não tem recorrência própria (gateway_subscription_id
 * nulo — InfinitePay, Mercado Pago e os gerenciados localmente). Gateways com
 * recorrência nativa (Asaas) cobram sozinhos e só reagimos ao webhook.
 *
 * Cobra o valor contratado (recurringAmount — grandfathering) pelo período
 * [next_billing_at, next_billing_at + ciclo contratado), com vencimento em
 * next_billing_at. Idempotente por assinatura + período: a fatura do período
 * é uma só e, emitida a cobrança, o agendador não a seleciona mais. Falha na
 * emissão deixa a fatura como falha e o agendador tenta de novo no dia
 * seguinte. Pago na hora, estende como o webhook.
 *
 * Cartão salvo (checkout transparente — payment_method credit_card e
 * gateway_card_id): a renovação cobra o cartão no gateway sem interação, no
 * dia do vencimento (não na antecedência do boleto/Pix). Recusa = falha da
 * emissão: fatura em falha, régua de cobrança e nova tentativa no dia
 * seguinte; o cliente pode pagar por Pix/boleto pelo checkout.
 *
 * Fases: DB (fatura) → HTTP (gateway) → DB (confirmação).
 */
class RenewSubscriptionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1; // nova tentativa: agendador do dia seguinte

    public function __construct(
        public readonly string $subscriptionId,
        // Reagendamentos por 429 (limite da API) já feitos neste dia.
        public readonly int $rateLimitRetries = 0,
    ) {
        $this->onQueue((string) config('billing.webhooks.queue', 'default'));
    }

    public function handle(
        GatewayRegistry $gatewayRegistry,
        CircuitBreakerService $circuitBreaker,
        FinancialEventService $financialEventService,
        BillingLogService $billingLogService,
        SubscriptionCycleService $cycles,
    ): void {
        $subscription = Subscription::query()
            ->with(['entity', 'plan'])
            ->find($this->subscriptionId);

        // Re-checagem: renovada, cancelada ou migrada para recorrência nativa.
        if (! $subscription || ! $subscription->isDueForLocalRenewal()) {
            return;
        }

        $periodStart = CarbonImmutable::instance($subscription->nextBillingDate())->startOfDay();

        if ($subscription->periodEndFrom($periodStart) === null || ! $subscription->recurringAmount()) {
            Log::warning('[RenewSubscriptionJob] Assinatura sem ciclo ou valor recorrente — não renovada.', [
                'subscription' => $subscription->id,
            ]);

            return;
        }

        $correlationId = (string) Str::uuid();
        $entity        = $subscription->entity;
        $gatewayCode   = (string) $subscription->gateway;
        $cardRenewal   = $this->chargesSavedCard($subscription, $gatewayRegistry);

        // Cartão é cobrado no dia do vencimento: antes disso, nada a fazer
        // (o agendador despacha de novo no dia seguinte).
        if ($cardRenewal && $periodStart->greaterThan(CarbonImmutable::today())) {
            return;
        }

        // ── Fase 1: DB — fatura do período (uma por assinatura + período) ──────
        $invoice = DB::transaction(function () use ($subscription, $periodStart, $correlationId, $cycles): Invoice {
            Subscription::query()->whereKey($subscription->id)->lockForUpdate()->first();

            return $cycles->periodInvoice($subscription, $periodStart, $correlationId, InvoiceStatus::Draft, 'renew');
        });

        // Cartão recusado e o cliente abriu Pix/boleto pelo checkout: o
        // cartão segue sendo tentado (aprovado, o Pix/boleto é cancelado).
        $retryCard = $cardRenewal && $subscription->cardRetryPending($invoice);

        // Cobrança do período já emitida (ou paga): nada a fazer.
        if (! $retryCard && in_array($invoice->status, [InvoiceStatus::Pending, InvoiceStatus::Overdue, InvoiceStatus::Paid], true)) {
            return;
        }

        $attemptNumber  = PaymentAttempt::query()->where('invoice_id', $invoice->id)->count() + 1;
        $idempotencyKey = "{$invoice->idempotency_key}:{$attemptNumber}";
        // Chave enviada ao gateway: a da tentativa anterior se ela falhou sem
        // resposta definitiva (timeout/5xx — o gateway pode ter criado a
        // cobrança e devolve a mesma); nova só depois de recusa (4xx).
        $chargeKey = ChargeIdempotency::keyFor($invoice, $idempotencyKey);
        // Vence em next_billing_at; numa nova tentativa depois dessa data,
        // hoje (gateway não aceita cobrança já vencida). O período não muda.
        $chargeDueDate = $periodStart->max(CarbonImmutable::today());

        // ── Fase 2: HTTP — gateway ─────────────────────────────────────────────
        if ($circuitBreaker->isOpen($gatewayCode, (string) $entity->id)) {
            $this->recordFailure($subscription, $invoice, $gatewayCode, $attemptNumber, $idempotencyKey, $chargeKey, $correlationId, __('manager_subscriptions.billing_errors.renewal_circuit_open'), $financialEventService, errorCode: ChargeIdempotency::CIRCUIT_OPEN);

            return;
        }

        try {
            $gateway = $gatewayRegistry->get($gatewayCode)->withContext(new GatewayCallContext($correlationId, (string) $entity->id));

            $charge = $cardRenewal ? $gateway->chargeCard(new CardChargeDTO(
                entityId: (string) $entity->id,
                invoiceId: (string) $invoice->id,
                subscriptionId: (string) $subscription->id,
                customerId: $this->gatewayCustomerId($gateway, $subscription, $entity),
                amount: (float) $invoice->amount,
                currency: (string) $invoice->currency,
                description: "Fatura {$invoice->reference}",
                payer: BillingCustomer::customer($entity),
                savedCardId: (string) $subscription->gateway_card_id,
                // Pagar.me exige 1 parcela em recorrência; Mercado Pago e
                // PagBank só documentam 1 na cobrança iniciada pelo lojista:
                // à vista, salvo gateway que declare aceitar
                // (supportsRenewalInstallments) — a tela e o lembrete avisam.
                installments: $gateway->supportsRenewalInstallments() ? max(1, (int) $subscription->card_installments) : 1,
                offSession: true,
                idempotencyKey: $chargeKey,
                metadata: $this->savedCardReferences($subscription),
            )) : $gateway->createCharge(new CreateChargeDTO(
                entityId: (string) $entity->id,
                invoiceId: (string) $invoice->id,
                subscriptionId: (string) $subscription->id,
                customerId: $this->gatewayCustomerId($gateway, $subscription, $entity),
                amount: (float) $invoice->amount,
                currency: (string) $invoice->currency,
                description: "Fatura {$invoice->reference}",
                dueDate: $chargeDueDate->toDateString(),
                idempotencyKey: $chargeKey,
                metadata: BillingCustomer::chargeMetadata($entity),
                // Tentativa anterior sem resposta definitiva (timeout/5xx):
                // o gateway pode ter criado a cobrança — reaproveita
                // (Asaas: sem chave de idempotência, busca pela referência).
                knownChargeIds: $invoice->knownChargeIds(),
                lookupBeforeCreate: ChargeIdempotency::previousAttemptInconclusive($invoice),
            ));
        } catch (GatewayIntegrationException $e) {
            // 429: a API pediu para esperar — nova tentativa depois do tempo
            // dela (no máximo 3 vezes no dia), nunca na hora.
            if ($e->isRateLimit() && $this->rateLimitRetries < 3) {
                self::dispatch($this->subscriptionId, $this->rateLimitRetries + 1)
                    ->delay(now()->addSeconds(max(30, (int) $e->retryAfter())));

                return;
            }

            $circuitBreaker->recordFailure($gatewayCode, $e->getTriggerType(), (string) $entity->id);
            $this->recordFailure(
                $subscription,
                $invoice,
                $gatewayCode,
                $attemptNumber,
                $idempotencyKey,
                $chargeKey,
                $correlationId,
                $e->getMessage(),
                $financialEventService,
                errorCode: $e->getTriggerType(),
            );

            return;
        } catch (Throwable $e) {
            $circuitBreaker->recordFailure($gatewayCode, 'exception', (string) $entity->id);
            $this->recordFailure(
                $subscription,
                $invoice,
                $gatewayCode,
                $attemptNumber,
                $idempotencyKey,
                $chargeKey,
                $correlationId,
                $e->getMessage(),
                $financialEventService,
                errorCode: $e instanceof GatewayIntegrationException ? $e->getTriggerType() : 'exception',
            );

            return;
        }

        $paymentStatus = PaymentStatus::fromGatewayStatus($charge->status);

        if (! $charge->success || $paymentStatus->isUnusable()) {
            // Cartão recusado é do cliente, não do gateway: não abre o circuito.
            if (! ($cardRenewal && $charge->success)) {
                $circuitBreaker->recordFailure($gatewayCode, 'declined', (string) $entity->id);
            }

            $this->recordFailure(
                $subscription,
                $invoice,
                $gatewayCode,
                $attemptNumber,
                $idempotencyKey,
                $chargeKey,
                $correlationId,
                $charge->success
                    ? __('manager_subscriptions.billing_errors.renewal_charge_declined', ['status' => (string) $charge->status])
                    : (string) $charge->errorMessage,
                $financialEventService,
                $charge,
                $charge->success ? ChargeIdempotency::DECLINED : $charge->errorCode,
            );

            return;
        }

        $circuitBreaker->recordSuccess($gatewayCode, (string) $entity->id);

        // ── Fase 3: DB — cobrança emitida (e paga, se o gateway já confirmou) ───
        $staleCharges = [];
        $applied      = DB::transaction(function () use ($subscription, $invoice, $charge, $paymentStatus, $periodStart, $gatewayCode, $attemptNumber, $idempotencyKey, $chargeKey, $correlationId, $cycles, $billingLogService, $cardRenewal, &$staleCharges): bool {
            Entity::query()->whereKey($subscription->entity_id)->lockForUpdate()->first();
            $locked    = Subscription::query()->with('plan')->whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            $gatewayId = Gateway::query()->where('code', $gatewayCode)->value('id');

            // Durante a chamada ao gateway a assinatura foi encerrada (régua)
            // ou substituída: a cobrança fica registrada, mas nada reabre a
            // fatura nem ressuscita a assinatura.
            if (! $this->stillRenewable($locked, $gatewayCode)) {
                $this->recordSuperseded($locked, $invoice->refresh(), $charge, $paymentStatus, $gatewayCode, $gatewayId, $attemptNumber, $idempotencyKey, $chargeKey, $correlationId, $billingLogService);

                return false;
            }

            $attempt = PaymentAttempt::query()->create([
                'entity_id'        => $locked->entity_id,
                'subscription_id'  => $locked->id,
                'invoice_id'       => $invoice->id,
                'gateway_id'       => $gatewayId,
                'gateway_code'     => $gatewayCode,
                'attempt_number'   => $attemptNumber,
                'status'           => PaymentAttemptStatus::Succeeded->value,
                'trigger'          => 'renewal',
                'request_payload'  => ['invoice_id' => $invoice->id, 'charge_idempotency_key' => $chargeKey],
                'response_payload' => $charge->rawResponse,
                'started_at'       => now(),
                'finished_at'      => now(),
                'idempotency_key'  => $idempotencyKey,
                'correlation_id'   => $correlationId,
            ]);

            // O webhook da cobrança pode ter chegado antes: reaproveita o pagamento.
            $existing = filled($charge->externalPaymentId)
                ? Payment::query()->where('gateway_code', $gatewayCode)->where('external_payment_id', $charge->externalPaymentId)->first()
                : null;

            $payment = $existing ?? Payment::query()->create([
                'entity_id'           => $locked->entity_id,
                'invoice_id'          => $invoice->id,
                'subscription_id'     => $locked->id,
                'gateway_id'          => $gatewayId,
                'gateway_code'        => $gatewayCode,
                'external_payment_id' => $charge->externalPaymentId ?: null,
                'status'              => PaymentStatus::Pending->value,
                'amount'              => $charge->amount ?? (float) $invoice->amount,
                'currency'            => $invoice->currency,
                'raw_gateway_payload' => $charge->rawResponse,
                'idempotency_key'     => 'payment-' . $idempotencyKey,
                'correlation_id'      => $correlationId,
                'payment_method'      => $cardRenewal ? 'credit_card' : null,
                'metadata'            => ['source' => 'renewal'],
            ]);

            $attempt->update(['payment_id' => $payment->id]);

            $invoice->refresh();

            // Cartão aprovado numa fatura com Pix/boleto aberto pelo cliente:
            // as outras cobranças dela deixam de valer (canceladas depois do commit).
            if ($cardRenewal && $invoice->status !== InvoiceStatus::Paid && filled($charge->externalPaymentId)) {
                $staleCharges = $invoice->liveChargeIds((string) $charge->externalPaymentId);

                if ($staleCharges !== []) {
                    $metadata                      = (array) ($invoice->metadata ?? []);
                    $metadata['detached_charges']  = array_values(array_unique([...(array) ($metadata['detached_charges'] ?? []), ...$staleCharges]));
                    $metadata['cancelled_charges'] = array_values(array_unique([...(array) ($metadata['cancelled_charges'] ?? []), ...$staleCharges]));

                    $invoice->forceFill(['metadata' => $metadata, 'payment_instructions' => null])->save();

                    Payment::query()
                        ->where('gateway_code', $gatewayCode)
                        ->whereIn('external_payment_id', $staleCharges)
                        ->where('status', PaymentStatus::Pending->value)
                        ->update(['status' => PaymentStatus::Cancelled->value]);
                }
            }

            // Não volta para pendente o que o webhook já confirmou. O link de
            // pagamento da resposta já fica na fatura (lembrete da régua e
            // "Pagar agora" no painel), sem esperar o webhook.
            $invoice->update(array_filter([
                'gateway_id'          => $gatewayId,
                'gateway_code'        => $gatewayCode,
                'external_invoice_id' => $charge->externalPaymentId ?: null,
                'status'              => $invoice->status === InvoiceStatus::Paid ? null : InvoiceStatus::Pending->value,
                'raw_gateway_payload' => $charge->rawResponse,
                'payment_url'         => $charge->paymentUrl ?: null,
                'payment_method'      => $cardRenewal ? 'credit_card' : null,
            ], fn ($value) => $value !== null));

            $locked->update([
                'current_invoice_id' => $invoice->id,
                'billing_state'      => $locked->billing_state === 'error' ? 'pending' : $locked->billing_state,
                'last_billing_error' => null,
            ]);

            // Sequência da recorrência no cartão (Mercado Pago Automatic Payments).
            if ($cardRenewal && $paymentStatus === PaymentStatus::Paid) {
                $payload = $locked->gateway_payload ?? [];
                data_set($payload, 'card.sequence', (int) data_get($payload, 'card.sequence', 1) + 1);
                $locked->update(['gateway_payload' => $payload]);
            }

            if ($paymentStatus === PaymentStatus::Paid) {
                $cycles->confirmPayment($locked, $invoice, $payment, $periodStart, $correlationId, 'renewal');
            }

            return true;
        });

        if (! $applied) {
            return;
        }

        foreach ($staleCharges as $staleId) {
            if (! $gateway->cancelCharge($staleId)) {
                $billingLogService->log(
                    level: 'warning',
                    message: 'Renovação no cartão aprovada; o Pix/boleto aberto pelo cliente não pôde ser cancelado no gateway — cancelar manualmente (se pago, estornar).',
                    context: ['external_charge_id' => $staleId],
                    entityId: (string) $entity->id,
                    subscription: $subscription,
                    invoice: $invoice,
                    gatewayCode: $gatewayCode,
                    correlationId: $correlationId,
                );
            }
        }

        $financialEventService->record(
            eventType: BillingEventType::InvoiceCreated,
            entityId: (string) $entity->id,
            subscription: $subscription,
            invoice: $invoice,
            amount: (float) $invoice->amount,
            currency: $invoice->currency,
            correlationId: $correlationId,
            source: 'scheduler',
        );

        $billingLogService->log(
            level: 'info',
            message: 'Cobrança de renovação emitida.',
            context: [
                'gateway'        => $gatewayCode,
                'external_id'    => $charge->externalPaymentId,
                'period_start'   => $periodStart->toDateString(),
                'payment_status' => $paymentStatus->value,
            ],
            entityId: (string) $entity->id,
            subscription: $subscription,
            invoice: $invoice,
            gatewayCode: $gatewayCode,
            correlationId: $correlationId,
        );
    }

    /**
     * Cliente no gateway. Assinatura sem gateway_customer_id (linha antiga,
     * ou ativação que não o gravou) cadastra/reaproveita o cliente agora e
     * guarda o id — mandar o id local da empresa como cliente seria recusado
     * pelos gateways com cadastro de cliente (Asaas, Pagar.me, Stripe).
     */
    private function gatewayCustomerId(PaymentGatewayInterface $gateway, Subscription $subscription, Entity $entity): string
    {
        if (filled($subscription->gateway_customer_id)) {
            return (string) $subscription->gateway_customer_id;
        }

        $customerId = $gateway->upsertCustomer(BillingCustomer::customer($entity));

        Subscription::query()->whereKey($subscription->id)->update(['gateway_customer_id' => $customerId]);
        $subscription->gateway_customer_id = $customerId;

        return $customerId;
    }

    /** Renovação no cartão salvo: forma cartão, cartão guardado e gateway com cartão transparente. */
    private function chargesSavedCard(Subscription $subscription, GatewayRegistry $registry): bool
    {
        return $subscription->payment_method === 'credit_card'
            && filled($subscription->gateway_card_id)
            && filled($subscription->gateway)
            && $registry->has((string) $subscription->gateway)
            && $registry->get((string) $subscription->gateway)->chargesSavedCards();
    }

    /**
     * Referências da 1ª cobrança no cartão que o gateway pede na cobrança
     * iniciada pelo lojista (ver BillingSubscriptionOrchestrator::cardPayload).
     *
     * @return array<string, mixed>
     */
    private function savedCardReferences(Subscription $subscription): array
    {
        $card = (array) data_get($subscription->gateway_payload, 'card', []);

        return array_filter([
            'card_origin_payment_id'      => $card['origin_payment_id'] ?? null,
            'card_network_transaction_id' => $card['network_transaction_id'] ?? null,
            'card_payment_method_id'      => $card['payment_method_id'] ?? $subscription->card_brand,
            'card_sequence'               => (int) ($card['sequence'] ?? 1) + 1,
            'cycle_months'                => $subscription->effectiveCycle()?->months(),
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * A renovação ainda vale depois da chamada ao gateway? Cobrança automática
     * vigente (ativa ou em atraso), não substituída, no mesmo gateway e sem
     * recorrência própria. A fatura cancelada não decide sozinha: a
     * cobrança expirada (Pix vencido) é reemitida na mesma fatura do período.
     */
    private function stillRenewable(Subscription $subscription, string $gatewayCode): bool
    {
        return $subscription->billing_mode === SubscriptionBillingMode::Gateway
            && in_array($subscription->status, [SubscriptionStatus::Active, SubscriptionStatus::PastDue], true)
            && blank($subscription->cancelled_reason)
            && ! $subscription->needs_billing_reconciliation
            && $subscription->gateway === $gatewayCode
            && blank($subscription->gateway_subscription_id);
    }

    /**
     * Cobrança emitida para uma assinatura que já não renova: tentativa e
     * pagamento registrados (o webhook dela cai no alerta de pagamento não
     * aplicado), fatura e assinatura intactas. Paga na hora → crítico, para
     * o time estornar; pendente → cancelar a cobrança no gateway.
     */
    private function recordSuperseded(
        Subscription $subscription,
        Invoice $invoice,
        CreateChargeResultDTO $charge,
        PaymentStatus $paymentStatus,
        string $gatewayCode,
        ?string $gatewayId,
        int $attemptNumber,
        string $idempotencyKey,
        string $chargeKey,
        string $correlationId,
        BillingLogService $billingLogService,
    ): void {
        $paid = $paymentStatus === PaymentStatus::Paid;

        $attempt = PaymentAttempt::query()->create([
            'entity_id'        => $subscription->entity_id,
            'subscription_id'  => $subscription->id,
            'invoice_id'       => $invoice->id,
            'gateway_id'       => $gatewayId,
            'gateway_code'     => $gatewayCode,
            'attempt_number'   => $attemptNumber,
            'status'           => PaymentAttemptStatus::Succeeded->value,
            'trigger'          => 'renewal',
            'request_payload'  => ['invoice_id' => $invoice->id, 'charge_idempotency_key' => $chargeKey],
            'response_payload' => $charge->rawResponse,
            'started_at'       => now(),
            'finished_at'      => now(),
            'idempotency_key'  => $idempotencyKey,
            'correlation_id'   => $correlationId,
        ]);

        $existing = filled($charge->externalPaymentId)
            ? Payment::query()->where('gateway_code', $gatewayCode)->where('external_payment_id', $charge->externalPaymentId)->first()
            : null;

        $payment = $existing ?? Payment::query()->create([
            'entity_id'           => $subscription->entity_id,
            'invoice_id'          => $invoice->id,
            'subscription_id'     => $subscription->id,
            'gateway_id'          => $gatewayId,
            'gateway_code'        => $gatewayCode,
            'external_payment_id' => $charge->externalPaymentId ?: null,
            'status'              => $paid ? PaymentStatus::Paid->value : PaymentStatus::Pending->value,
            'paid_at'             => $paid ? now() : null,
            'amount'              => $charge->amount ?? (float) $invoice->amount,
            'currency'            => $invoice->currency,
            'raw_gateway_payload' => $charge->rawResponse,
            'idempotency_key'     => 'payment-' . $idempotencyKey,
            'correlation_id'      => $correlationId,
            'metadata'            => ['source' => 'renewal', 'alert' => 'subscription_not_renewable'],
        ]);

        $attempt->update(['payment_id' => $payment->id]);

        $billingLogService->log(
            level: $paid ? 'critical' : 'warning',
            message: $paid
                ? 'Renovação paga na hora para assinatura encerrada ou substituída durante a emissão — estornar no gateway.'
                : 'Renovação emitida para assinatura encerrada ou substituída durante a emissão — cancelar a cobrança no gateway.',
            context: [
                'gateway'             => $gatewayCode,
                'external_charge_id'  => $charge->externalPaymentId,
                'subscription_status' => $subscription->status?->value,
                'invoice_status'      => $invoice->status?->value,
            ],
            entityId: (string) $subscription->entity_id,
            subscription: $subscription,
            invoice: $invoice,
            payment: $payment,
            gatewayCode: $gatewayCode,
            correlationId: $correlationId,
        );
    }

    /**
     * Emissão falhou: fatura em falha (o agendador tenta de novo no dia
     * seguinte — com a mesma chave de idempotência no gateway se a falha não
     * foi definitiva, ver ChargeIdempotency) e o motivo fica visível para o
     * manager.
     */
    private function recordFailure(
        Subscription $subscription,
        Invoice $invoice,
        string $gatewayCode,
        int $attemptNumber,
        string $idempotencyKey,
        string $chargeKey,
        string $correlationId,
        string $error,
        FinancialEventService $financialEventService,
        ?CreateChargeResultDTO $charge = null,
        ?string $errorCode = null,
    ): void {
        DB::transaction(function () use ($subscription, $invoice, $gatewayCode, $attemptNumber, $idempotencyKey, $chargeKey, $correlationId, $error, $charge, $errorCode): void {
            PaymentAttempt::query()->create([
                'entity_id'        => $subscription->entity_id,
                'subscription_id'  => $subscription->id,
                'invoice_id'       => $invoice->id,
                'gateway_code'     => $gatewayCode,
                'attempt_number'   => $attemptNumber,
                'status'           => PaymentAttemptStatus::Failed->value,
                'trigger'          => 'renewal',
                'request_payload'  => ['invoice_id' => $invoice->id, 'charge_idempotency_key' => $chargeKey],
                'response_payload' => $charge?->rawResponse,
                'error_code'       => $errorCode !== null ? mb_substr($errorCode, 0, 80) : null,
                'error_message'    => mb_substr($error, 0, 2000),
                'started_at'       => now(),
                'finished_at'      => now(),
                'idempotency_key'  => $idempotencyKey,
                'correlation_id'   => $correlationId,
            ]);

            // Nova tentativa no cartão numa fatura com Pix/boleto aberto pelo
            // cliente: a fatura segue em aberto (o Pix/boleto ainda vale).
            if (! in_array($invoice->fresh()?->status, [InvoiceStatus::Pending, InvoiceStatus::Overdue], true)) {
                $invoice->update(['status' => InvoiceStatus::Failed->value]);
            }

            $subscription->update([
                'billing_state'      => 'error',
                'last_billing_error' => mb_substr($error, 0, 500),
            ]);
        });

        $financialEventService->record(
            eventType: BillingEventType::PaymentFailed,
            entityId: (string) $subscription->entity_id,
            subscription: $subscription,
            invoice: $invoice,
            amount: (float) $invoice->amount,
            currency: $invoice->currency,
            correlationId: $correlationId,
            source: 'scheduler',
        );

        Log::warning('[RenewSubscriptionJob] Falha ao emitir a cobrança de renovação.', [
            'subscription' => $subscription->id,
            'error'        => $error,
            'correlation'  => $correlationId,
        ]);
    }
}
