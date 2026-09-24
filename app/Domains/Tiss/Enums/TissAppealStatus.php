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

    public function label(): string
    {
        return match ($this) {
            self::Opened     => 'Aberto',
            self::Submitted  => 'Enviado',
            self::InAnalysis => 'Em análise',
            self::Accepted   => 'Aceito',
            self::Rejected   => 'Rejeitado',
            self::Cancelled  => 'Cancelado',
        };
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
