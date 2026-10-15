<?php

declare(strict_types=1);

namespace App\Domains\Recall\Intravitreal\Enums;

/** Situação de uma aplicação: planejada → executada (ou cancelada / perdida). */
enum ApplicationStatus: string
{
    case Planned   = 'planned';
    case Performed = 'performed';
    case Cancelled = 'cancelled';
    case Missed    = 'missed';

    public function label(): string
    {
        return __('recall_course.application_status.' . $this->value);
    }
}
