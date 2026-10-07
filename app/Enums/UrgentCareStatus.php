<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Andamento de quem está no pronto atendimento. Na fila: aguardando e
 * chamado. Fora da fila: em atendimento (o "Atender" criou o agendamento de
 * urgência e abriu o prontuário), desistiu e removido (com motivo).
 */
enum UrgentCareStatus: int
{
    case Waiting = 1;
    case Called  = 2;
    case InCare  = 3;
    case Left    = 4;
    case Removed = 5;

    /** Ainda na fila (conta nos contadores da Agenda/Dashboard). */
    public const QUEUED = [self::Waiting, self::Called];

    public function key(): string
    {
        return match ($this) {
            self::Waiting => 'waiting',
            self::Called  => 'called',
            self::InCare  => 'in_care',
            self::Left    => 'left',
            self::Removed => 'removed',
        };
    }

    public function label(): string
    {
        return __('urgent_care.status_' . $this->key());
    }

    public function isQueued(): bool
    {
        return in_array($this, self::QUEUED, true);
    }

    /** @return list<int> */
    public static function queuedValues(): array
    {
        return array_map(fn (self $s) => $s->value, self::QUEUED);
    }
}
