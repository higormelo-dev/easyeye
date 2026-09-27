<?php

declare(strict_types=1);

namespace App\Domains\Tiss\PreValidation\Rules;

use App\Domains\Tiss\Models\TissGuide;
use App\Domains\Tiss\PreValidation\Contracts\TissGuideValidationRule;
use App\Domains\Tiss\PreValidation\Enums\TissValidationSeverity;
use App\Domains\Tiss\PreValidation\TissGuideValidationIssue;

/**
 * Bloqueia guias sem número de autorização quando o contrato exige autorização prévia.
 */
final class AuthorizationNumberRequired implements TissGuideValidationRule
{
    public function validate(TissGuide $guide): array
    {
        $contract = $guide->relationLoaded('contract') ? $guide->contract : $guide->contract()->first();

        if (! $contract || ! $contract->requires_authorization) {
            return [];
        }

        if (filled($guide->authorization_number)) {
            return [];
        }

        $operatorName = $guide->relationLoaded('operator')
            ? ($guide->operator?->trade_name ?? $guide->operator?->name ?? __('tiss_prevalidation.operator_fallback'))
            : __('tiss_prevalidation.operator_fallback');

        return [
            new TissGuideValidationIssue(
                severity: TissValidationSeverity::Error,
                code: 'AUTHORIZATION_REQUIRED',
                field: 'authorization_number',
                message: __('tiss_prevalidation.AUTHORIZATION_REQUIRED.message'),
                suggestion: __('tiss_prevalidation.AUTHORIZATION_REQUIRED.suggestion'),
                operatorHint: __('tiss_prevalidation.AUTHORIZATION_REQUIRED.operator_hint', ['operator' => $operatorName]),
            ),
        ];
    }
}
