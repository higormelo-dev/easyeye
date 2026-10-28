<?php

declare(strict_types=1);

namespace App\Domains\Tiss\PreValidation\Rules;

use App\Domains\Tiss\Enums\TissGuideType;
use App\Domains\Tiss\Models\TissGuide;
use App\Domains\Tiss\PreValidation\Contracts\TissGuideValidationRule;
use App\Domains\Tiss\PreValidation\Enums\TissValidationSeverity;
use App\Domains\Tiss\PreValidation\TissGuideValidationIssue;

/**
 * Para guias SP-SADT a data de execução dos procedimentos é obrigatória no padrão TISS.
 */
final class SadtExecutionDateRequired implements TissGuideValidationRule
{
    public function validate(TissGuide $guide): array
    {
        if ($guide->guide_type !== TissGuideType::Sadt) {
            return [];
        }

        if (filled($guide->execution_date)) {
            return [];
        }

        $operatorName = $guide->relationLoaded('operator')
            ? ($guide->operator?->trade_name ?? $guide->operator?->name ?? __('tiss_prevalidation.operator_fallback'))
            : __('tiss_prevalidation.operator_fallback');

        return [
            new TissGuideValidationIssue(
                severity: TissValidationSeverity::Error,
                code: 'SADT_EXECUTION_DATE_REQUIRED',
                field: 'execution_date',
                message: __('tiss_prevalidation.SADT_EXECUTION_DATE_REQUIRED.message'),
                suggestion: __('tiss_prevalidation.SADT_EXECUTION_DATE_REQUIRED.suggestion'),
                operatorHint: __('tiss_prevalidation.SADT_EXECUTION_DATE_REQUIRED.operator_hint', ['operator' => $operatorName]),
            ),
        ];
    }
}
