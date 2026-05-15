<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Enums;

enum TissGlosaStatus: string
{
    case Open            = 'open';
    case Appealed        = 'appealed';
    case PartialReversed = 'partial_reversed';
    case Reversed        = 'reversed';
    case Maintained      = 'maintained';
    case Cancelled       = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open            => 'Aberta',
            self::Appealed        => 'Recorrida',
            self::PartialReversed => 'Revertida parcialmente',
            self::Reversed        => 'Revertida',
            self::Maintained      => 'Mantida',
            self::Cancelled       => 'Cancelada',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Open            => 'danger',
            self::Appealed        => 'warning',
            self::PartialReversed => 'info',
            self::Reversed        => 'success',
            self::Maintained      => 'secondary',
            self::Cancelled       => 'light',
        };
    }

    public function isActionable(): bool
    {
        return $this === self::Open;
    }
}
