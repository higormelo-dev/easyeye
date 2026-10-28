<?php

namespace App\Enums;

enum LgpdRequestStatus: string
{
    case Pending    = 'pending';
    case InProgress = 'in_progress';
    case Completed  = 'completed';
    case Rejected   = 'rejected';
    case Cancelled  = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending    => 'Pendente',
            self::InProgress => 'Em Análise',
            self::Completed  => 'Concluída',
            self::Rejected   => 'Rejeitada',
            self::Cancelled  => 'Cancelada',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Pending    => 'bg-warning text-dark',
            self::InProgress => 'bg-info',
            self::Completed  => 'bg-success',
            self::Rejected   => 'bg-danger',
            self::Cancelled  => 'bg-secondary',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::InProgress]);
    }
}
