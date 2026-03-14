<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Trial     = 'trial';
    case Active    = 'active';
    case Expired   = 'expired';
    case Cancelled = 'cancelled';
    case PastDue   = 'past_due';

    public function label(): string
    {
        return match ($this) {
            self::Trial     => __('subscriptions.status.trial'),
            self::Active    => __('subscriptions.status.active'),
            self::Expired   => __('subscriptions.status.expired'),
            self::Cancelled => __('subscriptions.status.cancelled'),
            self::PastDue   => __('subscriptions.status.past_due'),
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Trial     => 'bg-info',
            self::Active    => 'bg-success',
            self::Expired   => 'bg-danger',
            self::Cancelled => 'bg-secondary',
            self::PastDue   => 'bg-warning text-dark',
        };
    }

    public function isAccessible(): bool
    {
        return in_array($this, [self::Trial, self::Active]);
    }
}
