<?php

declare(strict_types=1);

namespace App\Domains\Tiss\PreValidation\Rules;

use App\Domains\Tiss\Models\{TissGuide, TissGuideItem};
use App\Domains\Tiss\PreValidation\Contracts\TissGuideValidationRule;
use App\Domains\Tiss\PreValidation\Enums\TissValidationSeverity;
use App\Domains\Tiss\PreValidation\TissGuideValidationIssue;

final class ItemAmountsValid implements TissGuideValidationRule
{
    public function validate(TissGuide $guide): array
    {
        $items  = $guide->relationLoaded('items') ? $guide->items : $guide->items()->get();
        $issues = [];

        foreach ($items as $item) {
            /** @var TissGuideItem $item */
            $qty  = (float) ($item->quantity ?? 0);
            $unit = (float) ($item->unit_amount ?? 0);
            $code = $item->tuss_code ?? '?';

            if ($qty <= 0) {
                $issues[] = new TissGuideValidationIssue(
                    severity: TissValidationSeverity::Error,
                    code: 'ITEM_QUANTITY_ZERO',
                    field: "items.{$item->id}.quantity",
                    message: __('tiss_prevalidation.ITEM_QUANTITY_ZERO.message', ['code' => $code]),
                    suggestion: __('tiss_prevalidation.ITEM_QUANTITY_ZERO.suggestion'),
                );
            }

            if ($unit <= 0) {
                $issues[] = new TissGuideValidationIssue(
                    severity: TissValidationSeverity::Error,
                    code: 'ITEM_AMOUNT_ZERO',
                    field: "items.{$item->id}.unit_amount",
                    message: __('tiss_prevalidation.ITEM_AMOUNT_ZERO.message', ['code' => $code]),
                    suggestion: __('tiss_prevalidation.ITEM_AMOUNT_ZERO.suggestion'),
                );
            }
        }

        return $issues;
    }
}
