<?php

namespace App\Enums;

enum ImportStatus: string
{
    case Pending    = 'pending';
    case Processing = 'processing';
    case Done       = 'done';
    case Failed     = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending    => __('imports.status.pending'),
            self::Processing => __('imports.status.processing'),
            self::Done       => __('imports.status.done'),
            self::Failed     => __('imports.status.failed'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending    => 'secondary',
            self::Processing => 'primary',
            self::Done       => 'success',
            self::Failed     => 'danger',
        };
    }

    public function isDone(): bool
    {
        return $this === self::Done || $this === self::Failed;
    }
}
