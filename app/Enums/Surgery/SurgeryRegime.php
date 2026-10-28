<?php

declare(strict_types=1);

namespace App\Enums\Surgery;

/**
 * Regime da cirurgia. Só `day_hospital` e `inpatient` geram solicitação de internação TISS.
 */
enum SurgeryRegime: string
{
    case Ambulatory  = 'ambulatory';
    case DayHospital = 'day_hospital';
    case Inpatient   = 'inpatient';

    public function label(): string
    {
        return __('surgery_enums.surgery_regime.' . $this->value);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return array<string, string> valor => rótulo no idioma atual */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /** Regime que exige guia de solicitação de internação à operadora. */
    public function requiresInpatientRequest(): bool
    {
        return $this !== self::Ambulatory;
    }
}
