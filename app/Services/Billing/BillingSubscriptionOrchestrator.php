<?php

namespace App\Services\Billing;

use App\Contracts\Billing\PaymentGatewayInterface;
use App\DTOs\Billing\{CreateChargeDTO, CreateSubscriptionDTO, CustomerDTO, GatewayCallContext};
use App\DTOs\Billing\{CreateChargeResultDTO, CreateSubscriptionResultDTO};
use App\Enums\Billing\{BillingEventType, InvoiceStatus, PaymentAttemptStatus, PaymentStatus};
use App\Enums\{BillingCycle, SubscriptionStatus};
use App\Exceptions\Billing\GatewayIntegrationException;
use App\Models\Billing\Gateway;
use App\Models\Billing\{Invoice, Payment, PaymentAttempt, SubscriptionChange};
use App\Models\{Entity, Plan, Subscription};
use Illuminate\Support\Facades\{DB, Log};

class BillingSubscriptionOrchestrator
{
    public function __construct(
        private readonly GatewayResolver $gatewayResolver,
        private readonly GatewayRegistry $gatewayRegistry,
        private readonly FallbackGatewayService $fallbackService,
        private readonly CircuitBreakerService $circuitBreaker,
        private readonly CorrelationIdService $correlationIdService,
        private readonly BillingLogService $billingLogService,
        private readonly FinancialEventService $financialEventService,
    ) {
    }

    // ────────────────────────────────────────────────────────────────────────
    // Ativação principal
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Ativa uma assinatura paga para o tenant, integrando com o gateway selecionado.
     *
     * Fluxo separado em 3 fases para evitar transações DB envolvendo HTTP:
     *   Fase 1 — DB: cria registros pendentes (subscription, invoice)
     *   Fase 2 — HTTP: chama o gateway (fora de transação)
     *   Fase 3 — DB: confirma ou marca como falha
     */
    public function activateWithGateway(
        Entity $entity,
        Plan $plan,
        BillingCycle $cycle = BillingCycle::Monthly,
        ?string $requestedGateway = null,
    ): Subscription {
        $correlationId = $this->correlationIdService->resolve();

        // ── Fase 1: registros pendentes ──────────────────────────────────────
        [$subscription, $invoice, $idempotencyKey] = $this->persistPendingActivation(
            entity: $entity,
            plan: $plan,
            cycle: $cycle,
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
                requestedGateway: $requestedGateway,
                idempotencyKey: $idempotencyKey,
                correlationId: $correlationId,
            );
        } catch (GatewayIntegrationException $e) {
            $this->persistActivationFailure($subscription, $invoice, $e->getMessage(), $correlationId);

            throw $e;
        }

        // ── Fase 3: confirmar no DB ──────────────────────────────────────────
        return $this->persistActivationResult(
            entity: $entity,
            subscription: $subscription,
            invoice: $invoice,
            plan: $plan,
            gateway: $gateway,
            customerId: $customerId,
            externalSub: $externalSub,
            charge: $charge,
            correlationId: $correlationId,
        );
    }

    // ────────────────────────────────────────────────────────────────────────
    // Fase 1 — Persistência pendente
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Cria subscription (PastDue) + invoice (Draft) em transação rápida.
     * Cancela assinatura anterior individualmente para disparar observers.
     *
     * @return array{0: Subscription, 1: Invoice, 2: string}
     */
    private function persistPendingActivation(
        Entity $entity,
        Plan $plan,
        BillingCycle $cycle,
        string $correlationId,
    ): array {
        return DB::transaction(function () use ($entity, $plan, $cycle, $correlationId): array {
            // Cancela assinaturas ativas individualmente (dispara SubscriptionObserver)
            Subscription::query()
                ->forEntity((string) $entity->id)
                ->whereIn('status', [SubscriptionStatus::Trial->value, SubscriptionStatus::Active->value])
                ->get()
                ->each(function (Subscription $s) use ($correlationId): void {
                    $s->update([
                        'status'         => SubscriptionStatus::Cancelled,
                        'cancelled_at'   => now(),
                        'correlation_id' => $correlationId,
                    ]);
                });

            // Cria nova subscription como PastDue (aguardando confirmação do gateway)
            $subscription = Subscription::create([
                'entity_id'       => $entity->id,
                'plan_id'         => $plan->id,
                'status'          => SubscriptionStatus::PastDue,
                'billing_state'   => 'pending_activation',
                'starts_at'       => now(),
                'ends_at'         => $cycle->addToNow(),
                'next_billing_at' => $cycle->addToNow(),
                'correlation_id'  => $correlationId,
            ]);

            $idempotencyKey = 'charge-1-' . $subscription->id;

            // Cria invoice como Draft
            $invoice = Invoice::create([
                'entity_id'       => $entity->id,
                'subscription_id' => $subscription->id,
                'plan_id'         => $plan->id,
                'reference'       => $this->buildInvoiceReference($subscription->id),
                'amount'          => (float) $plan->price,
                'currency'        => 'BRL',
                'status'          => InvoiceStatus::Draft,
                'due_at'          => now(),
                'billing_reason'  => 'subscription_create',
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
     * Executa as 3 chamadas ao gateway:
     *   1. upsertCustomer
     *   2. createSubscription
     *   3. createCharge (com idempotency_key)
     *
     * Em falha, tenta o fallback gateway configurado.
     * Se ambos falharem, propaga a exceção.
     *
     * @return array{0: PaymentGatewayInterface, 1: string, 2: CreateSubscriptionResultDTO, 3: CreateChargeResultDTO}
     */
    private function executeGatewayFlow(
        Entity $entity,
        Plan $plan,
        Subscription $subscription,
        Invoice $invoice,
        BillingCycle $cycle,
        ?string $requestedGateway,
        string $idempotencyKey,
        string $correlationId,
    ): array {
        $gateway = $this->gatewayResolver->resolveForNewPurchase($entity, $plan, $requestedGateway);

        // Verifica circuit breaker antes de tentar o gateway
        if ($this->circuitBreaker->isOpen($gateway->code(), (string) $entity->id)) {
            return $this->tryFallbackGateway(
                entity: $entity,
                plan: $plan,
                subscription: $subscription,
                invoice: $invoice,
                cycle: $cycle,
                primaryGatewayCode: $gateway->code(),
                triggerType: 'gateway_unavailable',
                idempotencyKey: $idempotencyKey,
                correlationId: $correlationId,
                originalError: "Circuit aberto para gateway [{$gateway->code()}].",
            );
        }

        try {
            $result = $this->callGateway($gateway, $entity, $plan, $subscription, $invoice, $cycle, $idempotencyKey, $correlationId);
            $this->circuitBreaker->recordSuccess($gateway->code());

            return [$gateway, ...$result];
        } catch (GatewayIntegrationException $e) {
            $this->circuitBreaker->recordFailure($gateway->code(), $e->getTriggerType());

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
                primaryGatewayCode: $gateway->code(),
                triggerType: $e->getTriggerType(),
                idempotencyKey: $idempotencyKey,
                correlationId: $correlationId,
                originalError: $e->getMessage(),
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
        string $primaryGatewayCode,
        string $triggerType,
        string $idempotencyKey,
        string $correlationId,
        string $originalError,
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
            $result = $this->callGateway($fallbackGateway, $entity, $plan, $subscription, $invoice, $cycle, $idempotencyKey, $correlationId);
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
     * Executa as 3 chamadas ao gateway na ordem correta.
     * Retorna [customerId, externalSub, charge].
     */
    private function callGateway(
        PaymentGatewayInterface $gateway,
        Entity $entity,
        Plan $plan,
        Subscription $subscription,
        Invoice $invoice,
        BillingCycle $cycle,
        string $idempotencyKey,
        string $correlationId,
    ): array {
        $gateway = $gateway->withContext(new GatewayCallContext($correlationId, (string) $entity->id));

        $customerId = $gateway->upsertCustomer(new CustomerDTO(
            entityId: (string) $entity->id,
            name: (string) $entity->name,
            email: $entity->email,
            document: $entity->national_registration,
            phone: $entity->cellphone ?: $entity->telephone,
            externalReference: (string) $entity->id,
            metadata: [
                'customer_name' => $entity->name,
                'email'         => $entity->email,
                'document'      => $entity->national_registration,
                'phone'         => $entity->cellphone ?: $entity->telephone,
            ],
        ));

        $externalSub = $gateway->createSubscription(new CreateSubscriptionDTO(
            entityId: (string) $entity->id,
            subscriptionId: (string) $subscription->id,
            planId: (string) $plan->id,
            customerId: $customerId,
            amount: (float) $plan->price,
            currency: 'BRL',
            interval: $cycle->intervalName(),
            intervalCount: $cycle->intervalCount(),
            description: "Assinatura {$plan->name}",
            metadata: [
                'email'         => $entity->email,
                'customer_name' => $entity->name,
                'document'      => $entity->national_registration,
                'phone'         => $entity->cellphone ?: $entity->telephone,
            ],
        ));

        $charge = $gateway->createCharge(new CreateChargeDTO(
            entityId: (string) $entity->id,
            invoiceId: (string) $invoice->id,
            subscriptionId: (string) $subscription->id,
            customerId: $customerId,
            amount: (float) $invoice->amount,
            currency: $invoice->currency,
            description: "Fatura {$invoice->reference}",
            dueDate: now()->addDays(3)->format('Y-m-d'),
            idempotencyKey: $idempotencyKey,
            metadata: [
                'email'         => $entity->email,
                'customer_name' => $entity->name,
                'document'      => $entity->national_registration,
            ],
        ));

        return [$customerId, $externalSub, $charge];
    }

    // ────────────────────────────────────────────────────────────────────────
    // Fase 3 — Confirmação no DB
    // ────────────────────────────────────────────────────────────────────────

    private function persistActivationResult(
        Entity $entity,
        Subscription $subscription,
        Invoice $invoice,
        Plan $plan,
        PaymentGatewayInterface $gateway,
        string $customerId,
        $externalSub,
        $charge,
        string $correlationId,
    ): Subscription {
        return DB::transaction(function () use (
            $entity,
            $subscription,
            $invoice,
            $plan,
            $gateway,
            $customerId,
            $externalSub,
            $charge,
            $correlationId
        ): Subscription {
            $gatewayCode   = $gateway->code();
            $paymentStatus = $this->mapPaymentStatus($charge->status);
            $invoiceStatus = $this->mapInvoiceStatus($charge->status, $charge->success);
            $isPaid        = $paymentStatus === PaymentStatus::Paid;

            // Resolve gateway_id FK para popular nas tabelas
            $gatewayId = Gateway::where('code', $gatewayCode)->value('id');

            // Attempt
            $attempt = PaymentAttempt::create([
                'entity_id'        => $entity->id,
                'subscription_id'  => $subscription->id,
                'invoice_id'       => $invoice->id,
                'gateway_id'       => $gatewayId,
                'gateway_code'     => $gatewayCode,
                'attempt_number'   => 1,
                'status'           => $charge->success ? PaymentAttemptStatus::Succeeded->value : PaymentAttemptStatus::Failed->value,
                'trigger'          => 'activation',
                'request_payload'  => ['invoice_id' => $invoice->id],
                'response_payload' => $this->sanitize($charge->rawResponse),
                'error_code'       => $charge->errorCode,
                'error_message'    => $charge->errorMessage,
                'started_at'       => now(),
                'finished_at'      => now(),
                'duration_ms'      => 0,
                'idempotency_key'  => $invoice->idempotency_key,
                'correlation_id'   => $correlationId,
            ]);

            // Payment
            $payment = Payment::create([
                'entity_id'           => $entity->id,
                'invoice_id'          => $invoice->id,
                'subscription_id'     => $subscription->id,
                'gateway_id'          => $gatewayId,
                'gateway_code'        => $gatewayCode,
                'external_payment_id' => $charge->externalPaymentId,
                'status'              => $paymentStatus->value,
                'amount'              => (float) ($charge->amount ?? $invoice->amount),
                'currency'            => $invoice->currency,
                'paid_at'             => $isPaid ? now() : null,
                'failed_at'           => (! $charge->success) ? now() : null,
                'raw_gateway_payload' => $this->sanitize($charge->rawResponse),
                'correlation_id'      => $correlationId,
                'idempotency_key'     => 'payment-' . $invoice->idempotency_key,
                'metadata'            => ['source' => 'activation'],
            ]);

            $attempt->update(['payment_id' => $payment->id]);

            // Invoice
            $invoice->update([
                'gateway_id'          => $gatewayId,
                'gateway_code'        => $gatewayCode,
                'status'              => $invoiceStatus->value,
                'paid_at'             => $invoiceStatus === InvoiceStatus::Paid ? now() : null,
                'raw_gateway_payload' => $this->sanitize($charge->rawResponse),
            ]);

            // Subscription
            $newStatus    = $isPaid ? SubscriptionStatus::Active : SubscriptionStatus::PastDue;
            $billingState = $isPaid ? 'paid' : 'past_due';

            $subscription->update([
                'status'                  => $newStatus,
                'billing_state'           => $billingState,
                'gateway'                 => $gatewayCode,
                'pinned_gateway'          => $gatewayCode,
                'gateway_customer_id'     => $customerId,
                'gateway_subscription_id' => $externalSub->externalSubscriptionId,
                'gateway_payload'         => $this->sanitize($externalSub->rawResponse),
                'last_billing_error'      => $charge->errorMessage,
                'last_payment_at'         => $isPaid ? now() : null,
                'past_due_at'             => (! $isPaid) ? now() : null,
                'idempotency_key'         => $invoice->idempotency_key,
                'correlation_id'          => $correlationId,
            ]);

            // SubscriptionChange — audit de ativação/troca de plano
            SubscriptionChange::create([
                'entity_id'        => $entity->id,
                'subscription_id'  => $subscription->id,
                'new_plan_id'      => $plan->id,
                'new_gateway_code' => $gatewayCode,
                'change_type'      => 'activation',
                'reason'           => 'Ativação via gateway',
                'effective_at'     => now(),
                'correlation_id'   => $correlationId,
            ]);

            // Financial events
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

            if ($isPaid) {
                $this->financialEventService->record(
                    eventType: BillingEventType::PaymentSucceeded,
                    entityId: (string) $entity->id,
                    subscription: $subscription,
                    invoice: $invoice,
                    payment: $payment,
                    amount: (float) $payment->amount,
                    currency: $payment->currency,
                    correlationId: $correlationId,
                    source: 'activation',
                );

                $this->financialEventService->record(
                    eventType: BillingEventType::InvoicePaid,
                    entityId: (string) $entity->id,
                    subscription: $subscription,
                    invoice: $invoice,
                    payment: $payment,
                    amount: (float) $payment->amount,
                    currency: $payment->currency,
                    correlationId: $correlationId,
                    source: 'activation',
                );

                $this->financialEventService->record(
                    eventType: BillingEventType::SubscriptionActivated,
                    entityId: (string) $entity->id,
                    subscription: $subscription,
                    invoice: $invoice,
                    payment: $payment,
                    amount: (float) $payment->amount,
                    currency: $payment->currency,
                    correlationId: $correlationId,
                    source: 'activation',
                );
            } else {
                $this->financialEventService->record(
                    eventType: BillingEventType::PaymentFailed,
                    entityId: (string) $entity->id,
                    subscription: $subscription,
                    invoice: $invoice,
                    payment: $payment,
                    amount: (float) $payment->amount,
                    currency: $payment->currency,
                    correlationId: $correlationId,
                    source: 'activation',
                );
            }

            $this->billingLogService->log(
                level: 'info',
                message: 'Assinatura ativada via gateway.',
                context: [
                    'gateway'        => $gatewayCode,
                    'invoice_status' => $invoiceStatus->value,
                    'payment_status' => $paymentStatus->value,
                    'paid'           => $isPaid,
                ],
                entityId: (string) $entity->id,
                subscription: $subscription,
                invoice: $invoice,
                payment: $payment,
                attempt: $attempt,
                gatewayCode: $gatewayCode,
                correlationId: $correlationId,
            );

            return $subscription->fresh(['plan', 'currentInvoice']);
        });
    }

    /** Marca a ativação como falha total (quando o gateway e o fallback falham). */
    private function persistActivationFailure(
        Subscription $subscription,
        Invoice $invoice,
        string $errorMessage,
        string $correlationId,
    ): void {
        DB::transaction(function () use ($subscription, $invoice, $errorMessage, $correlationId): void {
            $subscription->update([
                'billing_state'      => 'error',
                'last_billing_error' => mb_substr($errorMessage, 0, 500),
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

    private function mapPaymentStatus(?string $status): PaymentStatus
    {
        return match ($status) {
            'paid', 'succeeded', 'approved' => PaymentStatus::Paid,
            'authorized' => PaymentStatus::Authorized,
            'cancelled', 'canceled' => PaymentStatus::Cancelled,
            'refunded'   => PaymentStatus::Refunded,
            'chargeback' => PaymentStatus::Chargeback,
            'failed', 'error', 'declined', 'refused' => PaymentStatus::Failed,
            default => PaymentStatus::Pending,
        };
    }

    private function mapInvoiceStatus(?string $status, bool $success): InvoiceStatus
    {
        if (! $success) {
            return InvoiceStatus::Failed;
        }

        return match ($status) {
            'paid', 'succeeded', 'approved' => InvoiceStatus::Paid,
            'cancelled', 'canceled' => InvoiceStatus::Cancelled,
            'refunded' => InvoiceStatus::Refunded,
            'failed', 'declined' => InvoiceStatus::Failed,
            'overdue' => InvoiceStatus::Overdue,
            default   => InvoiceStatus::Pending,
        };
    }

    private function buildInvoiceReference(string $subscriptionId): string
    {
        // Usa UUID segment + timestamp para unicidade garantida
        return 'INV-' . now()->format('YmdHis') . '-' . strtoupper(substr($subscriptionId, 0, 8));
    }

    private function sanitize(array $payload): array
    {
        $sensitive = ['card_number', 'cvv', 'card_cvv', 'password', 'secret', 'access_token'];

        array_walk_recursive($payload, function (&$v, $k) use ($sensitive): void {
            if (in_array(strtolower((string) $k), $sensitive, true)) {
                $v = '***';
            }
        });

        return $payload;
    }
}
