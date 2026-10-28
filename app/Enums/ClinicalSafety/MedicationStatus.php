<?php

declare(strict_types=1);

namespace App\Enums\ClinicalSafety;

/**
 * Situação da medicação em uso (A2): ativa (entra nos alertas e no retrato do
 * prontuário), suspensa (parou de usar — fica no histórico com a data) ou
 * descartada (registro indevido; só o médico descarta).
 */
enum MedicationStatus: string
{
    case Active    = 'active';
    case Suspended = 'suspended';
    case Discarded = 'discarded';

    public function label(): string
    {
        return __('clinical_safety.medication_statuses.' . $this->value);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
