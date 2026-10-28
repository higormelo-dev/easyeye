<?php

declare(strict_types=1);

namespace App\Domains\ClinicalReview\Enums;

/** O aceite da EasyEye sobre o parecer: conferiu a identidade do Revisor ou não. */
enum AcceptanceDecision: string
{
    case Accepted = 'accepted';
    case Refused  = 'refused';

    public function label(): string
    {
        return __('clinical_review.acceptance.' . $this->value);
    }
}
