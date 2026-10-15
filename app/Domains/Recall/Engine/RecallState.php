<?php

declare(strict_types=1);

namespace App\Domains\Recall\Engine;

use Carbon\CarbonInterface;

/**
 * Em que ponto do prazo está um retorno, DERIVADO das datas persistidas e do dia de hoje (nada
 * disso é gravado: uma tarefa muda de estado sozinha, sem job). Os limites são o primeiro dia
 * inclusivo de cada estado (ver TaskTimeline):
 *
 *   upcoming  hoje < invite_from        ainda longe — fora da lista de retornos
 *   due_soon  invite_from ≤ hoje < due  a vencer — já se pode convidar
 *   due       due ≤ hoje < overdue_from vencido, dentro da tolerância
 *   overdue   overdue_from ≤ hoje < lost_from  em atraso
 *   lost      hoje ≥ lost_from          perdido de vista (busca ativa)
 */
enum RecallState: string
{
    case Upcoming = 'upcoming';
    case DueSoon  = 'due_soon';
    case Due      = 'due';
    case Overdue  = 'overdue';
    case Lost     = 'lost';

    public static function at(CarbonInterface $today, CarbonInterface $inviteFrom, CarbonInterface $dueAt, CarbonInterface $overdueFrom, CarbonInterface $lostFrom): self
    {
        $day = $today->toDateString();

        return match (true) {
            $day >= $lostFrom->toDateString()    => self::Lost,
            $day >= $overdueFrom->toDateString() => self::Overdue,
            $day >= $dueAt->toDateString()       => self::Due,
            $day >= $inviteFrom->toDateString()  => self::DueSoon,
            default                              => self::Upcoming,
        };
    }

    /** Aparece na lista de retornos (vencido, em atraso, perdido ou a vencer)? */
    public function isListed(): bool
    {
        return $this !== self::Upcoming;
    }

    /** Já passou do dia do vencimento? */
    public function isPastDue(): bool
    {
        return in_array($this, [self::Due, self::Overdue, self::Lost], true);
    }

    public function label(): string
    {
        return __('recall.states.' . $this->value);
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $state): string => $state->value, self::cases());
    }
}
