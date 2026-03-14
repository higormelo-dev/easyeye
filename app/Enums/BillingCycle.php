<?php

namespace App\Enums;

enum BillingCycle: string
{
    case Monthly  = 'monthly';
    case Yearly   = 'yearly';
    case Lifetime = 'lifetime';

    public function label(): string
    {
        return match ($this) {
            self::Monthly  => __('subscriptions.billing_cycle.monthly'),
            self::Yearly   => __('subscriptions.billing_cycle.yearly'),
            self::Lifetime => __('subscriptions.billing_cycle.lifetime'),
        };
    }

    /** Returns the Carbon method to add to now() for this cycle, or null for lifetime. */
    public function addToNow(): ?\Carbon\Carbon
    {
        return match ($this) {
            self::Monthly  => now()->addMonth(),
            self::Yearly   => now()->addYear(),
            self::Lifetime => null,
        };
    }
}
