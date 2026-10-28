<?php

declare(strict_types=1);

namespace App\Enums\Surgery;

/** Resposta de um item do checklist: sim, não ou não se aplica. */
enum ChecklistAnswer: string
{
    case Yes           = 'yes';
    case No            = 'no';
    case NotApplicable = 'na';

    public function label(): string
    {
        return __('surgery_schedule.checklist.answers.' . $this->value);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
