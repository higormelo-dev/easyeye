<?php

namespace App\Enums;

enum CommissionStatus: string
{
    case Pending   = 'pending';
    case Paid      = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __('partners.commission_status.' . $this->value);
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Pending   => 'badge-soft-warning rounded text-warning border border-warning fs-13 fw-medium',
            self::Paid      => 'badge-soft-success rounded text-success border border-success fs-13 fw-medium',
            self::Cancelled => 'badge-soft-secondary rounded fs-13 fw-medium',
        };
    }
}
