<?php

declare(strict_types=1);

namespace App\Enums;

enum FinancialEntryType: string
{
    case Income  = 'income';
    case Expense = 'expense';

    public function label(): string
    {
        // Texto em lang/{locale}/financial_cash_flow.php (pt_BR mantém os rótulos de sempre).
        return __("financial_cash_flow.types.{$this->value}");
    }
}
