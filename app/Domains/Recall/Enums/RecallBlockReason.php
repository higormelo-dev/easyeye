<?php

declare(strict_types=1);

namespace App\Domains\Recall\Enums;

/** Por que o paciente não deve receber convite de retorno. */
enum RecallBlockReason: string
{
    case Deceased     = 'deceased';
    case DoNotContact = 'do_not_contact';
    case Moved        = 'moved';
    case NoValidPhone = 'no_valid_phone';
    case Other        = 'other';

    public function label(): string
    {
        return __('recall.block_reason.' . $this->value);
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $reason): string => $reason->value, self::cases());
    }
}
