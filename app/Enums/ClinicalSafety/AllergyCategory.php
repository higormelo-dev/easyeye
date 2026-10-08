<?php

declare(strict_types=1);

namespace App\Enums\ClinicalSafety;

/**
 * Categoria da alergia do paciente (A1). Conservante cobre o cloreto de
 * benzalcônio dos colírios multidose; antisséptico cobre o iodo.
 */
enum AllergyCategory: string
{
    case Medication   = 'medication';
    case EyeDrop      = 'eye_drop';
    case Anesthetic   = 'anesthetic';
    case Antiseptic   = 'antiseptic';
    case Latex        = 'latex';
    case Preservative = 'preservative';
    case Food         = 'food';
    case Other        = 'other';

    public function label(): string
    {
        return __('clinical_safety.categories.' . $this->value);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
