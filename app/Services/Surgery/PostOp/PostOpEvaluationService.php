<?php

declare(strict_types=1);

namespace App\Services\Surgery\PostOp;

use App\Enums\Surgery\{PostOpAdherence, PostOpEvaluationStatus, PostOpFinding, PostOpMilestone, SurgeryEye, SurgicalCaseStatus};
use App\Exceptions\Surgery\SurgeryRuleException;
use App\Models\{Doctor, VisualAcuityType};
use App\Models\Schedule;
use App\Models\Surgery\{SurgicalCase, SurgicalPostopEvaluation, SurgicalPostopEvaluationAddendum};
use App\Services\Surgery\SurgicalCaseService;
use App\Support\CurrentDoctor;
use App\Support\Surgery\{CanonicalHash, LocalTime};
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\{DB, Log};
use Throwable;

/**
 * Avaliação pós-operatória estruturada (ADR 0003, D12): AV, PIO, achados e
 * refração residual por marco e olho. Ato do médico (Gate PerformSurgery).
 *
 * Rascunho editável só pelo médico que o abriu; FINALIZADA é imutável (hash do
 * conteúdo clínico, `SurgicalPostopEvaluation` recusa o UPDATE) e a correção
 * vira adendo. Tudo sob a trava da linha do caso (relida: o caso precisa estar
 * REALIZADO — não vale o estado da instância do route binding) e, depois, da
 * linha da avaliação (ordem do ADR §5: caso → filhos).
 *
 * Complicação grave (endoftalmite suspeita, LIO deslocada) é calculada NO
 * SERVIDOR a partir dos achados — nunca de um campo do cliente — e, ao
 * finalizar, pede o retorno em 24 h (PostOpReturnService::requestUrgent), que
 * roda depois do commit, fora das travas.
 */
class PostOpEvaluationService
{
    public function __construct(
        private readonly SurgicalCaseService $cases,
        private readonly PostOpReturnService $returns,
    ) {
    }

    // ── Rascunho ────────────────────────────────────────────────────────────

    /**
     * Cria o rascunho (marco + olho) ou edita o existente.
     *
     * @param array<string, mixed> $data
     */
    public function saveDraft(SurgicalCase $case, array $data, ?SurgicalPostopEvaluation $evaluation = null, ?int $expectedRevision = null): SurgicalPostopEvaluation
    {
        $doctor = $this->performer();

        return DB::transaction(function () use ($case, $data, $evaluation, $expectedRevision, $doctor): SurgicalPostopEvaluation {
            $locked = $this->cases->lock((string) $case->getKey());

            $this->assertPerformed($locked);

            $attributes = $this->normalize($locked, $data);

            if ($evaluation === null) {
                $this->assertNoDuplicate($locked, $attributes['milestone'], $attributes['eye']);

                try {
                    $created = SurgicalPostopEvaluation::query()->create([
                        ...$attributes,
                        'entity_id'        => $locked->entity_id,
                        'surgical_case_id' => $locked->getKey(),
                        'patient_id'       => $locked->patient_id,
                        'doctor_id'        => $doctor->getKey(),
                        'schedule_id'      => $this->scheduleOf($locked, $data['schedule_id'] ?? null),
                    ]);
                } catch (UniqueConstraintViolationException) {
                    throw SurgeryRuleException::state('surgery_postop.errors.evaluation_exists');
                }

                return $created;
            }

            $row = SurgicalPostopEvaluation::query()
                ->where('surgical_case_id', $locked->getKey())
                ->whereKey($evaluation->getKey())
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                throw SurgeryRuleException::state('surgery_postop.errors.evaluation_not_found');
            }

            $this->assertEditableBy($row, $doctor);

            if ($expectedRevision !== null && (int) $row->revision !== $expectedRevision) {
                throw SurgeryRuleException::state('surgery.errors.stale_revision');
            }

            if (($attributes['milestone'] !== $row->milestone || $attributes['eye'] !== $row->eye)) {
                $this->assertNoDuplicate($locked, $attributes['milestone'], $attributes['eye'], $row->getKey());
            }

            try {
                $row->forceFill([
                    ...$attributes,
                    'schedule_id' => array_key_exists('schedule_id', $data) ? $this->scheduleOf($locked, $data['schedule_id']) : $row->schedule_id,
                    'revision'    => $row->revision + 1,
                ])->save();
            } catch (UniqueConstraintViolationException) {
                throw SurgeryRuleException::state('surgery_postop.errors.evaluation_exists');
            }

            return $row;
        });
    }

    /** Apaga o rascunho (finalizada nunca é apagada). */
    public function deleteDraft(SurgicalCase $case, SurgicalPostopEvaluation $evaluation): void
    {
        $doctor = $this->performer();

        DB::transaction(function () use ($case, $evaluation, $doctor): void {
            $locked = $this->cases->lock((string) $case->getKey());

            $row = SurgicalPostopEvaluation::query()
                ->where('surgical_case_id', $locked->getKey())
                ->whereKey($evaluation->getKey())
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                throw SurgeryRuleException::state('surgery_postop.errors.evaluation_not_found');
            }

            $this->assertEditableBy($row, $doctor);
            $row->delete();
        });
    }

    // ── Finalizar ───────────────────────────────────────────────────────────

    public function finalize(SurgicalCase $case, SurgicalPostopEvaluation $evaluation, ?int $expectedRevision = null): SurgicalPostopEvaluation
    {
        $doctor = $this->performer();

        return DB::transaction(function () use ($case, $evaluation, $expectedRevision, $doctor): SurgicalPostopEvaluation {
            $locked = $this->cases->lock((string) $case->getKey());

            $this->assertPerformed($locked);

            $row = SurgicalPostopEvaluation::query()
                ->where('surgical_case_id', $locked->getKey())
                ->whereKey($evaluation->getKey())
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                throw SurgeryRuleException::state('surgery_postop.errors.evaluation_not_found');
            }

            // Finalizar de novo (clique duplo, duas abas, retentativa) devolve a que já está pronta —
            // e, se for grave, repete o pedido do retorno urgente (idempotente: um por avaliação).
            if ($row->isFinalized()) {
                if ($row->severe) {
                    $this->requestUrgentSafely($row);
                }

                return $row;
            }

            $this->assertEditableBy($row, $doctor);

            if ($expectedRevision !== null && (int) $row->revision !== $expectedRevision) {
                throw SurgeryRuleException::state('surgery.errors.stale_revision');
            }

            if (! $this->hasClinicalContent($row)) {
                throw SurgeryRuleException::invalid('surgery_postop.errors.evaluation_empty');
            }

            $row->forceFill([
                'status'                 => PostOpEvaluationStatus::Finalized,
                'finalized_at'           => now(),
                'finalized_by_doctor_id' => $doctor->getKey(),
                'content_hash'           => $this->hash($row),
                'revision'               => $row->revision + 1,
            ])->save();

            // Complicação grave pede o retorno em 24 h — depois do commit, sem travas.
            if ($row->severe) {
                $this->requestUrgentSafely($row);
            }

            return $row;
        });
    }

    /**
     * Pede o retorno urgente DEPOIS do commit. Falha aqui não pode virar 500 com a
     * avaliação já finalizada: vai ao log (sem dado do paciente) e o pedido é
     * repetido por `PostOpReturnService::retry()` ("tentar de novo" da aba) ou
     * por uma nova chamada de finalizar (idempotente).
     */
    private function requestUrgentSafely(SurgicalPostopEvaluation $evaluation): void
    {
        DB::afterCommit(function () use ($evaluation): void {
            try {
                $this->returns->requestUrgent($evaluation->refresh());
            } catch (Throwable $e) {
                Log::warning('surgery.postop_urgent_return_failed', [
                    'evaluation_id' => (string) $evaluation->getKey(),
                    'exception'     => $e::class,
                ]);
            }
        });
    }

    // ── Adendo ──────────────────────────────────────────────────────────────

    /**
     * Correção/complemento de uma avaliação FINALIZADA. Pode trazer a refração
     * corrigida (esfera/cilindro/eixo), que os indicadores passam a usar.
     *
     * @param array<string, mixed> $data text, corrected_sphere, corrected_cylinder, corrected_axis
     */
    public function addAddendum(SurgicalCase $case, SurgicalPostopEvaluation $evaluation, array $data): SurgicalPostopEvaluationAddendum
    {
        $doctor = $this->performer();

        return DB::transaction(function () use ($case, $evaluation, $data, $doctor): SurgicalPostopEvaluationAddendum {
            $locked = $this->cases->lock((string) $case->getKey());

            if ($locked->status->isFinal() && $locked->status !== SurgicalCaseStatus::Discharged) {
                throw SurgeryRuleException::state('surgery.errors.case_closed');
            }

            $row = SurgicalPostopEvaluation::query()
                ->where('surgical_case_id', $locked->getKey())
                ->whereKey($evaluation->getKey())
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                throw SurgeryRuleException::state('surgery_postop.errors.evaluation_not_found');
            }

            if (! $row->isFinalized()) {
                throw SurgeryRuleException::state('surgery_postop.errors.addendum_needs_finalized');
            }

            $text = trim((string) ($data['text'] ?? ''));

            if (mb_strlen($text) < 3) {
                throw SurgeryRuleException::invalid('surgery_postop.errors.addendum_text_required');
            }

            $sphere   = $this->decimal($data['corrected_sphere'] ?? null);
            $cylinder = $this->decimal($data['corrected_cylinder'] ?? null);
            $axis     = filled($data['corrected_axis'] ?? null) ? (int) $data['corrected_axis'] : null;

            if ($sphere === null && ($cylinder !== null || $axis !== null)) {
                throw SurgeryRuleException::invalid('surgery_postop.errors.refraction_needs_sphere');
            }

            if ($cylinder !== null && $cylinder != 0 && $axis === null) {
                throw SurgeryRuleException::invalid('surgery_postop.errors.axis_required');
            }

            $equivalent = $sphere !== null ? $this->equivalent($sphere, $cylinder) : null;

            return SurgicalPostopEvaluationAddendum::query()->create([
                'entity_id'                      => $locked->entity_id,
                'surgical_postop_evaluation_id'  => $row->getKey(),
                'surgical_case_id'               => $locked->getKey(),
                'doctor_id'                      => $doctor->getKey(),
                'text'                           => $text,
                'corrected_sphere'               => $sphere,
                'corrected_cylinder'             => $cylinder,
                'corrected_axis'                 => $axis,
                'corrected_spherical_equivalent' => $equivalent,
                'content_hash'                   => CanonicalHash::of([
                    'v'          => CanonicalHash::VERSION,
                    'evaluation' => (string) $row->getKey(),
                    'eval_hash'  => $row->content_hash,
                    'text'       => $text,
                    'sphere'     => $sphere,
                    'cylinder'   => $cylinder,
                    'axis'       => $axis,
                ]),
            ]);
        });
    }

    // ── Leitura ─────────────────────────────────────────────────────────────

    /**
     * Refração que vale para os indicadores: a do adendo mais recente que a
     * corrigiu; senão a da própria avaliação.
     */
    public static function effectiveEquivalent(SurgicalPostopEvaluation $evaluation): ?float
    {
        $evaluation->loadMissing('addenda');

        $corrected = $evaluation->addenda
            ->filter(fn (SurgicalPostopEvaluationAddendum $a) => $a->corrected_spherical_equivalent !== null)
            ->last();

        $value = $corrected?->corrected_spherical_equivalent ?? $evaluation->spherical_equivalent;

        return $value === null ? null : (float) $value;
    }

    /** SE = esfera + cilindro/2, em 2 casas. */
    public function equivalent(float $sphere, ?float $cylinder): float
    {
        return round($sphere + (($cylinder ?? 0.0) / 2), 2);
    }

    // ── Internos ────────────────────────────────────────────────────────────

    private function performer(): Doctor
    {
        $doctor = CurrentDoctor::issuer();

        if ($doctor === null) {
            throw SurgeryRuleException::state('surgery.errors.document_needs_doctor');
        }

        return $doctor;
    }

    private function assertPerformed(SurgicalCase $locked): void
    {
        if ($locked->status !== SurgicalCaseStatus::Performed) {
            throw SurgeryRuleException::state('surgery_postop.errors.case_not_performed', ['status' => $locked->status->label()]);
        }
    }

    private function assertEditableBy(SurgicalPostopEvaluation $row, Doctor $doctor): void
    {
        if ($row->isFinalized()) {
            throw SurgeryRuleException::state('surgery_postop.errors.evaluation_finalized');
        }

        if ((string) $row->doctor_id !== (string) $doctor->getKey()) {
            throw SurgeryRuleException::state('surgery_postop.errors.not_author');
        }
    }

    private function assertNoDuplicate(SurgicalCase $locked, PostOpMilestone $milestone, SurgeryEye $eye, ?string $exceptId = null): void
    {
        if ($milestone === PostOpMilestone::Other) {
            return;
        }

        $exists = SurgicalPostopEvaluation::query()
            ->where('surgical_case_id', $locked->getKey())
            ->where('milestone', $milestone->value)
            ->where('eye', $eye->value)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->exists();

        if ($exists) {
            throw SurgeryRuleException::state('surgery_postop.errors.evaluation_exists');
        }
    }

    /**
     * Valida e normaliza o conteúdo clínico (nada de campo do cliente além do
     * que a regra aceita; `severe` e o equivalente esférico são calculados aqui).
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function normalize(SurgicalCase $locked, array $data): array
    {
        $milestone = PostOpMilestone::tryFrom((string) ($data['milestone'] ?? ''));

        if ($milestone === null || ! in_array($milestone, PostOpMilestone::evaluable(), true)) {
            throw SurgeryRuleException::invalid('surgery_postop.errors.milestone_invalid');
        }

        $eye = SurgeryEye::tryFrom((string) ($data['eye'] ?? ''));

        if ($eye === null || $eye === SurgeryEye::Both || ($locked->eye !== SurgeryEye::Both && $locked->eye !== $eye)) {
            throw SurgeryRuleException::invalid('surgery_postop.errors.eye_invalid');
        }

        // A tela manda ISO em UTC ('...Z'): converte para o fuso da aplicação antes de gravar/comparar.
        $evaluatedAt = LocalTime::parse($data['evaluated_at'] ?? null) ?? now();

        if ($evaluatedAt->greaterThan(now()->addMinutes(5))) {
            throw SurgeryRuleException::invalid('surgery_postop.errors.evaluated_at_future');
        }

        if ($locked->performed_at !== null && $evaluatedAt->lt($locked->performed_at->copy()->startOfDay())) {
            throw SurgeryRuleException::invalid('surgery_postop.errors.evaluated_before_surgery');
        }

        $findings = array_values(array_unique(array_map('strval', (array) ($data['findings'] ?? []))));

        foreach ($findings as $code) {
            if (PostOpFinding::tryFrom($code) === null) {
                throw SurgeryRuleException::invalid('surgery_postop.errors.finding_invalid');
            }
        }

        $notes = $this->text($data['findings_notes'] ?? null);

        if (in_array(PostOpFinding::Other->value, $findings, true) && $notes === null) {
            throw SurgeryRuleException::invalid('surgery_postop.errors.other_finding_needs_notes');
        }

        $iop = $this->decimal($data['iop_mmhg'] ?? null);

        if ($iop !== null && ($iop < 0 || $iop > 80)) {
            throw SurgeryRuleException::invalid('surgery_postop.errors.iop_range');
        }

        $sphere   = $this->decimal($data['refraction_sphere'] ?? null);
        $cylinder = $this->decimal($data['refraction_cylinder'] ?? null);
        $axis     = filled($data['refraction_axis'] ?? null) ? (int) $data['refraction_axis'] : null;

        if ($sphere === null && ($cylinder !== null || $axis !== null)) {
            throw SurgeryRuleException::invalid('surgery_postop.errors.refraction_needs_sphere');
        }

        if ($sphere !== null && ($sphere < -30 || $sphere > 30 || ($cylinder !== null && abs($cylinder) > 15))) {
            throw SurgeryRuleException::invalid('surgery_postop.errors.refraction_range');
        }

        if ($cylinder !== null && $cylinder != 0 && $axis === null) {
            throw SurgeryRuleException::invalid('surgery_postop.errors.axis_required');
        }

        if ($axis !== null && ($axis < 0 || $axis > 180)) {
            throw SurgeryRuleException::invalid('surgery_postop.errors.axis_required');
        }

        $adherence = filled($data['adherence'] ?? null) ? PostOpAdherence::tryFrom((string) $data['adherence']) : null;

        if (filled($data['adherence'] ?? null) && $adherence === null) {
            throw SurgeryRuleException::invalid('surgery_postop.errors.adherence_invalid');
        }

        $entityId = (string) $locked->entity_id;

        return [
            'milestone'            => $milestone,
            'eye'                  => $eye,
            'evaluated_at'         => $evaluatedAt,
            'va_uncorrected_id'    => $this->visualAcuity($entityId, $data['va_uncorrected_id'] ?? null),
            'va_corrected_id'      => $this->visualAcuity($entityId, $data['va_corrected_id'] ?? null),
            'iop_mmhg'             => $iop,
            'findings'             => $findings,
            'findings_notes'       => $notes,
            'refraction_sphere'    => $sphere,
            'refraction_cylinder'  => $cylinder,
            'refraction_axis'      => $axis,
            'spherical_equivalent' => $sphere !== null ? $this->equivalent($sphere, $cylinder) : null,
            'complications'        => $this->text($data['complications'] ?? null),
            'adherence'            => $adherence,
            'plan'                 => $this->text($data['plan'] ?? null),
            'severe'               => $this->isSevere($findings),
        ];
    }

    /** @param list<string> $findings */
    private function isSevere(array $findings): bool
    {
        return count(array_intersect($findings, PostOpFinding::severeValues())) > 0;
    }

    private function hasClinicalContent(SurgicalPostopEvaluation $row): bool
    {
        return $row->va_uncorrected_id !== null
            || $row->va_corrected_id !== null
            || $row->iop_mmhg !== null
            || (array) $row->findings !== []
            || $row->spherical_equivalent !== null
            || filled($row->complications)
            || filled($row->plan);
    }

    private function hash(SurgicalPostopEvaluation $row): string
    {
        return CanonicalHash::of([
            'v'         => CanonicalHash::VERSION,
            'id'        => (string) $row->getKey(),
            'case'      => (string) $row->surgical_case_id,
            'patient'   => (string) $row->patient_id,
            'doctor'    => (string) $row->doctor_id,
            'milestone' => $row->milestone->value,
            'eye'       => $row->eye->value,
            'evaluated' => $row->evaluated_at->toIso8601String(),
            'va_u'      => $row->va_uncorrected_id,
            'va_c'      => $row->va_corrected_id,
            'iop'       => $row->iop_mmhg,
            'findings'  => $row->findings,
            'notes'     => $row->findings_notes,
            'sphere'    => $row->refraction_sphere,
            'cylinder'  => $row->refraction_cylinder,
            'axis'      => $row->refraction_axis,
            'se'        => $row->spherical_equivalent,
            'compl'     => $row->complications,
            'adherence' => $row->adherence?->value,
            'plan'      => $row->plan,
            'severe'    => $row->severe,
        ]);
    }

    /** Acuidade visual da clínica ou global e ativa; de outra clínica, nunca. */
    private function visualAcuity(string $entityId, mixed $id): ?string
    {
        if (blank($id)) {
            return null;
        }

        $ok = VisualAcuityType::query()
            ->where(fn ($q) => $q->where('entity_id', $entityId)->orWhereNull('entity_id'))
            ->where('active', true)
            ->whereKey($id)
            ->exists();

        if (! $ok) {
            throw SurgeryRuleException::invalid('surgery_postop.errors.visual_acuity_invalid');
        }

        return (string) $id;
    }

    /** O retorno da Agenda vinculado precisa ser deste caso. */
    private function scheduleOf(SurgicalCase $locked, mixed $scheduleId): ?string
    {
        if (blank($scheduleId)) {
            return null;
        }

        $ok = Schedule::query()
            ->where('entity_id', $locked->entity_id)
            ->where('surgical_case_id', $locked->getKey())
            ->whereKey($scheduleId)
            ->exists();

        if (! $ok) {
            throw SurgeryRuleException::invalid('surgery_postop.errors.schedule_invalid');
        }

        return (string) $scheduleId;
    }

    private function decimal(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) str_replace(',', '.', (string) $value), 2);
    }

    private function text(mixed $value): ?string
    {
        $text = trim((string) Arr::get(['v' => $value], 'v', ''));

        return $text === '' ? null : mb_substr($text, 0, 5000);
    }
}
