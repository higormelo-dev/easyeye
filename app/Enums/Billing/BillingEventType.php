<?php

namespace App\Enums\Billing;

enum BillingEventType: string
{
    case InvoiceCreated   = 'invoice_created';
    case InvoicePaid      = 'invoice_paid';
    case InvoiceOverdue   = 'invoice_overdue';
    case PaymentSucceeded = 'payment_succeeded';
    case PaymentFailed    = 'payment_failed';
    case PaymentRefunded  = 'payment_refunded';
    // Estorno de parte do valor: o pagamento segue valendo (só registra o devolvido).
    case PaymentPartiallyRefunded = 'payment_partially_refunded';
    // Estorno pedido ao gateway pelo manager (ainda não confirmado).
    case RefundRequested          = 'refund_requested';
    case SubscriptionActivated    = 'subscription_activated';
    case SubscriptionCancelled    = 'subscription_cancelled';
    case SubscriptionPastDue      = 'subscription_past_due';
    case SubscriptionExpired      = 'subscription_expired';
    case ChargebackReceived       = 'chargeback_received';
    case GatewayFallbackTriggered = 'gateway_fallback_triggered';
    case GatewayFallbackSucceeded = 'gateway_fallback_succeeded';
    case GatewayFallbackFailed    = 'gateway_fallback_failed';
}
