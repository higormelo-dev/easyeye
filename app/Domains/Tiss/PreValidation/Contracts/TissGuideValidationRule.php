<?php

declare(strict_types=1);

namespace App\Domains\Tiss\PreValidation\Contracts;

use App\Domains\Tiss\Models\TissGuide;
use App\Domains\Tiss\PreValidation\TissGuideValidationIssue;

interface TissGuideValidationRule
{
    /**
     * @return list<TissGuideValidationIssue>
     */
    public function validate(TissGuide $guide): array;
}
