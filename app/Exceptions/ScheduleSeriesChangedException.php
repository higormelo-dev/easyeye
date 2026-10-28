<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A série mudou entre a prévia e a trava de "Reagendar a série": ganhou uma sessão, o horário da escolhida mudou ou
 * uma sessão que ficaria no lote mudou de data, médico ou encaixe (ScheduleSeriesService::rescheduleFuture). Nada foi
 * gravado; quem chama responde 409 e a recepção refaz a prévia. Sessão que só saiu do lote (cancelada ou atendida por
 * outra pessoa) NÃO dispara isto — o lote segue com as que restaram.
 */
final class ScheduleSeriesChangedException extends RuntimeException
{
    public function __construct(string $message = '')
    {
        parent::__construct($message !== '' ? $message : __('schedules.series_reschedule_changed'));
    }
}
