<?php

declare(strict_types=1);

namespace App\Enums\ClinicalSafety;

/**
 * Fonte da informação da medicação em uso (A2): relatada pelo paciente
 * (padrão da recepção), receita de outro profissional trazida pelo paciente
 * ou registrada pelo médico da clínica.
 */
enum MedicationSource: string
{
    case Patient              = 'patient';
    case ExternalPrescription = 'external_prescription';
    case Clinic               = 'clinic';

    public function label(): string
    {
        return __('clinical_safety.medication_sources.' . $this->value);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
