<?php

namespace App\Enums;

/**
 * Tipo de recompensa concedida ao referenciador quando o indicado converte.
 */
enum ReferralRewardType: string
{
    case TrialExtension     = 'trial_extension';      // Extensão de dias no trial atual
    case DiscountPercentage = 'discount_percentage';  // % de desconto na próxima fatura

    public function label(): string
    {
        return match ($this) {
            self::TrialExtension     => 'Extensão de Trial',
            self::DiscountPercentage => 'Desconto na Fatura',
        };
    }

    public function formatValue(int|float $value): string
    {
        return match ($this) {
            self::TrialExtension     => "{$value} dias extras",
            self::DiscountPercentage => "{$value}% de desconto",
        };
    }
}
