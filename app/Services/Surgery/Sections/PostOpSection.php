<?php

declare(strict_types=1);

namespace App\Services\Surgery\Sections;

use App\Enums\Surgery\{PostOpAdherence, PostOpFinding, PostOpMilestone, PostOpReturnStatus, SurgeryDocumentType, SurgeryEye, SurgicalCaseStatus};
use App\Models\Surgery\{SurgeryDocumentIssue, SurgicalCase, SurgicalPostopEvaluation, SurgicalPostopEvaluationAddendum, SurgicalPostopReturn};
use App\Models\VisualAcuityType;
use App\Services\Surgery\PostOp\{PostOpEvaluationService, PostOpGuidanceService, PostOpReturnService};
use App\Services\Surgery\SurgicalCaseView;
use App\Support\CurrentDoctor;
use Illuminate\Support\Collection;

/**
 * Aba "Pós-operatório": retornos D1/D7/D30 da Agenda (com o que falhou e o
 * desvio da data-alvo), avaliações estruturadas por marco/olho com adendos,
 * alertas de complicação grave e a emissão de colírios/orientações.
 * Visível depois que a cirurgia foi realizada.
 */
final class PostOpSection implements SurgeryShowSection
{
    public function __construct(
        private readonly PostOpReturnService $returns,
        private readonly PostOpGuidanceService $guidance,
    ) {
    }

    /** Chave da aba: a tela do caso a lê do fragmento da URL (`#tab=postop`). */
    public const KEY = 'postop';

    public function key(): string
    {
        return self::KEY;
    }

    public function order(): int
    {
        return 70;
    }

    public function label(): string
    {
        return (string) __('surgery_postop.tab');
    }

    /** Destaque da aba: complicação grave marcada em alguma avaliação. @return array{tone: string, label: string}|null */
    public function badge(SurgicalCase $case): ?array
    {
        return SurgicalCaseView::severeCaseIds([(string) $case->getKey()]) !== []
            ? ['tone' => 'danger', 'label' => (string) __('surgery.ui.show.severe_badge')]
            : null;
    }

    public function visible(SurgicalCase $case, array $can): bool
    {
        return in_array($case->status, [SurgicalCaseStatus::Performed, SurgicalCaseStatus::Discharged], true);
    }

    public function props(SurgicalCase $case, array $can): array
    {
        $case->loadMissing('surgeryType');

        $returns     = $this->returns->plannedReturns($case);
        $evaluations = SurgicalPostopEvaluation::query()
            ->where('surgical_case_id', $case->getKey())
            ->with(['vaUncorrected', 'vaCorrected', 'doctor.person', 'addenda.doctor.person'])
            ->orderBy('evaluated_at')
            ->orderBy('created_at')
            ->get();

        $performed = $case->status === SurgicalCaseStatus::Performed;
        $myDoctor  = CurrentDoctor::issuer();
        $caseId    = (string) $case->getKey();

        return [
            'returns'     => $returns->map(fn (SurgicalPostopReturn $r) => $this->returnRow($r))->values()->all(),
            'evaluations' => $evaluations->map(fn (SurgicalPostopEvaluation $e) => $this->evaluationRow($e, $performed, $myDoctor?->getKey() !== null ? (string) $myDoctor->getKey() : null, $can))->values()->all(),
            'alerts'      => $this->alerts($case, $returns, $evaluations),
            'documents'   => $case->documents()
                ->where('type', SurgeryDocumentType::PostOpGuidance->value)
                ->with('issuerDoctor.person')
                ->get()
                ->map(fn (SurgeryDocumentIssue $d) => [
                    'id'        => (string) $d->getKey(),
                    'issued_at' => $d->issued_at->toIso8601String(),
                    'doctor'    => $d->issuerDoctor?->person?->full_name,
                    'current'   => $d->isCurrent(),
                    'eyedrops'  => (array) ($d->payload['data']['eyedrops'] ?? []),
                    'pdf_url'   => route('panel.surgery.postop.documents.pdf', ['surgicalCase' => $caseId, 'document' => (string) $d->getKey()]),
                ])->all(),
            'guidance_defaults' => $this->guidance->defaults($case),
            'category_label'    => $this->guidance->categoryLabel($case),
            'options'           => [
                'milestones'      => array_map(fn (PostOpMilestone $m) => ['value' => $m->value, 'label' => $m->label(), 'short' => $m->short()], PostOpMilestone::evaluable()),
                'eyes'            => $this->eyeOptions($case),
                'findings'        => array_map(fn (PostOpFinding $f) => ['value' => $f->value, 'label' => $f->label(), 'severe' => $f->isSevere()], PostOpFinding::cases()),
                'adherence'       => PostOpAdherence::options(),
                'visual_acuities' => $this->visualAcuities((string) $case->entity_id),
                'iop_attention'   => (int) config('surgery_postop.iop_attention', 30),
            ],
            'can' => [
                'evaluate' => $can['perform'] && $performed,
                'addendum' => $can['perform'],
                'guidance' => $can['perform'] && in_array($case->status, [SurgicalCaseStatus::Performed, SurgicalCaseStatus::Discharged], true),
                'retry'    => $can['manage'] && $performed && ($returns->contains(fn (SurgicalPostopReturn $r) => $r->status->isRetryable())
                || $evaluations->contains(fn (SurgicalPostopEvaluation $e) => $e->severe && $e->isFinalized() && ! $returns->contains(fn (SurgicalPostopReturn $r) => (string) $r->source_evaluation_id === (string) $e->getKey()))),
            ],
            'urls' => [
                'evaluation_store'    => route('panel.surgery.postop.evaluations.store', $caseId),
                'evaluation_update'   => route('panel.surgery.postop.evaluations.update', ['surgicalCase' => $caseId, 'evaluation' => '__ID__']),
                'evaluation_destroy'  => route('panel.surgery.postop.evaluations.destroy', ['surgicalCase' => $caseId, 'evaluation' => '__ID__']),
                'evaluation_finalize' => route('panel.surgery.postop.evaluations.finalize', ['surgicalCase' => $caseId, 'evaluation' => '__ID__']),
                'evaluation_addendum' => route('panel.surgery.postop.evaluations.addenda', ['surgicalCase' => $caseId, 'evaluation' => '__ID__']),
                'returns_retry'       => route('panel.surgery.postop.returns.retry', $caseId),
                'guidance_store'      => route('panel.surgery.postop.guidance.store', $caseId),
                'medicine_search'     => route('panel.medicines.search'),
                'schedule_attend'     => route('panel.schedules.attend', ['schedule' => '__ID__']),
                'schedule_show'       => route('panel.schedules.show', ['schedule' => '__ID__']),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function returnRow(SurgicalPostopReturn $r): array
    {
        $schedule = $r->schedule;

        // Fora do dia-alvo? Vale o horário VIVO do agendamento (a recepção pode ter movido o retorno
        // depois de agendado); o horário gravado no retorno é só o de reserva.
        $booked   = $schedule?->date_time ?? $r->scheduled_for;
        $deviated = $r->status === PostOpReturnStatus::Scheduled
            && $booked !== null
            && $booked->toDateString() !== $r->target_date->toDateString();

        return [
            'id'            => (string) $r->getKey(),
            'milestone'     => ['value' => $r->milestone->value, 'short' => $r->milestone->short(), 'label' => $r->milestone->label()],
            'target_date'   => $r->target_date->toDateString(),
            'scheduled_for' => $r->scheduled_for?->toIso8601String(),
            'deviated'      => $deviated,
            'status'        => ['value' => $r->status->value, 'label' => $r->status->label()],
            'failure_code'  => $r->failure_code,
            'failure_label' => $r->failure_code ? (string) __('surgery_postop.failures.' . $r->failure_code) : null,
            'schedule'      => $schedule === null ? null : [
                'id'        => (string) $schedule->getKey(),
                'date_time' => $schedule->date_time->toIso8601String(),
                'situation' => ['value' => $schedule->situation->value, 'label' => $schedule->situation->label(), 'badge' => $schedule->situation->badgeClass()],
            ],
        ];
    }

    /**
     * @param array<string, bool> $can
     *
     * @return array<string, mixed>
     */
    private function evaluationRow(SurgicalPostopEvaluation $e, bool $performed, ?string $myDoctorId, array $can): array
    {
        return [
            'id'                   => (string) $e->getKey(),
            'milestone'            => ['value' => $e->milestone->value, 'short' => $e->milestone->short(), 'label' => $e->milestone->label()],
            'eye'                  => ['value' => $e->eye->value, 'label' => $e->eye->label()],
            'evaluated_at'         => $e->evaluated_at->toIso8601String(),
            'va_uncorrected_id'    => $e->va_uncorrected_id,
            'va_corrected_id'      => $e->va_corrected_id,
            'va_uncorrected'       => $e->vaUncorrected?->name,
            'va_corrected'         => $e->vaCorrected?->name,
            'iop_mmhg'             => $e->iop_mmhg,
            'iop_attention'        => $e->iop_mmhg !== null && (float) $e->iop_mmhg >= (float) config('surgery_postop.iop_attention', 30),
            'findings'             => array_map(fn (PostOpFinding $f) => ['value' => $f->value, 'label' => $f->label(), 'severe' => $f->isSevere()], $e->findingEnums()),
            'findings_notes'       => $e->findings_notes,
            'refraction_sphere'    => $e->refraction_sphere,
            'refraction_cylinder'  => $e->refraction_cylinder,
            'refraction_axis'      => $e->refraction_axis,
            'spherical_equivalent' => $e->spherical_equivalent,
            'effective_equivalent' => PostOpEvaluationService::effectiveEquivalent($e),
            'complications'        => $e->complications,
            'adherence'            => $e->adherence ? ['value' => $e->adherence->value, 'label' => $e->adherence->label()] : null,
            'plan'                 => $e->plan,
            'severe'               => (bool) $e->severe,
            'status'               => ['value' => $e->status->value, 'label' => $e->status->label()],
            'finalized'            => $e->isFinalized(),
            'finalized_at'         => $e->finalized_at?->toIso8601String(),
            'doctor'               => $e->doctor?->person?->full_name,
            'schedule_id'          => $e->schedule_id,
            'revision'             => (int) $e->revision,
            'can_edit'             => $performed && ! $e->isFinalized() && $myDoctorId !== null && $myDoctorId === (string) $e->doctor_id,
            'addenda'              => $e->addenda->map(fn (SurgicalPostopEvaluationAddendum $a) => [
                'id'                   => (string) $a->getKey(),
                'text'                 => $a->text,
                'doctor'               => $a->doctor?->person?->full_name,
                'created_at'           => $a->created_at?->toIso8601String(),
                'corrected_equivalent' => $a->corrected_spherical_equivalent,
                'corrected_sphere'     => $a->corrected_sphere,
                'corrected_cylinder'   => $a->corrected_cylinder,
                'corrected_axis'       => $a->corrected_axis,
            ])->all(),
            'can_addendum' => $can['perform'] && $e->isFinalized(),
        ];
    }

    /**
     * Alertas da aba: complicação grave finalizada (com o estado do retorno
     * urgente) e retorno planejado que falhou ou ficou sem horário.
     *
     * @param Collection<int, SurgicalPostopReturn>     $returns
     * @param Collection<int, SurgicalPostopEvaluation> $evaluations
     *
     * @return list<array<string, mixed>>
     */
    private function alerts(SurgicalCase $case, $returns, $evaluations): array
    {
        $alerts = [];

        foreach ($evaluations->filter(fn (SurgicalPostopEvaluation $e) => $e->severe && $e->isFinalized()) as $e) {
            $urgent = $returns->first(fn (SurgicalPostopReturn $r) => (string) $r->source_evaluation_id === (string) $e->getKey());

            $alerts[] = [
                'type'          => 'severe',
                'evaluation_id' => (string) $e->getKey(),
                'milestone'     => $e->milestone->short(),
                'eye'           => $e->eye->label(),
                'findings'      => array_map(fn (PostOpFinding $f) => $f->label(), array_filter($e->findingEnums(), fn (PostOpFinding $f) => $f->isSevere())),
                'urgent_return' => $urgent === null ? null : $this->returnRow($urgent),
            ];
        }

        foreach ($returns as $r) {
            if ($r->status === PostOpReturnStatus::Failed && $r->milestone !== PostOpMilestone::Urgent) {
                $alerts[] = ['type' => 'return_failed', 'milestone' => $r->milestone->short(), 'reason' => (string) __('surgery_postop.failures.' . ($r->failure_code ?? 'error'))];
            }
        }

        return $alerts;
    }

    /** @return list<array{value: string, label: string}> */
    private function eyeOptions(SurgicalCase $case): array
    {
        $eyes = $case->eye === SurgeryEye::Both ? [SurgeryEye::Right, SurgeryEye::Left] : [$case->eye];

        return array_map(fn (SurgeryEye $e) => ['value' => $e->value, 'label' => $e->label(), 'short' => $e->short()], $eyes);
    }

    /** @return list<array{id: string, name: string}> */
    private function visualAcuities(string $entityId): array
    {
        return VisualAcuityType::query()
            ->where(fn ($q) => $q->where('entity_id', $entityId)->orWhereNull('entity_id'))
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (VisualAcuityType $v) => ['id' => (string) $v->getKey(), 'name' => (string) $v->name])
            ->all();
    }
}
