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

        $typeLabel    = $guide->guide_type === TissGuideType::Sadt ? 'SP-SADT' : 'Consulta';
        $operatorName = $guide->relationLoaded('operator')
            ? ($guide->operator?->trade_name ?? $guide->operator?->name ?? 'a operadora')
            : 'a operadora';

        return [
            new TissGuideValidationIssue(
                severity: TissValidationSeverity::Error,
                code: 'ITEMS_REQUIRED',
                field: 'items',
                message: "Guia de {$typeLabel} sem procedimentos cadastrados.",
                suggestion: 'Adicione pelo menos um procedimento com código TUSS, quantidade e valor.',
                operatorHint: "Atenção: {$operatorName} não paga guias sem procedimentos informados.",
            ),
        ];
    }
}
