<?php

declare(strict_types=1);

namespace App\Domains\Recall\Services;

use App\Domains\Recall\Exceptions\RecallActionException;
use App\Domains\Recall\Models\{PatientRecall, PatientRecallEvent};
use App\Domains\Recall\Support\{RecallLocks, RecallRuleInUse};
use App\Models\{Schedule, User};
use Illuminate\Support\Facades\DB;

/**
 * Vincula um agendamento recém-criado ao retorno que ele atende ("Agendando o retorno de…"). Sem isto a
 * avaliação vincularia o PRÓXIMO agendamento aberto do paciente — que pode ser outra coisa (uma cirurgia, um
 * exame); com isto a equipe escolhe qual agendamento é o retorno, e a lista já o mostra como agendado sem
 * esperar a reavaliação.
 *
 * Roda na transação do agendamento (savepoint) e, depois da trava da agenda, toma a do recall do paciente
 * (ordem global: agenda → paciente (recall) → linha). Vínculo recusado (paciente diferente, outra clínica,
 * retorno já tratado) não derruba o agendamento: quem chama decide; a avaliação vincula o próximo aberto.
 */
class RecallLinkService
{
    /**
     * @throws RecallActionException
     */
    public function link(string $entityId, string $recallId, Schedule $schedule, ?User $actor = null): PatientRecall
    {
        return DB::transaction(function () use ($entityId, $recallId, $schedule, $actor): PatientRecall {
            $patientId = DB::table('patient_recalls')->where('id', $recallId)->where('entity_id', $entityId)->value('patient_id');

            if (! is_string($patientId)) {
                throw RecallActionException::notFound();
            }

            RecallLocks::patient($patientId);

            $row = PatientRecall::query()->withoutGlobalScopes()->where('entity_id', $entityId)->whereKey($recallId)->lockForUpdate()->first();

            if ($row === null) {
                throw RecallActionException::notFound();
            }

            if ((string) $schedule->entity_id !== $entityId || (string) $schedule->patient_id !== (string) $row->patient_id) {
                throw RecallActionException::linkMismatch();
            }

            if ($row->rule_suspended || ! RecallRuleInUse::for($row)) {
                throw RecallActionException::ruleSuspended();
            }

            if (! $row->lifecycle->isWorkable()) {
                throw RecallActionException::notWorkable($row->lifecycle->value);
            }

            $row->forceFill(['scheduled_schedule_id' => $schedule->id])->save();

            PatientRecallEvent::query()->withoutGlobalScopes()->create([
                'entity_id'     => $row->entity_id,
                'patient_id'    => $row->patient_id,
                'recall_id'     => $row->id,
                'event'         => 'scheduled',
                'actor_user_id' => $actor?->getKey(),
                'actor_label'   => $actor !== null ? mb_substr((string) $actor->name, 0, 160) : 'system',
                'meta'          => ['schedule_id' => (string) $schedule->id, 'date_time' => $schedule->date_time?->toIso8601String()],
            ]);

            return $row;
        });
    }
}
