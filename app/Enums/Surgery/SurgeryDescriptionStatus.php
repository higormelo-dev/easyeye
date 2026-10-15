<?php

declare(strict_types=1);

namespace App\Enums\Surgery;

/**
 * Descrição cirúrgica: rascunho editável ou finalizada (imutável; só adendo).
 */
enum SurgeryDescriptionStatus: string
{
    case Draft     = 'draft';
    case Finalized = 'finalized';

    public function label(): string
    {
        return __('surgery_operation.enums.surgery_description_status.' . $this->value);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
