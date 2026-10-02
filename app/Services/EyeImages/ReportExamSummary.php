<?php

declare(strict_types=1);

namespace App\Services\EyeImages;

use App\Models\{MedicalRecordDocumentation, PatientExam};
use Illuminate\Support\Collection;

/**
 * Lista "quais exames fizeram parte do laudo" em forma legível (PDF do
 * laudo). Um exame, pro médico, é o GRUPO de imagens que a galeria do
 * Gerenciador de Imagens mostra junto — mesma chave de examGroupKey() em
 * Pages/Panel/EyeImages/Index.vue: sessão mesclada (exam_session_id) ou
 * data + equipamento + tipo. Cada grupo vira uma linha com os olhos e a
 * quantidade de imagens.
 */
class ReportExamSummary
{
    /**
     * @return list<array{type: ?string, date: ?string, equipment: ?string, eyes: list<string>, images: int}>
     */
    public function forDocumentation(MedicalRecordDocumentation $documentation): array
    {
        return $this->summarize(
            $documentation->patientExams()->with(['examType', 'equipment'])->get(),
        );
    }

    /**
     * @param Collection<int, PatientExam> $exams
     *
     * @return list<array{type: ?string, date: ?string, equipment: ?string, eyes: list<string>, images: int}>
     */
    public function summarize(Collection $exams): array
    {
        return $exams
            ->sortBy(fn (PatientExam $e) => ($e->exam_performed_at ?? $e->created_at)?->timestamp)
            ->groupBy(function (PatientExam $e) {
                $date = ($e->exam_performed_at ?? $e->created_at)?->toDateString() ?? '';

                return $e->exam_session_id
                    ? 's:' . $e->exam_session_id
                    : implode('|', [$date, $e->entity_integrator_equipment_id, $e->exam_id]);
            })
            ->map(function (Collection $group) {
                /** @var PatientExam $first */
                $first = $group->first();
                $date  = $first->exam_performed_at ?? $first->created_at;

                return [
                    'type'      => $first->examType?->name,
                    'date'      => $date?->isoFormat('L'),
                    'equipment' => $first->equipment?->name,
                    'eyes'      => $group->map(fn (PatientExam $e) => $this->eyeLabel($e->laterality))
                        ->unique()->sort()->values()->all(),
                    'images' => $group->count(),
                ];
            })
            ->values()
            ->all();
    }

    private function eyeLabel(?int $laterality): string
    {
        return match ($laterality) {
            1       => __('eye_images.eye_od'),
            2       => __('eye_images.eye_oe'),
            default => __('eye_images.eye_ao'),
        };
    }
}
