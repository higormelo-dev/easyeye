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
            self::Trial     => 'badge-soft-info rounded text-info border border-info fs-13 fw-medium',
            self::Active    => 'badge-soft-success rounded text-success border border-success fs-13 fw-medium',
            self::Expired   => 'badge-soft-danger rounded text-danger border border-danger fs-13 fw-medium',
            self::Cancelled => 'badge-soft-secondary rounded fs-13 fw-medium',
            self::PastDue   => 'badge-soft-warning rounded text-warning border border-warning fs-13 fw-medium',
        };
    }

    public function isAccessible(): bool
    {
        return in_array($this, [self::Trial, self::Active]);
    }
}
