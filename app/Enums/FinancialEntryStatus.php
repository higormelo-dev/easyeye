<?php

declare(strict_types=1);

namespace App\Enums;

enum FinancialEntryStatus: string
{
    case Pending   = 'pending';
    case Paid      = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        // Texto em lang/{locale}/financial_cash_flow.php (pt_BR mantém os rótulos de sempre).
        return __("financial_cash_flow.statuses.{$this->value}");
    }
}
