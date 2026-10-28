<?php

declare(strict_types=1);

namespace App\Enums\ClinicalSafety;

/** Gravidade da reação alérgica relatada (A1). */
enum AllergySeverity: string
{
    case Mild     = 'mild';
    case Moderate = 'moderate';
    case Severe   = 'severe'; // grave / anafilaxia

    public function label(): string
    {
        return __('clinical_safety.severities.' . $this->value);
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
