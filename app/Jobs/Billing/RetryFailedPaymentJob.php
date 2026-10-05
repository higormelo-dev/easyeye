<?php

namespace App\Jobs\Billing;

use App\Contracts\Billing\PaymentGatewayInterface;
use App\DTOs\Billing\{CreateChargeDTO, GatewayCallContext};
use App\Enums\Billing\{BillingEventType, InvoiceStatus, PaymentStatus};
use App\Enums\{SubscriptionBillingMode, SubscriptionStatus};
use App\Models\Billing\{BillingRetrySchedule, Invoice, Payment};
use App\Models\{Entity, Subscription};
use App\Services\Billing\{BillingLogService, CircuitBreakerService, FinancialEventService, GatewayRegistry};
use App\Support\Billing\{BillingCustomer, PaymentUrl};
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Illuminate\Support\Facades\{DB, Log};
use Throwable;

/**
 * Retries a single failed payment using the BillingRetrySchedule entry.
 *
 * Não está mais no agendador (a nova tentativa da renovação é do
 * subscriptions:renew); disparado à mão, só cobra fatura em falha de
 * cobrança automática vigente com o período ainda não pago.
 */
class RetryFailedPaymentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1; // No queue retries — scheduling is handled by BillingRetrySchedule

    public function __construct(
        public readonly string $retryScheduleId,
    ) {
        $this->onQueue((string) config('billing.webhooks.queue', 'default'));
    }

    public function handle(
        GatewayRegistry $gatewayRegistry,
        CircuitBreakerService $circuitBreaker,
        FinancialEventService $financialEventService,
        BillingLogService $billingLogService,
    ): void {
        $schedule = BillingRetrySchedule::query()
            ->with(['subscription.entity', 'subscription.plan', 'invoice'])
            ->lockForUpdate()
            ->find($this->retryScheduleId);

        if (! $schedule || $schedule->status !== 'pending') {
            return;
        }

        $subscription = $schedule->subscription;
        $invoice      = $schedule->invoice;

        // Agendamento antigo (o agendador não o dispara mais): só cobra a
        // fatura que ainda está em falha, de uma cobrança automática vigente
        // e com o período ainda não pago — nunca assinatura encerrada,
        // substituída ou aguardando conciliação.
        if (! $this->stillChargeable($subscription, $invoice)) {
            $schedule->update([
                'status'         => 'skipped',
                'executed_at'    => now(),
                'result_message' => __('manager_subscriptions.billing_errors.retry_not_applicable'),
            ]);

            return;
        }

        $entity        = $subscription->entity;
        $plan          = $subscription->plan;
        $gatewayCode   = (string) ($schedule->gateway_code ?: $subscription->gateway ?: config('billing.default_gateway'));
        $correlationId = (string) $schedule->correlation_id;

        // Mark as executing to prevent double-processing
        $schedule->update(['status' => 'executed', 'executed_at' => now()]);

        // Circuit breaker check
        if ($circuitBreaker->isOpen($gatewayCode, (string) $entity->id)) {
            $schedule->update([
                'status'         => 'skipped',
                'result_message' => 'Circuit breaker aberto.',
            ]);

            return;
        }

        try {
            $gateway = $gatewayRegistry->get($gatewayCode)->withContext(new GatewayCallContext($correlationId, (string) $entity->id));

            // New idempotency key per attempt to force a fresh charge
            $idempotencyKey = 'retry:' . $invoice->id . ':attempt:' . $schedule->attempt_number;

            $chargeResult = $gateway->createCharge(new CreateChargeDTO(
                entityId: (string) $entity->id,
                invoiceId: (string) $invoice->id,
                subscriptionId: (string) $subscription->id,
                customerId: $this->gatewayCustomerId($gateway, $subscription, $entity),
                amount: (float) $invoice->amount,
                currency: (string) $invoice->currency,
                description: "Retry #{$schedule->attempt_number}: {$plan->name}",
                dueDate: now()->addDays(3)->format('Y-m-d'),
                idempotencyKey: $idempotencyKey,
                metadata: BillingCustomer::chargeMetadata($entity, ['attempt_number' => $schedule->attempt_number]),
            ));

            $circuitBreaker->recordSuccess($gatewayCode, (string) $entity->id);
        } catch (Throwable $e) {
            $circuitBreaker->recordFailure($gatewayCode, 'exception', (string) $entity->id);

            $schedule->update(['result_message' => mb_substr($e->getMessage(), 0, 500)]);

            Log::warning('[RetryFailedPaymentJob] Exceção ao retentar pagamento.', [
                'retry_schedule' => $this->retryScheduleId,
                'error'          => $e->getMessage(),
            ]);

            return;
        }

        if (! $chargeResult->success) {
            $circuitBreaker->recordFailure($gatewayCode, 'declined', (string) $entity->id);
            $schedule->update(['result_message' => mb_substr($chargeResult->errorMessage ?? 'Declined', 0, 500)]);

            $financialEventService->record(
                eventType: BillingEventType::PaymentFailed,
                entityId: (string) $entity->id,
                subscription: $subscription,
                invoice: $invoice,
                amount: (float) $invoice->amount,
                currency: (string) $invoice->currency,
                correlationId: $correlationId,
                source: 'retry_scheduler',
            );

            return;
        }

        // Success: create payment record, update invoice
        DB::transaction(function () use ($subscription, $invoice, $schedule, $chargeResult, $gatewayCode, $correlationId): void {
            Payment::query()->create([
                'entity_id'           => $subscription->entity_id,
                'invoice_id'          => $invoice->id,
                'subscription_id'     => $subscription->id,
                'gateway_code'        => $gatewayCode,
                'external_payment_id' => $chargeResult->externalPaymentId,
                'status'              => PaymentStatus::Pending->value,
                'amount'              => $chargeResult->amount ?? (float) $invoice->amount,
                'currency'            => $invoice->currency,
                'idempotency_key'     => 'retry:' . $invoice->id . ':attempt:' . $schedule->attempt_number,
                'correlation_id'      => $correlationId,
            ]);

            // Link da nova cobrança (só http/https): "Pagar agora" e o e-mail
            // da régua apontam para ela, não para a cobrança que falhou.
            $invoice->update(array_filter([
                'status'              => InvoiceStatus::Pending->value,
                'external_invoice_id' => $chargeResult->externalPaymentId,
                'raw_gateway_payload' => $chargeResult->rawResponse,
                'payment_url'         => PaymentUrl::safe($chargeResult->paymentUrl),
            ], fn ($value) => $value !== null));

            $subscription->update([
                'billing_state'      => 'pending',
                'last_billing_error' => null,
            ]);

            $schedule->update(['result_message' => 'Cobrança enviada com sucesso.']);
        });

        $billingLogService->log(
            level: 'info',
            message: "Retry #{$schedule->attempt_number} enviado com sucesso.",
            context: ['gateway' => $gatewayCode, 'external_id' => $chargeResult->externalPaymentId],
            entityId: (string) $entity->id,
            subscription: $subscription,
            invoice: $invoice,
            gatewayCode: $gatewayCode,
            correlationId: $correlationId,
        );
    }

    /**
     * Cliente no gateway: sem gateway_customer_id, cadastra/reaproveita agora
     * e guarda o id (o id local da empresa não é cliente no gateway).
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

    private function stillChargeable(?Subscription $subscription, ?Invoice $invoice): bool
    {
        if (! $subscription || ! $invoice
            || $subscription->billing_mode !== SubscriptionBillingMode::Gateway
            || ! in_array($subscription->status, [SubscriptionStatus::Active, SubscriptionStatus::PastDue], true)
            || filled($subscription->cancelled_reason)
            || $subscription->needs_billing_reconciliation
            || $invoice->status !== InvoiceStatus::Failed) {
            return false;
        }

        // Período desta fatura já pago (outra cobrança do mesmo ciclo).
        $periodEnd = $invoice->period_start ? $subscription->periodEndFrom($invoice->period_start) : null;

        return ! ($periodEnd && $subscription->ends_at && $subscription->ends_at->greaterThanOrEqualTo($periodEnd));
    }
}
