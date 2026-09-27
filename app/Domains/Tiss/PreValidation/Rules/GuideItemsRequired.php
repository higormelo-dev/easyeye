<?php

declare(strict_types=1);

namespace App\Domains\Tiss\PreValidation\Rules;

use App\Domains\Tiss\Enums\TissGuideType;
use App\Domains\Tiss\Models\TissGuide;
use App\Domains\Tiss\PreValidation\Contracts\TissGuideValidationRule;
use App\Domains\Tiss\PreValidation\Enums\TissValidationSeverity;
use App\Domains\Tiss\PreValidation\TissGuideValidationIssue;

final class GuideItemsRequired implements TissGuideValidationRule
{
    public function validate(TissGuide $guide): array
    {
        $items = $guide->relationLoaded('items') ? $guide->items : $guide->items()->get();

        if ($items->isNotEmpty()) {
            return [];
        }

        $typeLabel    = __('tiss_prevalidation.guide_type.' . ($guide->guide_type === TissGuideType::Sadt ? 'sadt' : 'consultation'));
        $operatorName = $guide->relationLoaded('operator')
            ? ($guide->operator?->trade_name ?? $guide->operator?->name ?? __('tiss_prevalidation.operator_fallback'))
            : __('tiss_prevalidation.operator_fallback');

        return [
            new TissGuideValidationIssue(
                severity: TissValidationSeverity::Error,
                code: 'ITEMS_REQUIRED',
                field: 'items',
                message: __('tiss_prevalidation.ITEMS_REQUIRED.message', ['type' => $typeLabel]),
                suggestion: __('tiss_prevalidation.ITEMS_REQUIRED.suggestion'),
                operatorHint: __('tiss_prevalidation.ITEMS_REQUIRED.operator_hint', ['operator' => $operatorName]),
            ),
        ];
    }
}
