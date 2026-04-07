<?php

namespace App\Services\Billing;

use App\Models\Billing\{BillingLog, Invoice, Payment, PaymentAttempt, WebhookEvent};
use App\Models\Subscription;

class BillingLogService
{
    public function log(
        string $level,
        string $message,
        array $context = [],
        ?string $entityId = null,
        ?Subscription $subscription = null,
        ?Invoice $invoice = null,
        ?Payment $payment = null,
        ?PaymentAttempt $attempt = null,
        ?WebhookEvent $webhookEvent = null,
        ?string $gatewayCode = null,
        ?string $correlationId = null,
    ): BillingLog {
        return BillingLog::query()->create([
            'entity_id'          => $entityId ?? $subscription?->entity_id ?? $invoice?->entity_id ?? $payment?->entity_id,
            'subscription_id'    => $subscription?->id,
            'invoice_id'         => $invoice?->id,
            'payment_id'         => $payment?->id,
            'payment_attempt_id' => $attempt?->id,
            'webhook_event_id'   => $webhookEvent?->id,
            'gateway_code'       => $gatewayCode,
            'level'              => $level,
            'message'            => $message,
            'context'            => $context,
            'correlation_id'     => $correlationId,
            'request_id'         => request()?->header('X-Request-Id'),
            'created_at'         => now(),
        ]);
    }
}
