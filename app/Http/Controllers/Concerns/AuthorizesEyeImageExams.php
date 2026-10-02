<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\PatientExam;

/**
 * Checagem de posse de tenant pra uma lista de exam_ids — compartilhada
 * entre controllers do Gerenciador de Imagens que recebem exam_ids[] no
 * payload (EyeImageReportController, EyeImageMontageController). Extraído
 * de EyeImageReportController::ownedExamIds() ao construir o Montage
 * (benchmark 18/09/2026) — mesmo comportamento, agora com 2 chamadores.
 */
trait AuthorizesEyeImageExams
{
    /**
     * @param array<int, mixed> $examIds
     * @param ?string           $patientId quando informado, todos os exames
     *                                     precisam ser desse paciente (laudo)
     *
     * @return list<string>
     */
    private function ownedExamIds(array $examIds, string $entityId, ?string $patientId = null): array
    {
        $examIds = array_values(array_unique(array_filter(array_map('strval', $examIds))));

        if ($examIds === []) {
            return [];
        }

        $owned = PatientExam::query()
            ->whereIn('patient_exams.id', $examIds)
            ->whereHas('patient', fn ($q) => $q->where('entity_id', $entityId))
            ->pluck('patient_id', 'patient_exams.id');

        // Nunca falha silenciosamente com um exam_id de outra clínica: 403
        // explícito (mesmo padrão de AiPayloadEnricher::authorizeExamIds).
        abort_if($owned->count() !== count($examIds), 403);

        // Exame de OUTRO paciente da mesma clínica num laudo: o documento
        // iria pro prontuário de um paciente citando exame de outro (LGPD).
        // 422 de regra, como o merge/split (ownedSamePatientExams).
        if ($patientId !== null) {
            abort_if(
                $owned->contains(fn ($examPatientId) => (string) $examPatientId !== $patientId),
                422,
                __('eye_images.report_same_patient'),
            );
        }

        return $owned->keys()->map(fn ($id) => (string) $id)->all();
    }
}
