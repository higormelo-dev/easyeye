<?php

namespace App\Jobs\Billing;

use App\DTOs\Billing\CreateChargeDTO;
use App\Enums\Billing\{BillingEventType, InvoiceStatus, PaymentStatus};
use App\Enums\BillingCycle;
use App\Models\Billing\{BillingRetrySchedule, Invoice, Payment};
use App\Models\{Subscription};
use App\DTOs\Billing\GatewayCallContext;
use App\Services\Billing\{BillingLogService, CircuitBreakerService, FinancialEventService, GatewayRegistry};
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Renews a single active subscription by issuing a new charge at the gateway.
 *
 * Dispatched by the scheduler for every subscription whose next_billing_at <= now().
 * Uses the 3-phase pattern: DB (pending) → HTTP (gateway) → DB (confirm).
 */
class RenewSubscriptionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1; // Retry logic is handled via BillingRetrySchedule

    public function __construct(
        public readonly string $subscriptionId,
    ) {
        $this->onQueue((string) config('billing.webhooks.queue', 'default'));
    }

    public function handle(
        GatewayRegistry $gatewayRegistry,
        CircuitBreakerService $circuitBreaker,
        FinancialEventService $financialEventService,
        BillingLogService $billingLogService,
    ): void {
        $subscription = Subscription::query()
            ->with(['entity', 'plan'])
            ->find($this->subscriptionId);

        if (! $subscription) {
            return;
        }

        // Re-check: another job may have already renewed this
        if (! $subscription->next_billing_at || $subscription->next_billing_at->isFuture()) {
            return;
        }

        if ($subscription->status->value !== 'active') {
            return;
        }

        $correlationId = (string) Str::uuid();
        $entity        = $subscription->entity;
        $plan          = $subscription->plan;
        $gatewayCode   = (string) ($subscription->gateway ?: config('billing.default_gateway'));

        // ── Phase 1: DB — create draft invoice ────────────────────────────────
        $invoice = DB::transaction(function () use ($subscription, $entity, $plan, $correlationId, $gatewayCode): Invoice {
            $cycle      = BillingCycle::from($plan->billing_cycle);
            $periodStart = $subscription->next_billing_at->toDateString();
            $periodEnd   = $subscription->next_billing_at->add(
                \DateInterval::createFromDateString($cycle->months() . ' months')
            )->toDateString();

            return Invoice::query()->create([
                'entity_id'       => $entity->id,
                'subscription_id' => $subscription->id,
                'plan_id'         => $plan->id,
                'gateway_code'    => $gatewayCode,
                'reference'       => 'INV-' . strtoupper(Str::random(10)),
                'period_start'    => $periodStart,
                'period_end'      => $periodEnd,
                'due_at'          => now()->addDays(3),
                'amount'          => $plan->price,
                'currency'        => 'BRL',
                'status'          => InvoiceStatus::Pending->value,
                'billing_reason'  => 'renewal',
                'correlation_id'  => $correlationId,
                'idempotency_key' => 'renew:' . $subscription->id . ':' . $subscription->next_billing_at->format('Y-m'),
                'metadata'        => ['source' => 'scheduler'],
            ]);
        });

        // ── Phase 2: HTTP — call gateway ──────────────────────────────────────
        if ($circuitBreaker->isOpen($gatewayCode, (string) $entity->id)) {
            $this->handleChargeFailure($subscription, $invoice, $correlationId, null, 'Circuit breaker aberto.');
            return;
        }

        try {
            $gateway = $gatewayRegistry->get($gatewayCode)->withContext(new GatewayCallContext($correlationId, (string) $entity->id));

            $chargeResult = $gateway->createCharge(new CreateChargeDTO(
                entityId: (string) $entity->id,
                invoiceId: (string) $invoice->id,
                subscriptionId: (string) $subscription->id,
                customerId: (string) ($subscription->gateway_customer_id ?? $entity->id),
                amount: (float) $plan->price,
                currency: 'BRL',
                description: "Renovação: {$plan->name}",
                dueDate: now()->addDays(3)->format('Y-m-d'),
                paymentMethod: $plan->payment_method ?? null,
                idempotencyKey: 'renew:' . $subscription->id . ':' . $subscription->next_billing_at->format('Y-m'),
                metadata: [
                    'customer_name' => $entity->name,
                    'email'         => $entity->email,
                    'document'      => $entity->national_registration,
                ],
            ));

            $circuitBreaker->recordSuccess($gatewayCode, (string) $entity->id);
        } catch (Throwable $e) {
            $circuitBreaker->recordFailure($gatewayCode, 'exception', (string) $entity->id);
            $this->handleChargeFailure($subscription, $invoice, $correlationId, $e->getMessage());
            return;
        }

        if (! $chargeResult->success) {
            $circuitBreaker->recordFailure($gatewayCode, 'declined', (string) $entity->id);
            $this->handleChargeFailure($subscription, $invoice, $correlationId, $chargeResult->errorMessage);
            return;
        }

        // ── Phase 3: DB — confirm success ─────────────────────────────────────
        $cycle = BillingCycle::from($plan->billing_cycle);

        DB::transaction(function () use ($subscription, $invoice, $chargeResult, $correlationId, $gatewayCode, $cycle): void {
            $payment = Payment::query()->create([
                'entity_id'           => $subscription->entity_id,
                'invoice_id'          => $invoice->id,
                'subscription_id'     => $subscription->id,
                'gateway_code'        => $gatewayCode,
                'external_payment_id' => $chargeResult->externalPaymentId,
                'status'              => PaymentStatus::Pending->value,
                'amount'              => $chargeResult->amount ?? (float) $invoice->amount,
                'currency'            => $invoice->currency,
                'idempotency_key'     => $invoice->idempotency_key,
                'correlation_id'      => $correlationId,
            ]);

            $invoice->update([
                'external_invoice_id' => $chargeResult->externalPaymentId,
                'status'              => InvoiceStatus::Pending->value,
                'raw_gateway_payload' => $chargeResult->rawResponse,
            ]);

            // Advance next_billing_at by one billing cycle
            $nextBillingAt = $subscription->next_billing_at->add(
                \DateInterval::createFromDateString($cycle->months() . ' months')
            );

            $subscription->update([
                'next_billing_at'    => $nextBillingAt,
                'current_invoice_id' => $invoice->id,
                'last_billing_error' => null,
            ]);
        });

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
            message: 'Renovação iniciada com sucesso.',
            context: ['gateway' => $gatewayCode, 'external_id' => $chargeResult->externalPaymentId],
            entityId: (string) $entity->id,
            subscription: $subscription,
            invoice: $invoice,
            gatewayCode: $gatewayCode,
            correlationId: $correlationId,
        );
    }

    private function handleChargeFailure(
        Subscription $subscription,
        Invoice $invoice,
        string $correlationId,
        ?string $errorMessage,
        ?string $reason = null,
    ): void {
        $maxAttempts = (int) config('billing.retry.payment_attempts.max_attempts', 3);
        $backoffSec  = (int) config('billing.retry.payment_attempts.backoff_seconds', 120);

        DB::transaction(function () use ($subscription, $invoice, $correlationId, $errorMessage, $reason, $maxAttempts, $backoffSec): void {
            $invoice->update(['status' => InvoiceStatus::Failed->value]);

            $subscription->update([
                'billing_state'      => 'past_due',
                'last_billing_error' => mb_substr($errorMessage ?? $reason ?? 'Falha desconhecida.', 0, 500),
            ]);

            // Schedule retry if below max attempts
            $existingAttempts = BillingRetrySchedule::query()
                ->where('subscription_id', $subscription->id)
                ->where('invoice_id', $invoice->id)
                ->count();

            if ($existingAttempts < $maxAttempts) {
                BillingRetrySchedule::query()->create([
                    'entity_id'       => $subscription->entity_id,
                    'subscription_id' => $subscription->id,
                    'invoice_id'      => $invoice->id,
                    'gateway_code'    => $subscription->gateway,
                    'attempt_number'  => $existingAttempts + 1,
                    'scheduled_for'   => now()->addSeconds($backoffSec * (2 ** $existingAttempts)), // exponential backoff
                    'status'          => 'pending',
                    'correlation_id'  => $correlationId,
                ]);
            }
        });

        Log::warning('[RenewSubscriptionJob] Falha ao renovar assinatura.', [
            'subscription' => $subscription->id,
            'error'        => $errorMessage ?? $reason,
            'correlation'  => $correlationId,
        ]);
    }
}
