<?php

declare(strict_types=1);

namespace App\Enums\Surgery;

/**
 * Implante vigente ou anulado (a linha nunca é apagada).
 */
enum ImplantStatus: string
{
    case Active = 'active';
    case Voided = 'voided';

    public function label(): string
    {
        return __('surgery_operation.enums.implant_status.' . $this->value);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
