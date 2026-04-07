<?php

namespace App\Services\Billing;

use App\Enums\Billing\BillingEventType;
use App\Models\Billing\{FinancialEvent, Invoice, Payment};
use App\Models\Subscription;

class FinancialEventService
{
    public function record(
        BillingEventType $eventType,
        string $entityId,
        ?Subscription $subscription = null,
        ?Invoice $invoice = null,
        ?Payment $payment = null,
        ?float $amount = null,
        ?string $currency = null,
        array $metadata = [],
        ?string $correlationId = null,
        ?string $source = null,
    ): FinancialEvent {
        return FinancialEvent::query()->create([
            'entity_id'       => $entityId,
            'subscription_id' => $subscription?->id,
            'invoice_id'      => $invoice?->id,
            'payment_id'      => $payment?->id,
            'event_type'      => $eventType->value,
            'amount'          => $amount,
            'currency'        => $currency,
            'effective_at'    => now(),
            'metadata'        => $metadata,
            'correlation_id'  => $correlationId,
            'source'          => $source,
        ]);
    }
}
