<?php

declare(strict_types = 1);

namespace App\Enums;

enum BillingBatchStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Processed = 'processed';
    case Paid = 'paid';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Rascunho',
            self::Submitted => 'Enviado',
            self::Processed => 'Processado',
            self::Paid => 'Pago',
            self::Rejected => 'Rejeitado',
            self::Cancelled => 'Cancelado',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Draft => 'bg-secondary',
            self::Submitted => 'bg-info text-dark',
            self::Processed => 'bg-primary',
            self::Paid => 'bg-success',
            self::Rejected => 'bg-danger',
            self::Cancelled => 'bg-dark',
        };
    }
}

