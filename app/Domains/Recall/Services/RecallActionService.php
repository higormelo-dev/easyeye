<?php

declare(strict_types=1);

namespace App\Domains\Recall\Services;

use App\Domains\Recall\Enums\{RecallContactOutcome, RecallLifecycle};
use App\Domains\Recall\Exceptions\RecallActionException;
use App\Domains\Recall\Models\{PatientRecall, PatientRecallEvent};
use App\Domains\Recall\Support\{RecallLocks, RecallRuleInUse};
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * As ações da EQUIPE sobre um retorno (contato, adiar, dispensar, reabrir, "feito fora"). Cada ação é uma
 * transação que toma a trava do PACIENTE antes de qualquer linha (a mesma do avaliador — RecallLocks), relê a
 * linha sob FOR UPDATE e só então decide: nunca valida o estado na instância carregada antes da trava (duas
 * abas passam as duas e a segunda sobrescreve a primeira).
 *
 * Linha de OUTRA clínica, de paciente removido ou inexistente é "não encontrada" (nada vaza). Retorno com a
 * regra suspensa não recebe ação: a EasyEye está em dúvida sobre ela. Cada ação grava o evento com quem fez.
 */
class RecallActionService
{
    /** Adiar por mais que isto é dispensar: o retorno volta sozinho no máximo em um ano. */
    public const MAX_SNOOZE_DAYS = 365;

    public const NOTE_MAX = 200;

    public const REASON_MIN = 3;

    public function __construct(private readonly RecallEvaluator $evaluator)
    {
    }

    /** Registra uma tentativa de contato (conta e guarda o último resultado). */
    public function contact(string $entityId, string $recallId, RecallContactOutcome $outcome, ?string $note = null, ?User $actor = null): PatientRecall
    {
        return $this->act($entityId, $recallId, function (PatientRecall $row) use ($outcome, $note, $actor): void {
            $this->assertWorkable($row);

            $row->forceFill([
                'last_contact_at'      => now(),
                'last_contact_outcome' => $outcome->value,
                'contact_count'        => (int) $row->contact_count + 1,
            ])->save();

            $this->event($row, 'contacted', $actor, ['outcome' => $outcome->value, 'note' => $this->text($note, self::NOTE_MAX)]);
        });
    }

    /** Adia o retorno até a data (amanhã em diante, no máximo um ano); ele volta sozinho para a lista. */
    public function snooze(string $entityId, string $recallId, CarbonImmutable $until, ?string $reason = null, ?User $actor = null, ?CarbonImmutable $today = null): PatientRecall
    {
        $today = ($today ?? CarbonImmutable::now())->startOfDay();
        $until = $until->startOfDay();

        if ($until->lessThanOrEqualTo($today) || $until->greaterThan($today->addDays(self::MAX_SNOOZE_DAYS))) {
            throw RecallActionException::invalidDate('O adiamento vai de amanhã até um ano.');
        }

        return $this->act($entityId, $recallId, function (PatientRecall $row) use ($until, $reason, $actor): void {
            $this->assertWorkable($row);

            $row->forceFill(['lifecycle' => RecallLifecycle::Snoozed->value, 'snoozed_until' => $until->toDateString()])->save();

            $this->event($row, 'snoozed', $actor, ['until' => $until->toDateString(), 'reason' => $this->text($reason, self::NOTE_MAX)]);
        });
    }

    /** Dispensa o retorno deste ciclo (o paciente não precisa/não vai voltar); o motivo é obrigatório. */
    public function dismiss(string $entityId, string $recallId, string $reason, ?User $actor = null): PatientRecall
    {
        $reason = $this->text($reason, self::NOTE_MAX);

        if ($reason === null || mb_strlen($reason) < self::REASON_MIN) {
            throw RecallActionException::reasonRequired();
        }

        return $this->act($entityId, $recallId, function (PatientRecall $row) use ($reason, $actor): void {
            $this->assertWorkable($row);

            $row->forceFill(['lifecycle' => RecallLifecycle::Dismissed->value, 'dismissed_reason' => $reason, 'snoozed_until' => null])->save();

            $this->event($row, 'dismissed', $actor, ['reason' => $reason]);
        });
    }

    /** Desfaz o adiamento ou a dispensa: o retorno volta para a lista. */
    public function reopen(string $entityId, string $recallId, ?User $actor = null): PatientRecall
    {
        return $this->act($entityId, $recallId, function (PatientRecall $row) use ($actor): void {
            $this->assertNotSuspended($row);

            if (! in_array($row->lifecycle, [RecallLifecycle::Snoozed, RecallLifecycle::Dismissed], true)) {
                throw RecallActionException::notReopenable($row->lifecycle->value);
            }

            $from = $row->lifecycle->value;

            $row->forceFill(['lifecycle' => RecallLifecycle::Open->value, 'snoozed_until' => null, 'dismissed_reason' => null])->save();

            $this->event($row, 'reopened', $actor, ['from' => $from]);
        });
    }

    /**
     * "Feito fora": o paciente fez a consulta/o exame em outro serviço na data. A data entra no cálculo como se o
     * ato tivesse sido registrado aqui (re-ancora o próximo vencimento) e a linha é reavaliada NA MESMA transação.
     */
    public function doneElsewhere(string $entityId, string $recallId, CarbonImmutable $date, ?User $actor = null, ?CarbonImmutable $today = null): PatientRecall
    {
        $today = ($today ?? CarbonImmutable::now())->startOfDay();
        $date  = $date->startOfDay();

        if ($date->greaterThan($today)) {
            throw RecallActionException::invalidDate('A data não pode ser futura.');
        }

        return $this->act($entityId, $recallId, function (PatientRecall $row) use ($date, $actor, $today): void {
            $this->assertWorkable($row);

            // A aplicação do curso tem o intervalo decidido pelo médico: só o prontuário (uma nova aplicação) a re-ancora.
            if ($row->isCourse()) {
                throw RecallActionException::notForCourse();
            }

            $floor = $row->anchor_at !== null ? CarbonImmutable::parse($row->anchor_at)->startOfDay() : null;

            if ($floor !== null && $date->lessThan($floor)) {
                throw RecallActionException::invalidDate('A data é anterior ao início deste acompanhamento.');
            }

            $row->forceFill(['external_done_at' => $date->toDateString(), 'external_done_by' => $actor?->getKey()])->save();

            $this->event($row, 'done_elsewhere', $actor, ['date' => $date->toDateString()]);

            // Reavalia agora (mesma transação, mesma trava): a tela já volta com a conta nova.
            $this->evaluator->evaluatePatient((string) $row->entity_id, (string) $row->patient_id, $today);
        });
    }

    // ── infraestrutura ──────────────────────────────────────────────────────

    /**
     * @param callable(PatientRecall): void $change recebe a linha FRESCA, travada, de paciente existente da clínica
     */
    private function act(string $entityId, string $recallId, callable $change): PatientRecall
    {
        return DB::transaction(function () use ($entityId, $recallId, $change): PatientRecall {
            // O paciente vem de uma leitura SEM trava só para saber qual trava tomar; a linha é relida depois dela.
            $patientId = DB::table('patient_recalls')->where('id', $recallId)->where('entity_id', $entityId)->value('patient_id');

            if (! is_string($patientId)) {
                throw RecallActionException::notFound();
            }

            RecallLocks::patient($patientId);

            $row = PatientRecall::query()->withoutGlobalScopes()->where('entity_id', $entityId)->whereKey($recallId)->lockForUpdate()->first();

            $patientExists = $row !== null && DB::table('patients')->where('id', $row->patient_id)->where('entity_id', $entityId)->whereNull('deleted_at')->exists();

            if (! $patientExists) {
                throw RecallActionException::notFound();
            }

            $change($row);

            return $row->refresh();
        });
    }

    private function assertWorkable(PatientRecall $row): void
    {
        $this->assertNotSuspended($row);

        if (! $row->lifecycle->isWorkable()) {
            throw RecallActionException::notWorkable($row->lifecycle->value);
        }
    }

    private function assertNotSuspended(PatientRecall $row): void
    {
        // O flag é derivado e chega com atraso; a versão VIVA do protocolo decide (RecallRuleInUse).
        if ($row->rule_suspended || ! RecallRuleInUse::for($row)) {
            throw RecallActionException::ruleSuspended();
        }
    }

    /** @param array<string, mixed> $meta */
    private function event(PatientRecall $row, string $event, ?User $actor, array $meta): void
    {
        PatientRecallEvent::query()->withoutGlobalScopes()->create([
            'entity_id'     => $row->entity_id,
            'patient_id'    => $row->patient_id,
            'recall_id'     => $row->id,
            'event'         => $event,
            'actor_user_id' => $actor?->getKey(),
            'actor_label'   => $actor !== null ? mb_substr((string) $actor->name, 0, 160) : 'system',
            'meta'          => array_filter($meta, static fn (mixed $value): bool => $value !== null) ?: null,
        ]);
    }

    private function text(?string $value, int $max): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
