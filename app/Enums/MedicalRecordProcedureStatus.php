<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Ciclo de vida de App\Models\MedicalRecordProcedure — Fase 3 (estoque ↔
 * prontuário). Substitui a inferência implícita de "foi executado" por um
 * estado real e auditável.
 */
enum MedicalRecordProcedureStatus: string
{
    case Requested = 'requested';
    case Done      = 'done';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Solicitado',
            self::Done      => 'Executado',
            self::Cancelled => 'Cancelado',
        };
    }

    /** Só Requested pode virar Done ou Cancelled — estado final não regride. */
    public function canTransitionTo(self $target): bool
    {
        return $this === self::Requested && in_array($target, [self::Done, self::Cancelled], true);
    }
}
