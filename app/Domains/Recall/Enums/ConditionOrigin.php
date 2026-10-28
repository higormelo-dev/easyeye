<?php

declare(strict_types=1);

namespace App\Domains\Recall\Enums;

/** De onde veio uma condição: CID do prontuário, medicação em uso ou lançada à mão pelo médico. */
enum ConditionOrigin: string
{
    case Cid        = 'cid';
    case Medication = 'medication';
    case Manual     = 'manual';

    public function label(): string
    {
        return __('recall.condition_origin.' . $this->value);
    }
}
