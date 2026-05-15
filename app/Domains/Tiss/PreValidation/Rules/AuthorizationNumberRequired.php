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
            ? ($guide->operator?->trade_name ?? $guide->operator?->name ?? 'a operadora')
            : 'a operadora';

        return [
            new TissGuideValidationIssue(
                severity: TissValidationSeverity::Error,
                code: 'AUTHORIZATION_REQUIRED',
                field: 'authorization_number',
                message: 'Número de autorização prévia não informado.',
                suggestion: 'Este convênio exige autorização prévia. Informe o número fornecido pela operadora.',
                operatorHint: "Atenção: {$operatorName} vai glosar esta guia pois o procedimento exige autorização prévia.",
            ),
        ];
    }
}
