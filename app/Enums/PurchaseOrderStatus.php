<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Ciclo de vida de App\Models\PurchaseOrder (Fase 4 — compras).
 *
 * draft → sent → partially_received → received
 *              ↘ received (recebeu tudo de uma vez, pula partially_received)
 * Qualquer estado não-terminal pode ir pra cancelled.
 */
enum PurchaseOrderStatus: string
{
    case Draft             = 'draft';
    case Sent              = 'sent';
    case PartiallyReceived = 'partially_received';
    case Received          = 'received';
    case Cancelled         = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft             => 'Rascunho',
            self::Sent              => 'Enviado ao fornecedor',
            self::PartiallyReceived => 'Recebido parcialmente',
            self::Received          => 'Recebido',
            self::Cancelled         => 'Cancelado',
        };
    }

    /** Itens só podem ser adicionados/editados/removidos em rascunho. */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Received, self::Cancelled], true);
    }

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Draft => in_array($target, [self::Sent, self::Cancelled], true),
            self::Sent, self::PartiallyReceived => in_array($target, [self::PartiallyReceived, self::Received, self::Cancelled], true),
            self::Received, self::Cancelled => false,
        };
    }
}
