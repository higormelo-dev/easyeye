<?php

declare(strict_types=1);

namespace App\Domains\Recall\Enums;

/** Natureza de uma tarefa de protocolo de retorno. */
enum RecallTaskKind: string
{
    case Consultation    = 'consultation';
    case Exam            = 'exam';
    case TreatmentCourse = 'treatment_course';

    public function label(): string
    {
        return __('recall_protocol.task_kinds.' . $this->value);
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $kind): string => $kind->value, self::cases());
    }
}
