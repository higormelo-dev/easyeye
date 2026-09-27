<?php

declare(strict_types=1);

namespace App\Enums;

enum BillingClaimStatus: string
{
    case Draft     = 'draft';
    case Submitted = 'submitted';
    case Paid      = 'paid';
    case Denied    = 'denied';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        // Texto em lang/{locale}/financial_billing.php (pt_BR mantém os rótulos de sempre).
        return __("financial_billing.claim_statuses.{$this->value}");
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Draft     => 'bg-secondary',
            self::Submitted => 'bg-info text-dark',
            self::Paid      => 'bg-success',
            self::Denied    => 'bg-danger',
            self::Cancelled => 'bg-dark',
        };
    }
}
