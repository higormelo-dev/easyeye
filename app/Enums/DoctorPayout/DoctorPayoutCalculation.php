<?php

declare(strict_types=1);

namespace App\Enums\DoctorPayout;

/**
 * Forma de cálculo de uma regra: percentual sobre o valor base do item
 * ("valor cobrado") ou valor fixo por item.
 */
enum DoctorPayoutCalculation: string
{
    case Percentage = 'percentage';
    case Fixed      = 'fixed';

    public function label(): string
    {
        return __("financial_doctor_payouts.calculations.{$this->value}");
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
