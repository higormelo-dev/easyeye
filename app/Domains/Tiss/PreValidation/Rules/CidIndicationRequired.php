<?php

declare(strict_types=1);

namespace App\Domains\Tiss\PreValidation\Rules;

use App\Domains\Tiss\Models\TissGuide;
use App\Domains\Tiss\PreValidation\Contracts\TissGuideValidationRule;
use App\Domains\Tiss\PreValidation\Enums\TissValidationSeverity;
use App\Domains\Tiss\PreValidation\TissGuideValidationIssue;

/**
 * Valida que o campo clinical_indication contém um código CID-10 válido.
 * O campo é obrigatório e deve seguir o padrão da ANS: letra + 2 dígitos + sufixo opcional.
 * Exemplos válidos: H40.1, Z00.0, A09, H52.1, H25.9.
 */
final class CidIndicationRequired implements TissGuideValidationRule
{
    private const CID10_PATTERN = '/^[A-Z]\d{2}(\.\d{1,2})?$/';

    public function validate(TissGuide $guide): array
    {
        $operatorName = $guide->relationLoaded('operator')
            ? ($guide->operator?->trade_name ?? $guide->operator?->name ?? __('tiss_prevalidation.operator_fallback'))
            : __('tiss_prevalidation.operator_fallback');

        $cid = trim((string) ($guide->clinical_indication ?? ''));

        if ($cid === '') {
            return [
                new TissGuideValidationIssue(
                    severity: TissValidationSeverity::Error,
                    code: 'CID_REQUIRED',
                    field: 'clinical_indication',
                    message: __('tiss_prevalidation.CID_REQUIRED.message'),
                    suggestion: __('tiss_prevalidation.CID_REQUIRED.suggestion'),
                    operatorHint: __('tiss_prevalidation.CID_REQUIRED.operator_hint', ['operator' => $operatorName]),
                ),
            ];
        }

        if (! preg_match(self::CID10_PATTERN, strtoupper($cid))) {
            return [
                new TissGuideValidationIssue(
                    severity: TissValidationSeverity::Error,
                    code: 'CID_FORMAT_INVALID',
                    field: 'clinical_indication',
                    message: __('tiss_prevalidation.CID_FORMAT_INVALID.message', ['cid' => $cid]),
                    suggestion: __('tiss_prevalidation.CID_FORMAT_INVALID.suggestion'),
                    operatorHint: __('tiss_prevalidation.CID_FORMAT_INVALID.operator_hint', ['operator' => $operatorName]),
                ),
            ];
        }

        return [];
    }
}
