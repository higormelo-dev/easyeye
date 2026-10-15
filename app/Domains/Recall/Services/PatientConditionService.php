<?php

declare(strict_types=1);

namespace App\Domains\Recall\Services;

use App\Domains\Recall\Enums\{ConditionOrigin, ConditionStatus};
use App\Domains\Recall\Exceptions\PatientConditionException;
use App\Domains\Recall\Models\{PatientCondition, RecallClinicProtocol};
use App\Domains\Recall\Support\RecallLocks;
use App\Enums\Eye;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Os atos do MÉDICO sobre as condições crônicas do paciente (ADR 0002, item 8): confirmar o que o sistema
 * sugeriu, descartar, dar alta, registrar à mão e corrigir olho/início. Ato clínico — o Gate (DecideRecall) é
 * conferido por quem chama; aqui mora a regra.
 *
 * Cada ato é uma transação que toma a trava do PACIENTE antes de qualquer linha (a mesma do avaliador e do
 * detector — RecallLocks), relê a condição sob FOR UPDATE e só então decide: nunca valida o estado na instância
 * carregada antes da trava. Depois de gravar, reavalia o paciente na MESMA transação: a lista de retornos já
 * volta com a conta nova (e a trilha de auditoria da condição vem do `Auditable`).
 *
 * O que o médico decide o sistema nunca desfaz (o detector respeita descartada, com alta e manual): editar olho ou
 * início de uma condição detectada a torna "do médico" (origem manual, a evidência continua guardada).
 */
class PatientConditionService
{
    public const REASON_MIN = 3;

    public const REASON_MAX = 500;

    /** Antes disto a data não é um início de acompanhamento (erro de digitação). */
    private const EARLIEST_YEAR = 1900;

    public function __construct(private readonly RecallEvaluator $evaluator)
    {
    }

    /**
     * Registra à mão uma condição ATIVA (o médico a confirma ao registrar) de um protocolo que a clínica usa.
     */
    public function add(string $entityId, string $patientId, string $protocolKey, Eye $eye, ?CarbonImmutable $startedOn, User $actor, ?CarbonImmutable $today = null): PatientCondition
    {
        $today = ($today ?? CarbonImmutable::now())->startOfDay();

        return DB::transaction(function () use ($entityId, $patientId, $protocolKey, $eye, $startedOn, $actor, $today): PatientCondition {
            RecallLocks::patient($patientId);

            $this->assertPatient($entityId, $patientId);
            $this->assertProtocolActive($entityId, $protocolKey);
            $this->assertStart($startedOn, $today);
            $this->assertNoOverlap($entityId, $patientId, $protocolKey, $eye, null);

            $row = PatientCondition::query()->withoutGlobalScopes()->create([
                'entity_id'    => $entityId,
                'patient_id'   => $patientId,
                'protocol_key' => $protocolKey,
                'eye'          => $eye->value,
                'status'       => ConditionStatus::Active->value,
                'origin'       => ConditionOrigin::Manual->value,
                'started_on'   => $startedOn?->toDateString(),
                'confirmed_at' => now(),
                'confirmed_by' => $actor->getKey(),
            ]);

            $this->evaluator->evaluatePatient($entityId, $patientId, $today);

            return $row->refresh();
        });
    }

    /** Corrige olho e início de uma condição em aberto; ela passa a ser "do médico" (o detector não a toca mais). */
    public function update(string $entityId, string $conditionId, Eye $eye, ?CarbonImmutable $startedOn, ?CarbonImmutable $today = null): PatientCondition
    {
        $today = ($today ?? CarbonImmutable::now())->startOfDay();

        return $this->act($entityId, $conditionId, $today, function (PatientCondition $row) use ($eye, $startedOn, $today): void {
            $this->assertOpen($row);
            $this->assertStart($startedOn, $today);
            $this->assertNoOverlap((string) $row->entity_id, (string) $row->patient_id, (string) $row->protocol_key, $eye, (string) $row->id);

            $row->forceFill([
                'eye'        => $eye->value,
                'started_on' => $startedOn?->toDateString(),
                'origin'     => ConditionOrigin::Manual->value,
            ])->save();
        });
    }

    /** Sugerida → ativa: o médico confirma que o quadro é real; só a partir daqui o protocolo gera retorno. */
    public function confirm(string $entityId, string $conditionId, User $actor, ?CarbonImmutable $today = null): PatientCondition
    {
        return $this->act($entityId, $conditionId, $today, function (PatientCondition $row) use ($actor): void {
            $this->assertSuggested($row);

            $row->forceFill(['status' => ConditionStatus::Active->value, 'confirmed_at' => now(), 'confirmed_by' => $actor->getKey()])->save();
        });
    }

    /** Sugerida → descartada: não é este quadro; o sistema não a sugere de novo. */
    public function reject(string $entityId, string $conditionId, User $actor, ?CarbonImmutable $today = null): PatientCondition
    {
        return $this->act($entityId, $conditionId, $today, function (PatientCondition $row) use ($actor): void {
            $this->assertSuggested($row);

            $row->forceFill(['status' => ConditionStatus::Rejected->value, 'rejected_at' => now(), 'rejected_by' => $actor->getKey()])->save();
        });
    }

    /** Ativa → alta: o acompanhamento terminou; os retornos dela se encerram. O motivo é obrigatório. */
    public function discharge(string $entityId, string $conditionId, string $reason, User $actor, ?CarbonImmutable $today = null): PatientCondition
    {
        $reason = trim((string) preg_replace('/\s+/u', ' ', $reason));

        if (mb_strlen($reason) < self::REASON_MIN) {
            throw PatientConditionException::reasonRequired();
        }

        return $this->act($entityId, $conditionId, $today, function (PatientCondition $row) use ($reason, $actor): void {
            if ($row->status !== ConditionStatus::Active) {
                throw PatientConditionException::notActive($row->status->value);
            }

            $row->forceFill([
                'status'           => ConditionStatus::Discharged->value,
                'discharged_at'    => now(),
                'discharged_by'    => $actor->getKey(),
                'discharge_reason' => mb_substr($reason, 0, self::REASON_MAX),
            ])->save();
        });
    }

    // ── infraestrutura ──────────────────────────────────────────────────────

    /**
     * @param callable(PatientCondition): void $change recebe a condição FRESCA, travada, de paciente existente da clínica
     */
    private function act(string $entityId, string $conditionId, ?CarbonImmutable $today, callable $change): PatientCondition
    {
        $today = ($today ?? CarbonImmutable::now())->startOfDay();

        return DB::transaction(function () use ($entityId, $conditionId, $today, $change): PatientCondition {
            // O paciente vem de uma leitura SEM trava só para saber qual trava tomar; a linha é relida depois dela.
            $patientId = DB::table('patient_conditions')->where('id', $conditionId)->where('entity_id', $entityId)->value('patient_id');

            if (! is_string($patientId)) {
                throw PatientConditionException::notFound();
            }

            RecallLocks::patient($patientId);

            $row = PatientCondition::query()->withoutGlobalScopes()->where('entity_id', $entityId)->whereKey($conditionId)->lockForUpdate()->first();

            if ($row === null || ! $this->patientExists($entityId, (string) $row->patient_id)) {
                throw PatientConditionException::notFound();
            }

            $change($row);

            $this->evaluator->evaluatePatient($entityId, (string) $row->patient_id, $today);

            return $row->refresh();
        });
    }

    private function assertPatient(string $entityId, string $patientId): void
    {
        if (! $this->patientExists($entityId, $patientId)) {
            throw PatientConditionException::notFound();
        }
    }

    private function patientExists(string $entityId, string $patientId): bool
    {
        return DB::table('patients')->where('id', $patientId)->where('entity_id', $entityId)->whereNull('deleted_at')->exists();
    }

    /** A condição só gera retorno se a clínica usa o protocolo e a versão adotada vale agora (não está parada). */
    private function assertProtocolActive(string $entityId, string $protocolKey): void
    {
        $adoption = RecallClinicProtocol::query()->withoutGlobalScopes()
            ->where('entity_id', $entityId)
            ->whereNull('deactivated_at')
            ->whereHas('protocol', static fn ($query) => $query->where('key', $protocolKey))
            ->with('version')
            ->first();

        if ($adoption === null || $adoption->usableContent() === null) {
            throw PatientConditionException::protocolNotActive($protocolKey);
        }
    }

    private function assertStart(?CarbonImmutable $startedOn, CarbonImmutable $today): void
    {
        if ($startedOn === null) {
            return;
        }

        if ($startedOn->greaterThan($today)) {
            throw PatientConditionException::invalidDate('O início do acompanhamento não pode ser futuro.');
        }

        if ($startedOn->year < self::EARLIEST_YEAR) {
            throw PatientConditionException::invalidDate('Data de início inválida.');
        }
    }

    /** Duas condições em aberto do mesmo protocolo não podem cobrir o mesmo olho (duplicaria os retornos). */
    private function assertNoOverlap(string $entityId, string $patientId, string $protocolKey, Eye $eye, ?string $ignoreId): void
    {
        $open = PatientCondition::query()->withoutGlobalScopes()
            ->where('entity_id', $entityId)
            ->where('patient_id', $patientId)
            ->where('protocol_key', $protocolKey)
            ->open()
            ->when($ignoreId !== null, static fn ($query) => $query->where('id', '!=', $ignoreId))
            ->get();

        foreach ($open as $other) {
            if ($eye === Eye::Both || $other->eye === Eye::Both || $other->eye === $eye) {
                throw PatientConditionException::alreadyOpen($protocolKey);
            }
        }
    }

    private function assertOpen(PatientCondition $row): void
    {
        if (! $row->status->isOpen()) {
            throw PatientConditionException::notOpen($row->status->value);
        }
    }

    private function assertSuggested(PatientCondition $row): void
    {
        if ($row->status !== ConditionStatus::Suggested) {
            throw PatientConditionException::notSuggested($row->status->value);
        }
    }
}
