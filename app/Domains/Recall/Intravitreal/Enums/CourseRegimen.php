<?php

declare(strict_types=1);

namespace App\Domains\Recall\Intravitreal\Enums;

/** O esquema do curso: ataque seguido de "trate e estenda", sob demanda (PRN) ou intervalo fixo. */
enum CourseRegimen: string
{
    case LoadingTae = 'loading_tae';
    case Prn        = 'prn';
    case Fixed      = 'fixed';

    public function label(): string
    {
        return __('recall_course.regimen.' . $this->value);
    }
}
