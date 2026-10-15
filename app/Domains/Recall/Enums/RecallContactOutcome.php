<?php

declare(strict_types=1);

namespace App\Domains\Recall\Enums;

/** O resultado de uma tentativa de contato com o paciente (ligação, mensagem, recado). */
enum RecallContactOutcome: string
{
    case Reached      = 'reached';
    case WillSchedule = 'will_schedule';
    case NoAnswer     = 'no_answer';
    case WrongNumber  = 'wrong_number';
    case Declined     = 'declined';

    public function label(): string
    {
        return __('recall.contact_outcome.' . $this->value);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $outcome): string => $outcome->value, self::cases());
    }
}
