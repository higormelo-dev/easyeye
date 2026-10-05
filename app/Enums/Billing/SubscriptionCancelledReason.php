<?php

namespace App\Enums\Billing;

/**
 * Motivo gravado em `subscriptions.cancelled_reason` quando o próprio sistema
 * encerra a linha (o cancelamento a pedido fica em `cancellations.reason`).
 */
enum SubscriptionCancelledReason: string
{
    /** Uma nova assinatura da empresa assumiu o lugar desta. */
    case Replaced = 'replaced';

    /**
     * Tentativa de contratação que o gateway recusou: nunca valeu, nunca é a
     * vigente da empresa e fica só no histórico.
     */
    case ActivationFailed = 'activation_failed';
}
