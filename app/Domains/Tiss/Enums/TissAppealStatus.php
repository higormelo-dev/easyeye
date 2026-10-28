<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Enums;

enum TissAppealStatus: string
{
    case Opened     = 'opened';
    case Submitted  = 'submitted';
    case InAnalysis = 'in_analysis';
    case Accepted   = 'accepted';
    case Rejected   = 'rejected';
    case Cancelled  = 'cancelled';

    /**
     * Texto em lang/{locale}/financial_glosas.php (pt_BR mantém os rótulos de sempre).
     * $locale fixo serve para texto PERSISTIDO (histórico TISS), que não pode variar
     * com o idioma de quem executou a ação.
     */
    public function label(?string $locale = null): string
    {
        return __("financial_glosas.appeal_status.{$this->value}", [], $locale);
    }

    public function color(): string
    {
        return match ($this) {
            self::Opened     => 'secondary',
            self::Submitted  => 'info',
            self::InAnalysis => 'warning',
            self::Accepted   => 'success',
            self::Rejected   => 'danger',
            self::Cancelled  => 'light',
        };
    }

    public function canBeSubmitted(): bool
    {
        return $this === self::Opened;
    }

    public function canBeResolved(): bool
    {
        return in_array($this, [self::Submitted, self::InAnalysis], true);
    }
}
