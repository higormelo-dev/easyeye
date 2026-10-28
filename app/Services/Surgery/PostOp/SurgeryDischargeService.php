<?php

declare(strict_types=1);

namespace App\Services\Surgery\PostOp;

use App\Enums\Surgery\{PostOpEvaluationStatus, PostOpMilestone, SurgeryDescriptionStatus, SurgeryDocumentType, SurgeryEye, SurgicalCaseStatus};
use App\Exceptions\Surgery\SurgeryRuleException;
use App\Models\Surgery\{SurgicalCase, SurgicalDescription, SurgicalPostopEvaluation};
use App\Services\Surgery\{SurgeryDocumentIssueService, SurgeryParticipants, SurgicalCaseService};
use App\Support\CurrentDoctor;
use Illuminate\Support\{Collection, Number};
use Illuminate\Support\Facades\{DB, Log};
use Throwable;

/**
 * Alta cirúrgica (ADR 0003, D12): transição do caso `performed → discharged`
 * que só o CIRURGIÃO decide, depois de a avaliação do 1º dia (D1) e a descrição
 * cirúrgica estarem FINALIZADAS e sem rascunho de avaliação pendente.
 *
 * Tudo na MESMA transação, com a linha do caso travada e relida: o estado
 * ATUAL (nunca o do route binding) decide; o documento de alta congelado é
 * emitido pelo SurgeryDocumentIssueService e o caso muda de estado juntos.
 * Idempotente: caso já com alta devolve-se como está, sem segundo documento
 * (duas abas, clique duplo, duas requisições simultâneas).
 *
 * Os retornos futuros ainda abertos da Agenda são cancelados DEPOIS do commit
 * e fora da trava do caso (a Agenda tem a própria ordem de locks); se isso
 * falhar, a alta já vale e o erro vai ao log (sem dado do paciente).
 */
class SurgeryDischargeService
{
    public function __construct(
        private readonly SurgicalCaseService $cases,
        private readonly SurgeryDocumentIssueService $documents,
        private readonly PostOpReturnService $returns,
        private readonly SurgeryParticipants $participants,
    ) {
    }

    /**
     * O que falta para dar alta, em textos para a tela (lista vazia = pode).
     *
     * @return list<string>
     */
    public function blockers(SurgicalCase $case): array
    {
        $problems = [];

        if ($case->status === SurgicalCaseStatus::Discharged) {
            return $problems;
        }

        if ($case->status !== SurgicalCaseStatus::Performed) {
            return [(string) __('surgery_postop.errors.discharge_needs_performed', ['status' => $case->status->label()])];
        }

        $finalized = $this->finalizedD1Eyes($case);

        foreach ($this->operatedEyes($case) as $eye) {
            if (! in_array($eye->value, $finalized, true)) {
                $problems[] = (string) __('surgery_postop.errors.discharge_needs_d1', ['eye' => $eye->label()]);
            }
        }

        $description = SurgicalDescription::query()->where('surgical_case_id', $case->getKey())->first();

        if ($description === null || $description->status !== SurgeryDescriptionStatus::Finalized) {
            $problems[] = (string) __('surgery_postop.errors.discharge_needs_description');
        }

        if (SurgicalPostopEvaluation::query()
            ->where('surgical_case_id', $case->getKey())
            ->where('status', PostOpEvaluationStatus::Draft->value)
            ->exists()
        ) {
            $problems[] = (string) __('surgery_postop.errors.discharge_drafts_pending');
        }

        return $problems;
    }

    /**
     * @param array<string, mixed> $data discharge_text, follow_up_plan
     */
    public function discharge(SurgicalCase $case, array $data, ?int $expectedRevision = null): SurgicalCase
    {
        $doctor = CurrentDoctor::issuer();

        if ($doctor === null) {
            throw SurgeryRuleException::state('surgery.errors.document_needs_doctor');
        }

        $text = trim((string) ($data['discharge_text'] ?? ''));
        $plan = trim((string) ($data['follow_up_plan'] ?? ''));

        $result = DB::transaction(function () use ($case, $doctor, $text, $plan, $expectedRevision): array {
            $locked = $this->cases->lock((string) $case->getKey());

            // Idempotência: a outra requisição já deu a alta.
            if ($locked->status === SurgicalCaseStatus::Discharged) {
                return [$locked, false];
            }

            $this->cases->assertRevision($locked, $expectedRevision);

            if ($locked->status !== SurgicalCaseStatus::Performed) {
                throw SurgeryRuleException::state('surgery_postop.errors.discharge_needs_performed', ['status' => $locked->status->label()]);
            }

            // A decisão é do cirurgião do caso (ou cirurgião da equipe).
            $this->participants->assertSurgeon($locked, $doctor);

            $blockers = $this->blockers($locked);

            if ($blockers !== []) {
                throw new SurgeryRuleException($blockers[0], 409, ['blockers' => $blockers]);
            }

            if (mb_strlen($text) < 10) {
                throw SurgeryRuleException::invalid('surgery_postop.errors.discharge_text_required');
            }

            if (mb_strlen($plan) < 3) {
                throw SurgeryRuleException::invalid('surgery_postop.errors.follow_up_required');
            }

            $evaluations = $this->finalizedEvaluations($locked);

            $this->documents->issue(
                $locked,
                SurgeryDocumentType::Discharge,
                (string) __('surgery_postop.discharge.document_title'),
                $this->content($locked, $evaluations, $text, $plan),
                [
                    'items'          => $this->summaryItems($locked, $evaluations),
                    'evaluation_ids' => $evaluations->pluck('id')->map(fn ($id) => (string) $id)->all(),
                    'discharge_text' => $text,
                    'follow_up_plan' => $plan,
                ],
                scope: 'main',
            );

            $this->cases->changeStatus($locked, SurgicalCaseStatus::Discharged, null, ['discharged_at' => now()]);

            return [$locked, true];
        });

        [$discharged, $changed] = $result;

        if ($changed) {
            // Fora da trava do caso: Agenda tem a própria ordem de locks.
            DB::afterCommit(function () use ($discharged): void {
                try {
                    $this->returns->cancelOpen($discharged, (string) __('surgery_postop.discharge.cancel_reason'));
                } catch (Throwable $e) {
                    Log::warning('surgery.postop_cancel_open_failed', ['case_id' => (string) $discharged->getKey(), 'exception' => $e::class]);
                }
            });
        }

        return $discharged->refresh();
    }

    // ── Internos ────────────────────────────────────────────────────────────

    /** @return list<SurgeryEye> */
    private function operatedEyes(SurgicalCase $case): array
    {
        return $case->eye === SurgeryEye::Both ? [SurgeryEye::Right, SurgeryEye::Left] : [$case->eye];
    }

    /** @return list<string> olhos com avaliação D1 finalizada */
    private function finalizedD1Eyes(SurgicalCase $case): array
    {
        return SurgicalPostopEvaluation::query()
            ->where('surgical_case_id', $case->getKey())
            ->where('milestone', PostOpMilestone::D1->value)
            ->where('status', PostOpEvaluationStatus::Finalized->value)
            ->pluck('eye')
            ->map(fn ($eye) => $eye instanceof SurgeryEye ? $eye->value : (string) $eye)
            ->all();
    }

    /** @return Collection<int, SurgicalPostopEvaluation> */
    private function finalizedEvaluations(SurgicalCase $case): Collection
    {
        return SurgicalPostopEvaluation::query()
            ->where('surgical_case_id', $case->getKey())
            ->where('status', PostOpEvaluationStatus::Finalized->value)
            ->with(['vaUncorrected', 'vaCorrected', 'addenda'])
            ->orderBy('evaluated_at')
            ->get();
    }

    /**
     * Linhas {label, value} do bloco estruturado do PDF.
     *
     * @param Collection<int, SurgicalPostopEvaluation> $evaluations
     *
     * @return list<array{label: string, value: string}>
     */
    private function summaryItems(SurgicalCase $case, Collection $evaluations): array
    {
        $case->loadMissing('surgeon.person');

        return [
            ['label' => (string) __('surgery_postop.discharge.pdf_surgery'), 'value' => $case->title . ' (' . $case->eye->label() . ')'],
            ['label' => (string) __('surgery_postop.discharge.pdf_date'), 'value' => ($case->performed_at ?? now())->isoFormat('L')],
            ['label' => (string) __('surgery_postop.discharge.pdf_surgeon'), 'value' => (string) ($case->surgeon?->person?->full_name ?? '')],
            ['label' => (string) __('surgery_postop.discharge.pdf_evaluations'), 'value' => (string) $evaluations->count()],
        ];
    }

    /**
     * HTML congelado da alta (texto do cirurgião escapado; purificado na
     * emissão): evolução por marco + alta + plano de seguimento.
     *
     * @param Collection<int, SurgicalPostopEvaluation> $evaluations
     */
    private function content(SurgicalCase $case, Collection $evaluations, string $text, string $plan): string
    {
        $html = '<h3>' . e((string) __('surgery_postop.discharge.evolution_title')) . '</h3>';

        if ($evaluations->isEmpty()) {
            $html .= '<p>' . e((string) __('surgery_postop.discharge.no_evaluations')) . '</p>';
        } else {
            $html .= '<table border="1" cellpadding="4" cellspacing="0" style="width:100%"><thead><tr>'
                . '<th>' . e((string) __('surgery_postop.discharge.col_milestone')) . '</th>'
                . '<th>' . e((string) __('surgery_postop.discharge.col_eye')) . '</th>'
                . '<th>' . e((string) __('surgery_postop.discharge.col_va')) . '</th>'
                . '<th>' . e((string) __('surgery_postop.discharge.col_iop')) . '</th>'
                . '<th>' . e((string) __('surgery_postop.discharge.col_findings')) . '</th>'
                . '</tr></thead><tbody>';

            foreach ($evaluations as $evaluation) {
                $va = implode(' / ', array_filter([
                    $evaluation->vaUncorrected?->name ? (string) __('surgery_postop.discharge.va_sc', ['value' => $evaluation->vaUncorrected->name]) : null,
                    $evaluation->vaCorrected?->name ? (string) __('surgery_postop.discharge.va_cc', ['value' => $evaluation->vaCorrected->name]) : null,
                ]));

                $findings = implode(', ', array_map(fn ($f) => $f->label(), $evaluation->findingEnums()));

                $html .= '<tr><td>' . e($evaluation->milestone->short()) . ' — ' . e($evaluation->evaluated_at->isoFormat('L')) . '</td>'
                    . '<td>' . e($evaluation->eye->label()) . '</td>'
                    . '<td>' . e($va) . '</td>'
                    . '<td>' . e($evaluation->iop_mmhg !== null ? Number::format((float) $evaluation->iop_mmhg, 1, locale: app()->getLocale()) . ' mmHg' : '') . '</td>'
                    . '<td>' . e($findings) . '</td></tr>';
            }

            $html .= '</tbody></table>';
        }

        return $html
            . '<h3>' . e((string) __('surgery_postop.discharge.decision_title')) . '</h3><p>' . nl2br(e($text)) . '</p>'
            . '<h3>' . e((string) __('surgery_postop.discharge.follow_up_title')) . '</h3><p>' . nl2br(e($plan)) . '</p>';
    }
}
