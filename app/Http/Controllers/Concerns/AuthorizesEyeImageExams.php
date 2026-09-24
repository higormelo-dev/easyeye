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
     *
     * @return list<string>
     */
    private function ownedExamIds(array $examIds, string $entityId): array
    {
        $examIds = array_values(array_unique(array_filter(array_map('strval', $examIds))));

        if ($examIds === []) {
            return [];
        }

        $owned = PatientExam::query()
            ->whereIn('patient_exams.id', $examIds)
            ->whereHas('patient', fn ($q) => $q->where('entity_id', $entityId))
            ->pluck('patient_exams.id')
            ->map(fn ($id) => (string) $id)
            ->all();

        // Nunca falha silenciosamente com um exam_id de outra clínica: 403
        // explícito (mesmo padrão de AiPayloadEnricher::authorizeExamIds).
        abort_if(count($owned) !== count($examIds), 403);

        return $owned;
    }
}
