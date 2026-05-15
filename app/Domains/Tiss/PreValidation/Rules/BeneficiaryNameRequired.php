<?php

declare(strict_types=1);

namespace App\Domains\Tiss\PreValidation\Rules;

use App\Domains\Tiss\Models\TissGuide;
use App\Domains\Tiss\PreValidation\Contracts\TissGuideValidationRule;
use App\Domains\Tiss\PreValidation\Enums\TissValidationSeverity;
use App\Domains\Tiss\PreValidation\TissGuideValidationIssue;

final class BeneficiaryNameRequired implements TissGuideValidationRule
{
    public function validate(TissGuide $guide): array
    {
        if (filled($guide->beneficiary_name)) {
            return [];
        }

        return [
            new TissGuideValidationIssue(
                severity: TissValidationSeverity::Warning,
                code: 'BENEFICIARY_NAME_MISSING',
                field: 'beneficiary_name',
                message: 'Nome do beneficiário não informado.',
                suggestion: 'Informe o nome completo conforme consta no cartão do plano de saúde.',
            ),
        ];
    }
}
