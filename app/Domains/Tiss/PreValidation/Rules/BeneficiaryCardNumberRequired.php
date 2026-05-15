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
            ? ($guide->operator?->trade_name ?? $guide->operator?->name ?? 'a operadora')
            : 'a operadora';

        return [
            new TissGuideValidationIssue(
                severity: TissValidationSeverity::Error,
                code: 'BENEFICIARY_CARD_REQUIRED',
                field: 'beneficiary_card_number',
                message: 'Número da carteirinha do beneficiário não informado.',
                suggestion: 'Informe o número da carteirinha exatamente como consta no cartão do plano de saúde.',
                operatorHint: "Atenção: {$operatorName} vai glosar esta guia pois o número da carteirinha é obrigatório.",
            ),
        ];
    }
}
