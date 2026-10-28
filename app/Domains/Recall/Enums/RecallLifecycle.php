<?php

declare(strict_types=1);

namespace App\Domains\Recall\Enums;

/**
 * O ciclo de vida de um retorno (patient_recalls.lifecycle) — o que a CLÍNICA fez com ele; o PRAZO (a vencer,
 * vencido, perdido) é outra coisa e vem das datas (RecallState).
 *
 *   open        em aberto (aparece na lista e pode ser convidado)
 *   snoozed     adiado até `snoozed_until` (volta sozinho)
 *   dismissed   dispensado pela clínica, com motivo (não volta nesse ciclo)
 *   closed      encerrado pelo sistema (feito fora, condição encerrada, tarefa de uma vez só concluída)
 *   needs_info  falta um dado para calcular as datas (ex.: início do uso do medicamento)
 */
enum RecallLifecycle: string
{
    case Open      = 'open';
    case Snoozed   = 'snoozed';
    case Dismissed = 'dismissed';
    case Closed    = 'closed';
    case NeedsInfo = 'needs_info';

    /** Entra na lista de retornos (aberto ou adiado). */
    public function isWorkable(): bool
    {
        return in_array($this, [self::Open, self::Snoozed], true);
    }

    public function label(): string
    {
        return __('recall.lifecycle.' . $this->value);
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $lifecycle): string => $lifecycle->value, self::cases());
    }
}
