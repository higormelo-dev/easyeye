<?php

declare(strict_types=1);

namespace App\Services\Surgery\Guards;

use App\Exceptions\Surgery\SurgeryRuleException;
use App\Models\Doctor;
use App\Models\Surgery\SurgicalCase;
use App\Services\Surgery\SurgeryChecklistService;

/**
 * Registrar a cirurgia como REALIZADA exige a Pausa Cirúrgica (Time Out)
 * fechada E ainda válida: se o plano (LIO, técnica, anestesia, equipe) ou a
 * janela mudaram depois do fechamento, a Pausa antiga não vale mais. Única saída: o CIRURGIÃO do caso informa `time_out_override_reason`
 * (justificativa de pelo menos 10 caracteres), que fica gravada na fase —
 * secretária e outros médicos não têm essa exceção.
 *
 * Roda dentro da transação de quem registra a cirurgia, com o caso travado.
 */
final class ChecklistTimeOutGuard implements SurgeryPerformGuard
{
    public function __construct(private readonly SurgeryChecklistService $checklist)
    {
    }

    public function assert(SurgicalCase $locked, array $input, Doctor $performer): void
    {
        // Fechada E ainda válida para o plano/janela de agora.
        if ($this->checklist->isTimeOutCurrent($locked)) {
            return;
        }

        $reason = trim((string) ($input['time_out_override_reason'] ?? ''));

        if ($reason === '') {
            throw SurgeryRuleException::state(
                match (true) {
                    $this->checklist->isTimeOutCriticalNo($locked) => 'surgery_schedule.checklist.errors.time_out_critical_no',
                    $this->checklist->isTimeOutStale($locked)      => 'surgery_schedule.checklist.errors.time_out_stale',
                    default                                        => 'surgery_schedule.checklist.errors.time_out_required',
                },
            );
        }

        // Só o cirurgião justifica; recusa com mensagem própria, sem gravar nada.
        $this->checklist->overrideTimeOut($locked, $performer, $reason);
    }
}
