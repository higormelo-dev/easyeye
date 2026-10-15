<?php

declare(strict_types=1);

namespace App\Domains\Recall\Intravitreal\Enums;

/** A autorização do convênio de UMA aplicação. */
enum AuthorizationStatus: string
{
    case NotRequired = 'not_required';
    case Pending     = 'pending';
    case Requested   = 'requested';
    case Approved    = 'approved';
    case Denied      = 'denied';
    case Expired     = 'expired';

    public function label(): string
    {
        return __('recall_course.authorization.' . $this->value);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
