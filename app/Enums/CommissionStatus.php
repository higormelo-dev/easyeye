<?php

namespace App\Enums;

enum CommissionStatus: string
{
    case Pending   = 'pending';
    case Paid      = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending   => 'Pendente',
            self::Paid      => 'Pago',
            self::Cancelled => 'Cancelado',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Pending   => 'bg-warning text-dark',
            self::Paid      => 'bg-success',
            self::Cancelled => 'bg-secondary',
        };
    }
}
