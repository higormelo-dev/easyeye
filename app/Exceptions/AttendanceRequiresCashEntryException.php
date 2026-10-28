<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Schedule;
use RuntimeException;

/**
 * Regra "Atendido exige caixa": a clínica habilitou
 * `requires_cash_to_complete` e o agendamento não tem lançamento de caixa
 * ativo (não cancelado, da própria clínica). Lançada por
 * ScheduleService::changeSituation() — ponto único da regra — antes de
 * qualquer escrita; cada chamador traduz para a própria UX (422 no PATCH/
 * store/update, linha ignorada no bulk, aviso no "Finalizar" do prontuário).
 */
class AttendanceRequiresCashEntryException extends RuntimeException
{
    public function __construct(public readonly Schedule $schedule)
    {
        parent::__construct((string) __('schedules.cash_entry_required'));
    }
}
