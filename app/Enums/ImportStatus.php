<?php

namespace App\Enums;

enum ImportStatus: string
{
    case Pending    = 'pending';
    case Processing = 'processing';
    case Done       = 'done';
    case Failed     = 'failed';
    case Cancelled  = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending    => __('imports.status.pending'),
            self::Processing => __('imports.status.processing'),
            self::Done       => __('imports.status.done'),
            self::Failed     => __('imports.status.failed'),
            self::Cancelled  => __('imports.status.cancelled'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending    => 'secondary',
            self::Processing => 'primary',
            self::Done       => 'success',
            self::Failed     => 'danger',
            self::Cancelled  => 'dark',
        };
    }

    /**
     * Estado terminal: não é mais pollável e não deve mais rodar.
     */
    public function isDone(): bool
    {
        return in_array($this, [self::Done, self::Failed, self::Cancelled], true);
    }
}
