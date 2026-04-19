<?php

namespace App\Jobs\Billing;

use App\DTOs\Billing\CreateChargeDTO;
use App\Enums\Billing\{BillingEventType, InvoiceStatus, PaymentStatus};
use App\Models\Billing\{BillingRetrySchedule, Payment};
use App\DTOs\Billing\GatewayCallContext;
use App\Services\Billing\{BillingLogService, CircuitBreakerService, FinancialEventService, GatewayRegistry};
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Retries a single failed payment using the BillingRetrySchedule entry.
 *
 * Dispatched by the scheduler for every due pending retry schedule.
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
        $entity       = $subscription->entity;
        $plan         = $subscription->plan;
        $gatewayCode  = (string) ($schedule->gateway_code ?: $subscription->gateway ?: config('billing.default_gateway'));
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
                customerId: (string) ($subscription->gateway_customer_id ?? $entity->id),
                amount: (float) $invoice->amount,
                currency: (string) $invoice->currency,
                description: "Retry #{$schedule->attempt_number}: {$plan->name}",
                dueDate: now()->addDays(3)->format('Y-m-d'),
                idempotencyKey: $idempotencyKey,
                metadata: [
                    'customer_name'  => $entity->name,
                    'email'          => $entity->email,
                    'document'       => $entity->national_registration,
                    'attempt_number' => $schedule->attempt_number,
                ],
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

            $invoice->update([
                'status'              => InvoiceStatus::Pending->value,
                'external_invoice_id' => $chargeResult->externalPaymentId,
                'raw_gateway_payload' => $chargeResult->rawResponse,
            ]);

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
}
