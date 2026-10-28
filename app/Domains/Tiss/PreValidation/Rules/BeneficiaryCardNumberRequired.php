<?php

declare(strict_types=1);

namespace App\Domains\Tiss\PreValidation\Rules;

use App\Domains\Tiss\Models\TissGuide;
use App\Domains\Tiss\PreValidation\Contracts\TissGuideValidationRule;
use App\Domains\Tiss\PreValidation\Enums\TissValidationSeverity;
use App\Domains\Tiss\PreValidation\TissGuideValidationIssue;

final class BeneficiaryCardNumberRequired implements TissGuideValidationRule
{
    public function validate(TissGuide $guide): array
    {
        if (filled($guide->beneficiary_card_number)) {
            return [];
        }

        $operatorName = $guide->relationLoaded('operator')
            ? ($guide->operator?->trade_name ?? $guide->operator?->name ?? __('tiss_prevalidation.operator_fallback'))
            : __('tiss_prevalidation.operator_fallback');

        return [
            new TissGuideValidationIssue(
                severity: TissValidationSeverity::Error,
                code: 'BENEFICIARY_CARD_REQUIRED',
                field: 'beneficiary_card_number',
                message: __('tiss_prevalidation.BENEFICIARY_CARD_REQUIRED.message'),
                suggestion: __('tiss_prevalidation.BENEFICIARY_CARD_REQUIRED.suggestion'),
                operatorHint: __('tiss_prevalidation.BENEFICIARY_CARD_REQUIRED.operator_hint', ['operator' => $operatorName]),
            ),
        ];
    }
}
