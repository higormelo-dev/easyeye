<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\AI\Models\{AiRun, AiRunFeedback};

/**
 * Persistência idempotente do feedback do médico após edição pesada do rascunho
 * (Onda 3, P5). Re-submit substitui o anterior.
 */
class AiFeedbackService
{
    public const ALLOWED_TAGS = [
        'diagnosis_wrong',
        'language',
        'missing_context',
        'excess',
        'other',
    ];

    /**
     * @param list<string> $tags
     */
    public function submit(AiRun $run, int $editRatioPercent, array $tags, ?string $note, ?string $doctorId): AiRunFeedback
    {
        $ratio = max(0, min(100, $editRatioPercent));

        return AiRunFeedback::query()->updateOrCreate(
            ['ai_run_id' => $run->id],
            [
                'entity_id'          => (string) $run->entity_id,
                'doctor_id'          => $doctorId,
                'edit_ratio_percent' => $ratio,
                'tags'               => array_values(array_intersect($tags, self::ALLOWED_TAGS)),
                'note'               => $note !== null ? mb_substr($note, 0, 2000) : null,
                'submitted_at'       => now(),
            ],
        );
    }
}
