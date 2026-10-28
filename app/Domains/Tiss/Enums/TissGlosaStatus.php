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

    /**
     * Texto em lang/{locale}/financial_glosas.php (pt_BR mantém os rótulos de sempre).
     * $locale fixo serve para texto PERSISTIDO (histórico TISS), que não pode variar
     * com o idioma de quem executou a ação.
     */
    public function label(?string $locale = null): string
    {
        return __("financial_glosas.glosa_status.{$this->value}", [], $locale);
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
