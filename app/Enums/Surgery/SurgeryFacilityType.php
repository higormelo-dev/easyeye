<?php

declare(strict_types=1);

namespace App\Enums\Surgery;

/**
 * Tipo de local onde a cirurgia acontece.
 */
enum SurgeryFacilityType: string
{
    case OwnClinic      = 'own_clinic';
    case Hospital       = 'hospital';
    case SurgicalCenter = 'surgical_center';

    public function label(): string
    {
        return __('surgery_enums.surgery_facility_type.' . $this->value);
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
}
