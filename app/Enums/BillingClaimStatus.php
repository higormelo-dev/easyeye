<?php

declare(strict_types = 1);

namespace App\Enums;

enum BillingClaimStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Paid = 'paid';
    case Denied = 'denied';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Rascunho',
            self::Submitted => 'Enviado',
            self::Paid => 'Pago',
            self::Denied => 'Glosado',
            self::Cancelled => 'Cancelado',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Draft => 'bg-secondary',
            self::Submitted => 'bg-info text-dark',
            self::Paid => 'bg-success',
            self::Denied => 'bg-danger',
            self::Cancelled => 'bg-dark',
        };
    }
}

