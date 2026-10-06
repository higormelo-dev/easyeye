<?php

namespace App\Services\Billing;

use App\Contracts\Billing\{PaymentGatewayInterface, QueriesGatewayRecurrences};
use App\DTOs\Billing\{CancelSubscriptionDTO, CardChargeDTO, CheckoutPayment, CreateChargeDTO, CreateSubscriptionDTO, GatewayCallContext};
use App\DTOs\Billing\{CreateChargeResultDTO, CreateSubscriptionResultDTO};
use App\Enums\Billing\{BillingEventType, InvoiceStatus, PaymentAttemptStatus, PaymentStatus, SubscriptionCancelledReason};
use App\Enums\{BillingCycle, SubscriptionBillingMode, SubscriptionStatus};
use App\Exceptions\Billing\{GatewayIntegrationException, SubscriptionSupersededException};
use App\Models\Billing\Gateway;
use App\Models\Billing\{Invoice, Payment, PaymentAttempt};
use App\Models\{Entity, Plan, Subscription};
use App\Support\Billing\{BillingCustomer, ChargeIdempotency, PayloadSanitizer};
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\{DB, Log};
use Illuminate\Validation\ValidationException;
use Throwable;

class BillingSubscriptionOrchestrator
{
    /** Criação da recorrência sem resposta e sem como conferir no gateway: sem fallback. */
    public const TRIGGER_RECURRENCE_UNCONFIRMED = 'recurrence_unconfirmed';

    /** Cartão recusado na contratação pelo checkout: sem fallback (o token é do gateway). */
    public const TRIGGER_CARD_DECLINED = 'card_declined';

    public function __construct(
        private readonly GatewayResolver $gatewayResolver,
        private readonly GatewayRegistry $gatewayRegistry,
        private readonly FallbackGatewayService $fallbackService,
        private readonly CircuitBreakerService $circuitBreaker,
        private readonly CorrelationIdService $correlationIdService,
        private readonly BillingLogService $billingLogService,
        private readonly FinancialEventService $financialEventService,
        private readonly SubscriptionCycleService $cycles,
        private readonly BillingCancellationService $cancellation,
    ) {
    }

    // ────────────────────────────────────────────────────────────────────────
    // Ativação principal
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Ativa uma assinatura paga para o tenant, integrando com o gateway selecionado.
     *
     * Fluxo separado em 3 fases para evitar transações DB envolvendo HTTP:
     *   Fase 1 — DB: cria a nova assinatura (aguardando o 1º pagamento) e a
     *            fatura do 1º período. A vigente anterior continua intacta.
     *   Fase 2 — HTTP: cliente, assinatura e (se o gateway não a emite junto)
     *            a 1ª cobrança. Recusa do gateway = falha.
     *   Fase 3 — DB: a nova assume o lugar das vigentes anteriores (que param
     *            de cobrar no gateway) e dá acesso até o fim do dia do
     *            vencimento da 1ª cobrança; pago → ativa.
     *
     * Falha no gateway (e no fallback): a vigente anterior fica intacta e a
     * nova não dá acesso nem entra no MRR — a exceção chega ao manager.
     *
     * $checkout (checkout transparente): Pix/boleto emitem a 1ª cobrança
     * nessa forma; cartão (token do SDK do gateway) cobra na hora, com
     * vencimento hoje, e guarda o cartão para a renovação — recusa encerra a
     * tentativa sem fallback (TRIGGER_CARD_DECLINED). Gateway sem a forma
     * transparente (InfinitePay; cartão no Asaas) segue pelo link.
     */
    public function activateWithGateway(
        Entity $entity,
        Plan $plan,
        BillingCycle $cycle = BillingCycle::Monthly,
        ?string $requestedGateway = null,
        ?CheckoutPayment $checkout = null,
    ): Subscription {
        // O preço vem do ciclo escolhido (antes era sempre o preço de
        // referência do plano: "anual" cobrava um mês). Ciclo que o plano
        // não vende (inclusive vitalício, fora da estratégia) é recusado.
        $amount = $plan->priceFor($cycle);

        if ($amount === null) {
            throw ValidationException::withMessages([
                'billing_cycle' => __('manager_subscriptions.errors.cycle_not_offered', [
                    'cycle' => $cycle->label(),
                    'plan'  => $plan->name,
                ]),
            ]);
        }

        $correlationId = $this->correlationIdService->resolve();
        // Cartão cobra na hora: o 1º período começa hoje.
        $firstDueDate = $checkout?->isCard() ? CarbonImmutable::today() : $this->cycles->firstDueDate();

        // Tentativa anterior com recorrência não conferida: confirma antes.
        $this->ensureNoPendingOrphanRecurrence($entity, $correlationId);

        // ── Fase 1: registros pendentes ──────────────────────────────────────
        [$subscription, $invoice, $idempotencyKey] = $this->persistPendingActivation(
            entity: $entity,
            plan: $plan,
            cycle: $cycle,
            amount: $amount,
            firstDueDate: $firstDueDate,
            correlationId: $correlationId,
        );

        // ── Fase 2: gateway (fora de transação DB) ───────────────────────────
        try {
            [$gateway, $customerId, $externalSub, $charge] = $this->executeGatewayFlow(
                entity: $entity,
                plan: $plan,
                subscription: $subscription,
                invoice: $invoice,
                cycle: $cycle,
                firstDueDate: $firstDueDate,
                requestedGateway: $requestedGateway,
                idempotencyKey: $idempotencyKey,
                correlationId: $correlationId,
                checkout: $checkout,
            );
        } catch (Throwable $e) {
            // Qualquer falha (não só a do gateway) encerra a tentativa: a nova
            // não pode ficar vigente sem cobrança emitida.
            $this->persistActivationFailure($subscription, $invoice, $e->getMessage(), $correlationId);

            throw $e;
        }

        // ── Fase 3: confirmar no DB ──────────────────────────────────────────
        [$activated, $replaced, $superseded] = $this->persistActivationResult(
            entity: $entity,
            subscription: $subscription,
            invoice: $invoice,
            gateway: $gateway,
            customerId: $customerId,
            externalSub: $externalSub,
            charge: $charge,
            firstDueDate: $firstDueDate,
            correlationId: $correlationId,
            checkout: $checkout,
        );

        // Outra alteração assumiu o lugar desta enquanto o gateway respondia:
        // a recorrência recém-criada é desfeita (depois do commit) e o
        // manager é avisado — a alteração mais recente é a que vale.
        if ($superseded) {
            $this->cycles->stopRecurrences(collect([$activated]), $correlationId);

            // A 1ª cobrança avulsa já emitida (fatura cancelada aqui) também
            // é cancelada no gateway quando ele permite — senão fica o alerta.
            if (! $activated->hasGatewayRecurrence() && $charge?->externalPaymentId
                && PaymentStatus::fromGatewayStatus($charge->status) !== PaymentStatus::Paid) {
                $this->cancellation->cancelOpenCharges($activated, Invoice::query()->whereKey($invoice->id)->get(), $correlationId);
            }

            throw new SubscriptionSupersededException(__('manager_subscriptions.errors.activation_superseded'));
        }

        // A recorrência das substituídas para no gateway só depois do commit.
        $this->cycles->stopRecurrences($replaced, $correlationId);

        return $activated;
    }

    // ────────────────────────────────────────────────────────────────────────
    // Fase 1 — Persistência pendente
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Cria a assinatura (PastDue, aguardando o 1º pagamento) e a fatura do
     * 1º período (Draft) em transação rápida. Não mexe na vigente: ela só é
     * substituída depois do sucesso no gateway. Sem `gateway` preenchido a
     * nova ainda não dá acesso (ver Subscription::firstChargeAccessEndsAt).
     *
     * @return array{0: Subscription, 1: Invoice, 2: string}
     */
    private function persistPendingActivation(
        Entity $entity,
        Plan $plan,
        BillingCycle $cycle,
        float $amount,
        CarbonImmutable $firstDueDate,
        string $correlationId,
    ): array {
        return DB::transaction(function () use ($entity, $plan, $cycle, $amount, $firstDueDate, $correlationId): array {
            $dueAt = $firstDueDate->endOfDay();

            $subscription = Subscription::create([
                'entity_id'     => $entity->id,
                'plan_id'       => $plan->id,
                'billing_cycle' => $cycle,
                'amount'        => $amount,
                'billing_mode'  => SubscriptionBillingMode::Gateway,
                'status'        => SubscriptionStatus::PastDue,
                'billing_state' => 'pending_activation',
                'starts_at'     => now(),
                // Até o 1º pagamento: vencimento da 1ª cobrança (fim do dia).
                'ends_at'         => $dueAt,
                'next_billing_at' => $dueAt,
                'correlation_id'  => $correlationId,
            ]);

            $idempotencyKey = 'charge-1-' . $subscription->id;

            // 1º período: do vencimento da 1ª cobrança até o próximo.
            $invoice = Invoice::create([
                'entity_id'       => $entity->id,
                'subscription_id' => $subscription->id,
                'plan_id'         => $plan->id,
                'reference'       => $this->buildInvoiceReference($subscription->id),
                'amount'          => $amount,
                'currency'        => 'BRL',
                'period_start'    => $firstDueDate->toDateString(),
                'period_end'      => $subscription->periodEndFrom($firstDueDate)?->toDateString(),
                'status'          => InvoiceStatus::Draft,
                'due_at'          => $dueAt,
                'billing_reason'  => SubscriptionCycleService::BILLING_REASON_CREATE,
                'correlation_id'  => $correlationId,
                'idempotency_key' => $idempotencyKey,
            ]);

            $subscription->update(['current_invoice_id' => $invoice->id]);

            return [$subscription, $invoice, $idempotencyKey];
        });
    }

    // ────────────────────────────────────────────────────────────────────────
    // Fase 2 — Chamadas ao gateway (sem transação DB)
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Executa as chamadas ao gateway:
     *   1. upsertCustomer
     *   2. createSubscription
     *   3. createCharge (com idempotency_key) — só se a assinatura do gateway
     *      não emite a 1ª cobrança sozinha
     *
     * Em falha, tenta o fallback gateway configurado.
     * Se ambos falharem, propaga a exceção.
     *
     * @return array{0: PaymentGatewayInterface, 1: string, 2: CreateSubscriptionResultDTO, 3: CreateChargeResultDTO|null}
     */
    private function executeGatewayFlow(
        Entity $entity,
        Plan $plan,
        Subscription $subscription,
        Invoice $invoice,
        BillingCycle $cycle,
        CarbonImmutable $firstDueDate,
        ?string $requestedGateway,
        string $idempotencyKey,
        string $correlationId,
        ?CheckoutPayment $checkout = null,
    ): array {
        $gateway = $this->gatewayResolver->resolveForNewPurchase($entity, $plan, $requestedGateway);
        // Cartão tokenizado só vale no gateway que gerou o token: sem fallback.
        $cardBound = $checkout?->isCard() && $gateway->supportsTransparent($checkout->method->value);

        // Verifica circuit breaker antes de tentar o gateway
        if ($this->circuitBreaker->isOpen($gateway->code(), (string) $entity->id)) {
            if ($cardBound) {
                throw GatewayIntegrationException::unavailable($gateway->code(), "Circuit aberto para gateway [{$gateway->code()}].");
            }

            return $this->tryFallbackGateway(
                entity: $entity,
                plan: $plan,
                subscription: $subscription,
                invoice: $invoice,
                cycle: $cycle,
                firstDueDate: $firstDueDate,
                primaryGatewayCode: $gateway->code(),
                triggerType: 'gateway_unavailable',
                idempotencyKey: $idempotencyKey,
                correlationId: $correlationId,
                originalError: "Circuit aberto para gateway [{$gateway->code()}].",
                checkout: $checkout,
            );
        }

        try {
            $result = $this->callGateway($gateway, $entity, $plan, $subscription, $invoice, $cycle, $firstDueDate, $idempotencyKey, $correlationId, $checkout);
            $this->circuitBreaker->recordSuccess($gateway->code());

            return [$gateway, ...$result];
        } catch (GatewayIntegrationException $e) {
            // Recusa do cartão não é falha do gateway (não abre o circuito).
            if ($e->getTriggerType() !== self::TRIGGER_CARD_DECLINED) {
                $this->circuitBreaker->recordFailure($gateway->code(), $e->getTriggerType());
            }

            // A recorrência pode ter ficado criada no primário: outro gateway
            // agora seria uma segunda cobrança. Cartão: o token é do primário.
            if ($e->getTriggerType() === self::TRIGGER_RECURRENCE_UNCONFIRMED || $cardBound) {
                throw $e;
            }

            Log::warning("BillingOrchestrator: falha no gateway primário [{$gateway->code()}].", [
                'error'          => $e->getMessage(),
                'trigger'        => $e->getTriggerType(),
                'entity_id'      => $entity->id,
                'correlation_id' => $correlationId,
            ]);

            return $this->tryFallbackGateway(
                entity: $entity,
                plan: $plan,
                subscription: $subscription,
                invoice: $invoice,
                cycle: $cycle,
                firstDueDate: $firstDueDate,
                primaryGatewayCode: $gateway->code(),
                triggerType: $e->getTriggerType(),
                idempotencyKey: $idempotencyKey,
                correlationId: $correlationId,
                originalError: $e->getMessage(),
                checkout: $checkout,
            );
        }
    }

    /** Tenta o gateway de fallback; lança se não houver ou se também falhar. */
    private function tryFallbackGateway(
        Entity $entity,
        Plan $plan,
        Subscription $subscription,
        Invoice $invoice,
        BillingCycle $cycle,
        CarbonImmutable $firstDueDate,
        string $primaryGatewayCode,
        string $triggerType,
        string $idempotencyKey,
        string $correlationId,
        string $originalError,
        ?CheckoutPayment $checkout = null,
    ): array {
        $this->financialEventService->record(
            eventType: BillingEventType::GatewayFallbackTriggered,
            entityId: (string) $entity->id,
            subscription: $subscription,
            invoice: $invoice,
            correlationId: $correlationId,
            source: 'activation',
            metadata: ['primary_gateway' => $primaryGatewayCode, 'trigger' => $triggerType],
        );

        $fallbackCode = $this->fallbackService->resolve(
            entityId: (string) $entity->id,
            planId: (string) $plan->id,
            primaryGatewayCode: $primaryGatewayCode,
            triggerType: $triggerType,
        );

        if (! $fallbackCode) {
            $this->financialEventService->record(
                eventType: BillingEventType::GatewayFallbackFailed,
                entityId: (string) $entity->id,
                subscription: $subscription,
                invoice: $invoice,
                correlationId: $correlationId,
                source: 'activation',
                metadata: ['reason' => 'no_fallback_configured'],
            );

            throw new GatewayIntegrationException(
                "Falha no gateway [{$primaryGatewayCode}] e nenhum fallback configurado. Erro original: {$originalError}",
                $triggerType,
            );
        }

        $fallbackGateway = $this->gatewayRegistry->get($fallbackCode);

        try {
            $result = $this->callGateway($fallbackGateway, $entity, $plan, $subscription, $invoice, $cycle, $firstDueDate, $idempotencyKey, $correlationId, $checkout);
            $this->circuitBreaker->recordSuccess($fallbackCode);

            $this->financialEventService->record(
                eventType: BillingEventType::GatewayFallbackSucceeded,
                entityId: (string) $entity->id,
                subscription: $subscription,
                invoice: $invoice,
                correlationId: $correlationId,
                source: 'activation',
                metadata: ['fallback_gateway' => $fallbackCode],
            );

            return [$fallbackGateway, ...$result];
        } catch (GatewayIntegrationException $e2) {
            $this->circuitBreaker->recordFailure($fallbackCode, $e2->getTriggerType());

            $this->financialEventService->record(
                eventType: BillingEventType::GatewayFallbackFailed,
                entityId: (string) $entity->id,
                subscription: $subscription,
                invoice: $invoice,
                correlationId: $correlationId,
                source: 'activation',
                metadata: ['fallback_gateway' => $fallbackCode, 'error' => $e2->getMessage()],
            );

            throw $e2;
        }
    }

    /**
     * Executa as chamadas ao gateway na ordem correta.
     * Retorna [customerId, externalSub, charge|null].
     *
     * Recusa do gateway (success=false na assinatura ou na cobrança, ou
     * cobrança já recusada/cancelada) vira GatewayIntegrationException. Se a
     * recorrência chegou a ser criada e a cobrança falhou, ela é cancelada
     * antes do fallback — senão sobraria uma recorrência órfã cobrando.
     */
    private function callGateway(
        PaymentGatewayInterface $gateway,
        Entity $entity,
        Plan $plan,
        Subscription $subscription,
        Invoice $invoice,
        BillingCycle $cycle,
        CarbonImmutable $firstDueDate,
        string $idempotencyKey,
        string $correlationId,
        ?CheckoutPayment $checkout = null,
    ): array {
        $gateway = $gateway->withContext(new GatewayCallContext($correlationId, (string) $entity->id));

        $customerId = $gateway->upsertCustomer(BillingCustomer::customer($entity));

        // A recorrência leva o id da nossa assinatura (externalReference no
        // Asaas): se a resposta se perder, dá para achá-la e desfazê-la.
        try {
            $externalSub = $gateway->createSubscription(new CreateSubscriptionDTO(
                entityId: (string) $entity->id,
                subscriptionId: (string) $subscription->id,
                planId: (string) $plan->id,
                customerId: $customerId,
                amount: (float) $subscription->amount,
                currency: 'BRL',
                interval: $cycle->intervalName(),
                intervalCount: $cycle->intervalCount(),
                description: "Assinatura {$plan->name}",
                metadata: BillingCustomer::chargeMetadata($entity),
                firstDueDate: $firstDueDate->toDateString(),
            ));
        } catch (GatewayIntegrationException $e) {
            // Timeout: o gateway pode ter criado a recorrência mesmo assim.
            // Limite da API (a chamada nem saiu): nada a desfazer.
            if (! $e->isRateLimit()) {
                $this->undoUnconfirmedRecurrence($gateway, $subscription, $e->getMessage());
            }

            throw $e;
        }

        if (! $externalSub->success) {
            // 5xx (ou sem status): a criação pode ter acontecido lá. 4xx não.
            if ($externalSub->httpStatus === null || $externalSub->httpStatus >= 500) {
                $this->undoUnconfirmedRecurrence($gateway, $subscription, (string) $externalSub->errorMessage);
            }

            throw new GatewayIntegrationException(
                __('manager_subscriptions.errors.gateway_subscription_failed', [
                    'gateway' => $gateway->code(),
                    'error'   => mb_substr((string) $externalSub->errorMessage, 0, 500),
                ]),
                'gateway_unavailable',
            );
        }

        // A assinatura do gateway já emitiu a 1ª cobrança (Asaas): uma
        // cobrança avulsa aqui seria um segundo boleto do mesmo período.
        if ($gateway->subscriptionIssuesFirstCharge() && filled($externalSub->externalSubscriptionId)) {
            if ($checkout !== null) {
                $checkout->outcome->usedLink = ! $gateway->supportsTransparent($checkout->method->value);
            }

            return [$customerId, $externalSub, null];
        }

        if ($checkout?->isCard() && $checkout->card !== null && $gateway->supportsTransparent($checkout->method->value)) {
            return [$customerId, $externalSub, $this->chargeFirstCard($gateway, $entity, $plan, $subscription, $invoice, $cycle, $customerId, $externalSub, $idempotencyKey, $checkout)];
        }

        if ($checkout !== null) {
            $checkout->outcome->usedLink = ! $gateway->supportsTransparent($checkout->method->value);
        }

        $charge = $gateway->createCharge(new CreateChargeDTO(
            entityId: (string) $entity->id,
            invoiceId: (string) $invoice->id,
            subscriptionId: (string) $subscription->id,
            customerId: $customerId,
            amount: (float) $invoice->amount,
            currency: $invoice->currency,
            description: "Fatura {$invoice->reference}",
            dueDate: $firstDueDate->toDateString(),
            // Pix/boleto escolhido no checkout (cartão sem transparente: a forma padrão, com link).
            paymentMethod: $checkout && ! $checkout->isCard() ? $checkout->method->value : null,
            idempotencyKey: $idempotencyKey,
            metadata: BillingCustomer::chargeMetadata($entity),
        ));

        if (! $charge->success || PaymentStatus::fromGatewayStatus($charge->status)->isUnusable()) {
            $this->cancelOrphanRecurrence($gateway, $entity, $subscription, $externalSub);

            throw $charge->success
                ? new GatewayIntegrationException(
                    __('manager_subscriptions.errors.gateway_charge_declined', [
                        'gateway' => $gateway->code(),
                        'status'  => (string) $charge->status,
                    ]),
                    'declined',
                )
                : GatewayIntegrationException::fromHttpStatus($gateway->code(), (int) $charge->errorCode, (string) $charge->errorMessage);
        }

        return [$customerId, $externalSub, $charge];
    }

    /**
     * 1ª cobrança no cartão (checkout transparente): token do SDK, parcelas e
     * cartão guardado para a renovação. Recusa → TRIGGER_CARD_DECLINED (sem
     * fallback), com a recorrência eventualmente criada desfeita.
     */
    private function chargeFirstCard(
        PaymentGatewayInterface $gateway,
        Entity $entity,
        Plan $plan,
        Subscription $subscription,
        Invoice $invoice,
        BillingCycle $cycle,
        string $customerId,
        CreateSubscriptionResultDTO $externalSub,
        string $idempotencyKey,
        CheckoutPayment $checkout,
    ): CreateChargeResultDTO {
        $charge = $gateway->chargeCard(new CardChargeDTO(
            entityId: (string) $entity->id,
            invoiceId: (string) $invoice->id,
            subscriptionId: (string) $subscription->id,
            customerId: $customerId,
            amount: (float) $invoice->amount,
            currency: $invoice->currency,
            description: "Assinatura {$plan->name}",
            payer: BillingCustomer::customer($entity),
            cardToken: $checkout->card->token,
            installments: $checkout->card->installments,
            saveCard: true,
            idempotencyKey: $idempotencyKey,
            metadata: ['cycle_months' => $cycle->months()],
            paymentMethodId: $checkout->card->paymentMethodId,
            issuerId: $checkout->card->issuerId,
        ));

        $checkout->outcome->nextAction = $charge->nextAction;
        $checkout->outcome->savedCard  = $charge->savedCard;

        if (! $charge->success || PaymentStatus::fromGatewayStatus($charge->status)->isUnusable()) {
            $this->cancelOrphanRecurrence($gateway, $entity, $subscription, $externalSub);

            $checkout->outcome->declineMessage = $charge->success ? $charge->errorMessage : null;

            throw new GatewayIntegrationException(
                $charge->success
                    ? (string) ($charge->errorMessage ?: __('manager_subscriptions.errors.gateway_charge_declined', ['gateway' => $gateway->code(), 'status' => (string) $charge->status]))
                    : "[{$gateway->code()}] " . mb_substr((string) $charge->errorMessage, 0, 500),
                $charge->success || ChargeIdempotency::isDefinitive($charge->errorCode) ? self::TRIGGER_CARD_DECLINED : 'gateway_unavailable',
            );
        }

        return $charge;
    }

    /**
     * A criação da recorrência falhou sem resposta confiável (timeout/5xx):
     * procura no gateway pela nossa referência e cancela o que tiver sido
     * criado. Sem como confirmar (gateway fora do ar), a tentativa fica
     * marcada (orphan_check pendente), não segue para o fallback — seria uma
     * segunda cobrança — e a próxima contratação da empresa só sai depois da
     * confirmação (ensureNoPendingOrphanRecurrence).
     */
    private function undoUnconfirmedRecurrence(PaymentGatewayInterface $gateway, Subscription $subscription, string $error): void
    {
        if (! $gateway instanceof QueriesGatewayRecurrences) {
            return;
        }

        if ($this->cancelRecurrencesByReference($gateway, $subscription)) {
            return;
        }

        $subscription->forceFill([
            'gateway_payload' => [
                ...($subscription->gateway_payload ?? []),
                'orphan_check' => ['gateway' => $gateway->code(), 'status' => 'pending', 'at' => now()->toIso8601String()],
            ],
        ])->save();

        Log::critical('BillingOrchestrator: a criação da recorrência falhou sem resposta e não foi possível confirmar no gateway se ela existe. Conferir pela referência e cancelar manualmente.', [
            'gateway'            => $gateway->code(),
            'external_reference' => $subscription->id,
            'subscription_id'    => $subscription->id,
            'error'              => mb_substr($error, 0, 500),
        ]);

        throw new GatewayIntegrationException(
            __('manager_subscriptions.errors.gateway_recurrence_unconfirmed', ['gateway' => $gateway->code()]),
            self::TRIGGER_RECURRENCE_UNCONFIRMED,
        );
    }

    /**
     * Tentativa anterior da empresa cuja recorrência não pôde ser conferida:
     * confere de novo antes de criar outra. Sem confirmação, recusa a nova
     * contratação — senão o cliente ficaria com duas recorrências.
     */
    private function ensureNoPendingOrphanRecurrence(Entity $entity, string $correlationId): void
    {
        Subscription::query()
            ->forEntity((string) $entity->id)
            ->where('cancelled_reason', SubscriptionCancelledReason::ActivationFailed->value)
            ->get()
            ->filter(fn (Subscription $attempt) => data_get($attempt->gateway_payload, 'orphan_check.status') === 'pending')
            ->each(function (Subscription $attempt) use ($entity, $correlationId): void {
                $code    = (string) data_get($attempt->gateway_payload, 'orphan_check.gateway');
                $gateway = $this->gatewayRegistry->get($code)->withContext(new GatewayCallContext($correlationId, (string) $entity->id));

                if (! $gateway instanceof QueriesGatewayRecurrences || ! $this->cancelRecurrencesByReference($gateway, $attempt)) {
                    throw ValidationException::withMessages([
                        'gateway' => __('manager_subscriptions.errors.previous_attempt_unconfirmed', ['gateway' => $code]),
                    ]);
                }

                $attempt->update(['gateway_payload' => [
                    ...($attempt->gateway_payload ?? []),
                    'orphan_check' => ['gateway' => $code, 'status' => 'cleared', 'at' => now()->toIso8601String()],
                ]]);
            });
    }

    /**
     * Cancela no gateway toda recorrência criada com a referência da
     * assinatura. True = confirmado que nenhuma ficou cobrando.
     */
    private function cancelRecurrencesByReference(PaymentGatewayInterface&QueriesGatewayRecurrences $gateway, Subscription $subscription): bool
    {
        try {
            $ids = $gateway->findRecurrenceIdsByReference((string) $subscription->id);

            foreach ($ids as $externalId) {
                Subscription::rememberRecurrenceCancelledByUs((string) $subscription->id, (string) $externalId);

                $result = $gateway->cancelSubscription(new CancelSubscriptionDTO(
                    entityId: (string) $subscription->entity_id,
                    subscriptionId: (string) $subscription->id,
                    externalSubscriptionId: $externalId,
                ));

                if (! $result->success) {
                    return false;
                }
            }
        } catch (Throwable $e) {
            Log::warning('BillingOrchestrator: falha ao conferir a recorrência pela referência.', [
                'gateway'         => $gateway->code(),
                'subscription_id' => $subscription->id,
                'error'           => $e->getMessage(),
            ]);

            return false;
        }

        if ($ids !== []) {
            Log::warning('BillingOrchestrator: recorrência criada apesar da falha na resposta do gateway foi cancelada.', [
                'gateway'                  => $gateway->code(),
                'subscription_id'          => $subscription->id,
                'gateway_subscription_ids' => $ids,
            ]);
        }

        return true;
    }

    /** Desfaz a recorrência criada quando a 1ª cobrança não pôde ser emitida. */
    private function cancelOrphanRecurrence(
        PaymentGatewayInterface $gateway,
        Entity $entity,
        Subscription $subscription,
        CreateSubscriptionResultDTO $externalSub,
    ): void {
        if (blank($externalSub->externalSubscriptionId)) {
            return;
        }

        try {
            Subscription::rememberRecurrenceCancelledByUs((string) $subscription->id, (string) $externalSub->externalSubscriptionId);

            $result = $gateway->cancelSubscription(new CancelSubscriptionDTO(
                entityId: (string) $entity->id,
                subscriptionId: (string) $subscription->id,
                externalSubscriptionId: (string) $externalSub->externalSubscriptionId,
            ));

            if ($result->success) {
                return;
            }

            $error = $result->errorMessage;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        Log::critical('BillingOrchestrator: recorrência criada no gateway sem 1ª cobrança não pôde ser cancelada. Cancelar manualmente.', [
            'gateway'                 => $gateway->code(),
            'gateway_subscription_id' => $externalSub->externalSubscriptionId,
            'subscription_id'         => $subscription->id,
            'error'                   => $error,
        ]);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Fase 3 — Confirmação no DB
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Grava o resultado do gateway e põe a nova no lugar das vigentes. Se a
     * nova já não é a pendente da empresa (alteração concorrente), ela fica
     * encerrada com os ids do gateway (para desfazer a recorrência) e nada é
     * substituído.
     *
     * @return array{0: Subscription, 1: Collection<int, Subscription>, 2: bool}
     */
    private function persistActivationResult(
        Entity $entity,
        Subscription $subscription,
        Invoice $invoice,
        PaymentGatewayInterface $gateway,
        string $customerId,
        CreateSubscriptionResultDTO $externalSub,
        ?CreateChargeResultDTO $charge,
        CarbonImmutable $firstDueDate,
        string $correlationId,
        ?CheckoutPayment $checkout = null,
    ): array {
        return DB::transaction(function () use (
            $entity,
            $subscription,
            $invoice,
            $gateway,
            $customerId,
            $externalSub,
            $charge,
            $firstDueDate,
            $correlationId,
            $checkout
        ): array {
            // Mesma ordem de travas do SubscriptionManagementService: empresa,
            // depois as assinaturas — duas ativações simultâneas não deixam
            // duas vigentes.
            Entity::query()->whereKey($entity->id)->lockForUpdate()->first();
            $subscription = Subscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();

            $gatewayCode = $gateway->code();
            $gatewayId   = Gateway::where('code', $gatewayCode)->value('id');
            $payment     = $charge ? $this->recordCharge($subscription, $invoice, $charge, $gatewayCode, $gatewayId, $correlationId, $checkout) : null;

            if ($this->isSuperseded($subscription)) {
                return $this->persistSuperseded($subscription, $invoice->refresh(), $payment, $charge, $gatewayCode, $customerId, $externalSub, $correlationId);
            }

            $invoice = $invoice->refresh();

            // Sem cobrança avulsa (Asaas), o webhook da 1ª parcela liga a
            // fatura — e pode ter chegado antes deste commit: não sobrescreve.
            // O link da 1ª cobrança, quando a resposta o traz, já fica na
            // fatura: "Pagar agora" logo após a contratação.
            $invoice->update(array_filter([
                'gateway_id'               => $gatewayId,
                'gateway_code'             => $gatewayCode,
                'external_invoice_id'      => $charge?->externalPaymentId ?: null,
                'external_subscription_id' => $externalSub->externalSubscriptionId ?: null,
                'status'                   => $invoice->status === InvoiceStatus::Draft ? InvoiceStatus::Pending->value : null,
                'raw_gateway_payload'      => $charge ? $this->sanitize($charge->rawResponse ?? []) : null,
                'payment_url'              => $charge?->paymentUrl ?: null,
                // Forma da cobrança emitida (checkout transparente).
                'payment_method' => $checkout && ! $checkout->outcome->usedLink ? $checkout->method->value : null,
            ], fn ($value) => $value !== null));

            // A cobrança foi emitida: a nova passa a dar acesso até o fim do
            // dia do vencimento (gateway preenchido), enquanto aguarda o 1º
            // pagamento.
            $subscription->update([
                'gateway'                 => $gatewayCode,
                'pinned_gateway'          => $gatewayCode,
                'gateway_customer_id'     => $customerId,
                'gateway_subscription_id' => $externalSub->externalSubscriptionId ?: null,
                'gateway_payload'         => $this->sanitize($externalSub->rawResponse ?? []) + $this->cardPayload($charge, $checkout),
                'billing_state'           => $subscription->hasBeenPaid() ? $subscription->billing_state : 'pending_activation',
                'last_billing_error'      => null,
                'idempotency_key'         => $invoice->idempotency_key,
                'correlation_id'          => $correlationId,
                ...$this->savedCardColumns($checkout),
            ]);

            // D5 vale uma vez: até quando a contratação dá acesso sem pagar.
            $previous = Subscription::query()
                ->forEntity((string) $entity->id)
                ->inForce()
                ->withoutFailedActivations()
                ->whereKeyNot($subscription->id)
                ->where('created_at', '<=', $subscription->created_at)
                ->get();

            $subscription->update(['first_charge_grace_ends_at' => $this->firstChargeGraceEnd($subscription, $previous, $firstDueDate)]);

            // Só agora (sucesso no gateway) a nova substitui as vigentes da
            // empresa — trial, ativa ou em atraso — e registra a troca. O
            // checkout da clínica nunca chega aqui com um plano pago e vigente:
            // a troca dele é upgrade/downgrade (PlanChangeService), que não
            // cancela o pago antes de o novo ser pago.
            $replaced = $this->cycles->replacePrevious($subscription, $correlationId, 'activation', alwaysRecord: true);

            $this->financialEventService->record(
                eventType: BillingEventType::InvoiceCreated,
                entityId: (string) $entity->id,
                subscription: $subscription,
                invoice: $invoice,
                amount: (float) $invoice->amount,
                currency: $invoice->currency,
                correlationId: $correlationId,
                source: 'activation',
            );

            $paymentStatus = $charge ? PaymentStatus::fromGatewayStatus($charge->status) : PaymentStatus::Pending;

            // Pago na hora (cartão): ativa já, como o webhook faria.
            if ($paymentStatus === PaymentStatus::Paid) {
                $replaced = $replaced->merge($this->cycles->confirmPayment($subscription, $invoice->refresh(), $payment, $firstDueDate, $correlationId, 'activation'))->unique('id')->values();
            }

            $this->billingLogService->log(
                level: 'info',
                message: 'Cobrança automática emitida no gateway.',
                context: [
                    'gateway'            => $gatewayCode,
                    'first_due_date'     => $firstDueDate->toDateString(),
                    'payment_status'     => $paymentStatus->value,
                    'charge_by_gateway'  => $charge === null,
                    'replaced_count'     => $replaced->count(),
                    'gateway_recurrence' => filled($externalSub->externalSubscriptionId),
                ],
                entityId: (string) $entity->id,
                subscription: $subscription,
                invoice: $invoice,
                payment: $payment,
                gatewayCode: $gatewayCode,
                correlationId: $correlationId,
            );

            return [$subscription->fresh(['plan', 'currentInvoice']), $replaced, false];
        });
    }

    /**
     * D5 (acesso até o fim do dia do 1º vencimento sem ter pago) vale UMA vez:
     * com outra contratação não paga da clínica pendente ou encerrada/expirada
     * nos últimos billing.checkout.first_charge_grace_cooldown_days dias, a
     * nova não estende o acesso — no máximo mantém o que a vigente anterior
     * ainda dava (trial ou a contratação pendente substituída); sem isso, só
     * depois de paga. Cadastro com contratação (start_mode=checkout) segue a
     * mesma regra.
     *
     * @param Collection<int, Subscription> $previous vigentes anteriores da clínica
     */
    private function firstChargeGraceEnd(Subscription $subscription, Collection $previous, CarbonImmutable $firstDueDate): CarbonImmutable
    {
        // Sem direito ao D5: no máximo o acesso que a anterior não paga (trial
        // ou contratação pendente substituída) ainda dava — nunca estende.
        $inherited = $previous
            ->reject(fn (Subscription $old) => $old->hasBeenPaid())
            ->map(fn (Subscription $old) => $old->status === SubscriptionStatus::Trial ? $old->trial_ends_at : $old->firstChargeAccessEndsAt())
            ->filter(fn ($end) => $end !== null && $end->isFuture())
            ->map(fn ($end) => CarbonImmutable::instance($end))
            ->sortDesc()
            ->first();

        $since       = now()->subDays(max(0, (int) config('billing.checkout.first_charge_grace_cooldown_days', 30)));
        $usedGraceOf = Subscription::query()
            ->forEntity((string) $subscription->entity_id)
            ->withoutFailedActivations()
            ->whereKeyNot($subscription->id)
            ->where('billing_mode', SubscriptionBillingMode::Gateway->value)
            ->whereNull('last_payment_at')
            ->whereNotNull('gateway')
            ->where('gateway', '!=', '')
            ->where(fn ($q) => $q->where('status', SubscriptionStatus::PastDue->value)
                ->orWhere(fn ($ended) => $ended->whereIn('status', [SubscriptionStatus::Cancelled->value, SubscriptionStatus::Expired->value])
                    ->whereRaw('COALESCE(cancelled_at, ends_at, updated_at) >= ?', [$since])))
            ->exists();

        if (! $usedGraceOf) {
            return $firstDueDate->endOfDay();
        }

        return $inherited ?? CarbonImmutable::now()->subSecond();
    }

    /**
     * A nova deixou de ser a pendente da empresa: foi encerrada por outra
     * alteração (cortesia, trial ou outra contratação substituíram as
     * vigentes) ou existe uma mais recente vigente. Mesmo desempate de
     * currentFirst.
     */
    private function isSuperseded(Subscription $subscription): bool
    {
        if (! in_array($subscription->status, [SubscriptionStatus::PastDue, SubscriptionStatus::Active], true)) {
            return true;
        }

        return Subscription::query()
            ->forEntity((string) $subscription->entity_id)
            ->inForce()
            ->withoutFailedActivations()
            ->whereKeyNot($subscription->id)
            ->where(fn ($q) => $q->where('created_at', '>', $subscription->created_at)
                ->orWhere(fn ($tie) => $tie->where('created_at', $subscription->created_at)
                    ->where('id', '>', $subscription->id)))
            ->exists();
    }

    /**
     * Encerra a contratação que perdeu a vez, guardando os ids do gateway
     * (a recorrência é desfeita depois do commit) e a fatura cancelada. A
     * cobrança avulsa já emitida não tem cancelamento pela API comum: fica o
     * alerta para o time SaaS (se for paga, o webhook registra sem dar acesso).
     *
     * @return array{0: Subscription, 1: Collection<int, Subscription>, 2: bool}
     */
    private function persistSuperseded(
        Subscription $subscription,
        Invoice $invoice,
        ?Payment $payment,
        ?CreateChargeResultDTO $charge,
        string $gatewayCode,
        string $customerId,
        CreateSubscriptionResultDTO $externalSub,
        string $correlationId,
    ): array {
        $subscription->update([
            'status'                  => SubscriptionStatus::Cancelled,
            'cancelled_at'            => $subscription->cancelled_at ?? now(),
            'cancelled_reason'        => $subscription->cancelled_reason ?? SubscriptionCancelledReason::Replaced->value,
            'billing_state'           => 'cancelled',
            'next_billing_at'         => null,
            'gateway'                 => $gatewayCode,
            'gateway_customer_id'     => $customerId,
            'gateway_subscription_id' => $externalSub->externalSubscriptionId ?: null,
            'gateway_payload'         => $this->sanitize($externalSub->rawResponse ?? []),
            'correlation_id'          => $correlationId,
        ]);

        if ($invoice->status !== InvoiceStatus::Paid) {
            $invoice->update(array_filter([
                'gateway_code'        => $gatewayCode,
                'external_invoice_id' => $charge?->externalPaymentId ?: null,
                'status'              => InvoiceStatus::Cancelled->value,
            ], fn ($value) => $value !== null));
        }

        $chargePaid = $charge && PaymentStatus::fromGatewayStatus($charge->status) === PaymentStatus::Paid;

        $this->billingLogService->log(
            level: $chargePaid ? 'critical' : 'warning',
            message: $chargePaid
                ? 'Contratação substituída durante a ativação teve a 1ª cobrança paga na hora — estornar no gateway.'
                : 'Contratação substituída durante a ativação: recorrência desfeita; cancelar a 1ª cobrança no gateway se ela tiver sido emitida.',
            context: [
                'gateway'                 => $gatewayCode,
                'gateway_subscription_id' => $externalSub->externalSubscriptionId,
                'external_charge_id'      => $charge?->externalPaymentId,
            ],
            entityId: (string) $subscription->entity_id,
            subscription: $subscription,
            invoice: $invoice,
            payment: $payment,
            gatewayCode: $gatewayCode,
            correlationId: $correlationId,
        );

        return [$subscription->fresh(), collect(), true];
    }

    /** Tentativa e pagamento (pendente) da 1ª cobrança avulsa. */
    private function recordCharge(
        Subscription $subscription,
        Invoice $invoice,
        CreateChargeResultDTO $charge,
        string $gatewayCode,
        ?string $gatewayId,
        string $correlationId,
        ?CheckoutPayment $checkout = null,
    ): Payment {
        $attempt = PaymentAttempt::create([
            'entity_id'        => $subscription->entity_id,
            'subscription_id'  => $subscription->id,
            'invoice_id'       => $invoice->id,
            'gateway_id'       => $gatewayId,
            'gateway_code'     => $gatewayCode,
            'attempt_number'   => 1,
            'status'           => PaymentAttemptStatus::Succeeded->value,
            'trigger'          => 'activation',
            'request_payload'  => ['invoice_id' => $invoice->id],
            'response_payload' => $this->sanitize($charge->rawResponse ?? []),
            'started_at'       => now(),
            'finished_at'      => now(),
            'duration_ms'      => 0,
            'idempotency_key'  => $invoice->idempotency_key,
            'correlation_id'   => $correlationId,
        ]);

        // O webhook da cobrança pode ter chegado antes deste commit e já
        // gravado o pagamento: reaproveita (id externo é único por gateway).
        $existing = filled($charge->externalPaymentId)
            ? Payment::query()->where('gateway_code', $gatewayCode)->where('external_payment_id', $charge->externalPaymentId)->first()
            : null;

        // Fica pendente: o pagamento confirmado (aqui ou no webhook) é
        // aplicado pelo SubscriptionCycleService::confirmPayment.
        $payment = $existing ?? Payment::create([
            'entity_id'           => $subscription->entity_id,
            'invoice_id'          => $invoice->id,
            'subscription_id'     => $subscription->id,
            'gateway_id'          => $gatewayId,
            'gateway_code'        => $gatewayCode,
            'external_payment_id' => $charge->externalPaymentId ?: null,
            'status'              => PaymentStatus::Pending->value,
            'amount'              => (float) ($charge->amount ?? $invoice->amount),
            'currency'            => $invoice->currency,
            'raw_gateway_payload' => $this->sanitize($charge->rawResponse ?? []),
            'correlation_id'      => $correlationId,
            'idempotency_key'     => 'payment-' . $invoice->idempotency_key,
            'payment_method'      => $checkout && ! $checkout->outcome->usedLink ? $checkout->method->value : null,
            'metadata'            => array_filter([
                'source'       => 'activation',
                'installments' => $checkout?->isCard() ? $checkout->card?->installments : null,
            ]),
        ]);

        $attempt->update(['payment_id' => $payment->id]);

        return $payment;
    }

    /**
     * Falha total na ativação (gateway e fallback): a tentativa é encerrada
     * — sem acesso, fora do MRR e das vigentes — e a assinatura anterior
     * segue intacta.
     */
    private function persistActivationFailure(
        Subscription $subscription,
        Invoice $invoice,
        string $errorMessage,
        string $correlationId,
    ): void {
        DB::transaction(function () use ($subscription, $invoice, $errorMessage, $correlationId): void {
            $subscription->update([
                'status'             => SubscriptionStatus::Cancelled,
                'cancelled_at'       => now(),
                'cancelled_reason'   => SubscriptionCancelledReason::ActivationFailed->value,
                'billing_state'      => 'error',
                'last_billing_error' => mb_substr($errorMessage, 0, 500),
                'ends_at'            => now(),
                'next_billing_at'    => null,
                'correlation_id'     => $correlationId,
            ]);

            $invoice->update(['status' => InvoiceStatus::Failed->value]);
        });

        $this->billingLogService->log(
            level: 'error',
            message: 'Falha total na ativação — gateway e fallback falharam.',
            context: ['error' => mb_substr($errorMessage, 0, 500)],
            entityId: (string) $subscription->entity_id,
            subscription: $subscription,
            invoice: $invoice,
            correlationId: $correlationId,
        );
    }

    // ────────────────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Colunas do cartão guardado no gateway (só id, bandeira e 4 últimos).
     *
     * @return array<string, mixed>
     */
    private function savedCardColumns(?CheckoutPayment $checkout): array
    {
        if ($checkout === null || $checkout->outcome->usedLink) {
            return [];
        }

        $card = $checkout->outcome->savedCard;

        return [
            'payment_method'    => $checkout->method->value,
            'gateway_card_id'   => $card?->id,
            'card_brand'        => $card?->brand,
            'card_last4'        => $card?->last4,
            'card_installments' => $checkout->isCard() ? max(1, (int) $checkout->card?->installments) : null,
        ];
    }

    /**
     * Referências da 1ª cobrança no cartão que a renovação reenvia ao gateway
     * (Pagar.me payment_origin.charge_id; Mercado Pago reference.id e
     * network_transaction_id do Automatic Payments).
     *
     * @return array<string, mixed>
     */
    private function cardPayload(?CreateChargeResultDTO $charge, ?CheckoutPayment $checkout): array
    {
        if (! $checkout?->isCard() || $charge === null || $checkout->outcome->savedCard === null) {
            return [];
        }

        return ['card' => array_filter([
            'origin_payment_id'      => $charge->externalPaymentId,
            'network_transaction_id' => data_get($charge->rawResponse, 'easyeye_card.network_transaction_id'),
            'payment_method_id'      => data_get($charge->rawResponse, 'easyeye_card.payment_method_id'),
            'sequence'               => 1,
        ], fn ($value) => $value !== null)];
    }

    private function buildInvoiceReference(string $subscriptionId): string
    {
        // Timestamp + final do UUID: o início de um UUID v7 é o próprio
        // timestamp (duas ativações da empresa no mesmo segundo colidiam).
        return 'INV-' . now()->format('YmdHis') . '-' . strtoupper(substr($subscriptionId, -8));
    }

    private function sanitize(array $payload): array
    {
        return PayloadSanitizer::clean($payload);
    }
}
