<?php

namespace App\Services\Billing;

use App\DTOs\Billing\GatewayWebhookInputDTO;
use App\Enums\Billing\{BillingEventType, CancellationReason, InvoiceStatus, PaymentStatus};
use App\Models\Billing\{Cancellation, Invoice, Payment, WebhookEvent};
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ProcessWebhookEventService
{
    /** Normalized event types that indicate a successful payment */
    private const PAID_TYPES = ['paid', 'authorized'];

    /** Normalized event types that indicate a payment failure */
    private const FAILED_TYPES = ['failed'];

    /** Normalized event types that indicate cancellation */
    private const CANCELLED_TYPES = ['cancelled'];

    /** Normalized event types that indicate a refund */
    private const REFUNDED_TYPES = ['refunded'];

    /** Normalized event types that indicate a chargeback */
    private const CHARGEBACK_TYPES = ['chargeback'];

    public function __construct(
        private readonly GatewayRegistry $gatewayRegistry,
        private readonly BillingLogService $billingLogService,
        private readonly FinancialEventService $financialEventService,
    ) {
    }

    public function process(WebhookEvent $webhookEvent): void
    {
        if ($webhookEvent->status->value === 'processed') {
            return;
        }

        $correlationId = $webhookEvent->correlation_id ?: (string) Str::uuid();

        $webhookEvent->update([
            'status'         => 'processing',
            'attempts'       => $webhookEvent->attempts + 1,
            'correlation_id' => $correlationId,
        ]);

        try {
            $gateway = $this->gatewayRegistry->get($webhookEvent->gateway_code);

            $normalized = $gateway->parseWebhook(new GatewayWebhookInputDTO(
                gatewayCode: $webhookEvent->gateway_code,
                headers: $webhookEvent->headers ?? [],
                body: json_encode($webhookEvent->payload ?? [], JSON_UNESCAPED_UNICODE) ?: '{}',
                payload: $webhookEvent->payload ?? [],
                externalEventId: $webhookEvent->external_event_id,
                signature: $webhookEvent->signature,
                receivedAt: $webhookEvent->received_at?->toIso8601String(),
            ));

            // Resolve all models inside a transaction with pessimistic locking
            // to prevent race conditions when two webhooks arrive concurrently
            [$subscription, $invoice, $payment] = DB::transaction(function () use ($normalized, $webhookEvent) {
                $subscription = $this->resolveSubscription($normalized->externalSubscriptionId, $normalized->metadata);
                $invoice      = $this->resolveInvoice($normalized->externalInvoiceId, $normalized->gatewayCode, $subscription);
                $payment      = $this->resolvePayment($normalized->externalPaymentId, $normalized->gatewayCode, $subscription, $invoice, $normalized->amount, $normalized->currency);

                // Lock subscription row to prevent concurrent state mutations
                if ($subscription) {
                    $subscription = Subscription::query()
                        ->where('id', $subscription->id)
                        ->lockForUpdate()
                        ->first();
                }

                if ($subscription && ! $webhookEvent->entity_id) {
                    $webhookEvent->entity_id = $subscription->entity_id;
                }

                if ($payment && $invoice && ! $payment->invoice_id) {
                    $payment->invoice_id = $invoice->id;
                    $payment->save();
                }

                return [$subscription, $invoice, $payment];
            });

            // Apply state changes based on normalized event type (explicit match, not str_contains)
            $eventType = strtolower($normalized->eventType);

            if (in_array($eventType, self::PAID_TYPES, true)) {
                $this->applyPaidState($subscription, $invoice, $payment, $correlationId);
            } elseif (in_array($eventType, self::FAILED_TYPES, true)) {
                $this->applyFailedState($subscription, $invoice, $payment, $correlationId);
            } elseif (in_array($eventType, self::CANCELLED_TYPES, true)) {
                $this->applyCancelledState($subscription, $normalized->gatewayCode, $correlationId);
            } elseif (in_array($eventType, self::REFUNDED_TYPES, true)) {
                $this->applyRefundedState($subscription, $invoice, $payment, $correlationId);
            } elseif (in_array($eventType, self::CHARGEBACK_TYPES, true)) {
                $this->applyChargebackState($subscription, $invoice, $payment, $correlationId);
            }
            // 'unknown' and unrecognized types: log without state change

            $webhookEvent->update([
                'event_type'         => $normalized->eventType,
                'normalized_payload' => [
                    'event_type'               => $normalized->eventType,
                    'status'                   => $normalized->status,
                    'external_subscription_id' => $normalized->externalSubscriptionId,
                    'external_payment_id'      => $normalized->externalPaymentId,
                    'external_invoice_id'      => $normalized->externalInvoiceId,
                    'amount'                   => $normalized->amount,
                    'currency'                 => $normalized->currency,
                ],
                'status'       => 'processed',
                'processed_at' => now(),
                'last_error'   => null,
            ]);

            $this->billingLogService->log(
                level: 'info',
                message: 'Webhook processado com sucesso.',
                context: [
                    'event_type' => $normalized->eventType,
                    'status'     => $normalized->status,
                ],
                entityId: $subscription?->entity_id ?? $webhookEvent->entity_id,
                subscription: $subscription,
                invoice: $invoice,
                payment: $payment,
                webhookEvent: $webhookEvent,
                gatewayCode: $normalized->gatewayCode,
                correlationId: $correlationId,
            );
        } catch (Throwable $e) {
            $webhookEvent->update([
                'status'     => 'failed',
                'last_error' => mb_substr($e->getMessage(), 0, 2000),
            ]);

            $this->billingLogService->log(
                level: 'error',
                message: 'Falha ao processar webhook.',
                context: ['error' => $e->getMessage()],
                entityId: $webhookEvent->entity_id,
                webhookEvent: $webhookEvent,
                gatewayCode: $webhookEvent->gateway_code,
                correlationId: $correlationId,
            );

            throw $e;
        }
    }

    private function resolveSubscription(?string $externalSubscriptionId, array $metadata): ?Subscription
    {
        if ($externalSubscriptionId) {
            $subscription = Subscription::query()
                ->where('gateway_subscription_id', $externalSubscriptionId)
                ->first();

            if ($subscription) {
                return $subscription;
            }
        }

        $subscriptionId = $metadata['subscription_id'] ?? null;

        if (is_string($subscriptionId) && $subscriptionId !== '') {
            return Subscription::query()->find($subscriptionId);
        }

        return null;
    }

    private function resolveInvoice(?string $externalInvoiceId, string $gatewayCode, ?Subscription $subscription): ?Invoice
    {
        if ($externalInvoiceId) {
            $invoice = Invoice::query()
                ->where('gateway_code', $gatewayCode)
                ->where('external_invoice_id', $externalInvoiceId)
                ->first();

            if ($invoice) {
                return $invoice;
            }
        }

        if ($subscription?->current_invoice_id) {
            return Invoice::query()->find($subscription->current_invoice_id);
        }

        return null;
    }

    private function resolvePayment(
        ?string $externalPaymentId,
        string $gatewayCode,
        ?Subscription $subscription,
        ?Invoice $invoice,
        ?float $amount,
        ?string $currency,
    ): ?Payment {
        if ($externalPaymentId) {
            $payment = Payment::query()
                ->where('gateway_code', $gatewayCode)
                ->where('external_payment_id', $externalPaymentId)
                ->first();

            if ($payment) {
                return $payment;
            }
        }

        if (! $subscription || ! $invoice) {
            return null;
        }

        // Idempotency key prevents duplicate payment records on webhook retry
        $idempotencyKey = 'webhook:' . $gatewayCode . ':' . ($externalPaymentId ?? $invoice->id);

        return Payment::query()->firstOrCreate(
            [
                'invoice_id'      => $invoice->id,
                'idempotency_key' => $idempotencyKey,
            ],
            [
                'entity_id'           => $subscription->entity_id,
                'subscription_id'     => $subscription->id,
                'gateway_code'        => $gatewayCode,
                'external_payment_id' => $externalPaymentId,
                'status'              => PaymentStatus::Pending->value,
                'amount'              => $amount ?? (float) $invoice->amount,
                'currency'            => $currency ?? $invoice->currency,
                'idempotency_key'     => $idempotencyKey,
            ],
        );
    }

    private function applyPaidState(?Subscription $subscription, ?Invoice $invoice, ?Payment $payment, string $correlationId): void
    {
        if ($payment) {
            $payment->update([
                'status'  => PaymentStatus::Paid->value,
                'paid_at' => now(),
            ]);
        }

        if ($invoice) {
            $invoice->update([
                'status'  => InvoiceStatus::Paid->value,
                'paid_at' => now(),
            ]);
        }

        if ($subscription) {
            $subscription->update([
                'status'             => 'active',
                'billing_state'      => 'paid',
                'last_payment_at'    => now(),
                'last_billing_error' => null,
            ]);

            $this->financialEventService->record(
                eventType: BillingEventType::SubscriptionActivated,
                entityId: (string) $subscription->entity_id,
                subscription: $subscription,
                invoice: $invoice,
                payment: $payment,
                amount: $payment ? (float) $payment->amount : null,
                currency: $payment?->currency,
                correlationId: $correlationId,
                source: 'webhook',
            );
        }

        if ($subscription && $invoice) {
            $this->financialEventService->record(
                eventType: BillingEventType::InvoicePaid,
                entityId: (string) $subscription->entity_id,
                subscription: $subscription,
                invoice: $invoice,
                payment: $payment,
                amount: $payment ? (float) $payment->amount : (float) $invoice->amount,
                currency: $payment?->currency ?? $invoice->currency,
                correlationId: $correlationId,
                source: 'webhook',
            );
        }
    }

    private function applyFailedState(?Subscription $subscription, ?Invoice $invoice, ?Payment $payment, string $correlationId): void
    {
        if ($payment) {
            $payment->update([
                'status'    => PaymentStatus::Failed->value,
                'failed_at' => now(),
            ]);
        }

        if ($invoice) {
            $invoice->update([
                'status' => InvoiceStatus::Failed->value,
            ]);
        }

        if ($subscription) {
            $subscription->update([
                'status'             => 'past_due',
                'billing_state'      => 'past_due',
                'last_billing_error' => 'Falha no pagamento recebida via webhook.',
            ]);

            $this->financialEventService->record(
                eventType: BillingEventType::PaymentFailed,
                entityId: (string) $subscription->entity_id,
                subscription: $subscription,
                invoice: $invoice,
                payment: $payment,
                amount: $payment ? (float) $payment->amount : null,
                currency: $payment?->currency,
                correlationId: $correlationId,
                source: 'webhook',
            );
        }
    }

    private function applyCancelledState(?Subscription $subscription, string $gatewayCode, string $correlationId): void
    {
        if (! $subscription) {
            return;
        }

        $subscription->update([
            'status'        => 'cancelled',
            'cancelled_at'  => now(),
            'billing_state' => 'cancelled',
        ]);

        Cancellation::query()->create([
            'entity_id'       => $subscription->entity_id,
            'subscription_id' => $subscription->id,
            'gateway_code'    => $gatewayCode,
            'reason'          => CancellationReason::System->value,
            'source'          => 'webhook',
            'effective_at'    => now(),
            'metadata'        => ['origin' => 'gateway'],
            'correlation_id'  => $correlationId,
        ]);

        $this->financialEventService->record(
            eventType: BillingEventType::SubscriptionCancelled,
            entityId: (string) $subscription->entity_id,
            subscription: $subscription,
            amount: null,
            currency: null,
            correlationId: $correlationId,
            source: 'webhook',
        );
    }

    private function applyRefundedState(?Subscription $subscription, ?Invoice $invoice, ?Payment $payment, string $correlationId): void
    {
        if ($payment) {
            $payment->update([
                'status'      => PaymentStatus::Refunded->value,
                'refunded_at' => now(),
            ]);
        }

        if ($invoice) {
            $invoice->update([
                'status' => InvoiceStatus::Refunded->value,
            ]);
        }

        if ($subscription) {
            $this->financialEventService->record(
                eventType: BillingEventType::PaymentRefunded,
                entityId: (string) $subscription->entity_id,
                subscription: $subscription,
                invoice: $invoice,
                payment: $payment,
                amount: $payment ? (float) $payment->amount : null,
                currency: $payment?->currency,
                correlationId: $correlationId,
                source: 'webhook',
            );
        }
    }

    private function applyChargebackState(?Subscription $subscription, ?Invoice $invoice, ?Payment $payment, string $correlationId): void
    {
        if ($payment) {
            $payment->update([
                'status'        => PaymentStatus::Chargeback->value,
                'chargeback_at' => now(),
            ]);
        }

        if ($invoice) {
            $invoice->update([
                'status' => InvoiceStatus::Failed->value,
            ]);
        }

        if ($subscription) {
            // Mark subscription as past_due pending dispute resolution
            $subscription->update([
                'status'             => 'past_due',
                'billing_state'      => 'chargeback',
                'last_billing_error' => 'Chargeback recebido via webhook.',
            ]);

            $this->financialEventService->record(
                eventType: BillingEventType::ChargebackReceived,
                entityId: (string) $subscription->entity_id,
                subscription: $subscription,
                invoice: $invoice,
                payment: $payment,
                amount: $payment ? (float) $payment->amount : null,
                currency: $payment?->currency,
                correlationId: $correlationId,
                source: 'webhook',
            );
        }
    }
}
