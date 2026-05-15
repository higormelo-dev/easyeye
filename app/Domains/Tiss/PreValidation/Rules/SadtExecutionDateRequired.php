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
            ? ($guide->operator?->trade_name ?? $guide->operator?->name ?? 'a operadora')
            : 'a operadora';

        return [
            new TissGuideValidationIssue(
                severity: TissValidationSeverity::Error,
                code: 'SADT_EXECUTION_DATE_REQUIRED',
                field: 'execution_date',
                message: 'Data de execução é obrigatória para guias SP-SADT.',
                suggestion: 'Informe a data em que os procedimentos foram realizados.',
                operatorHint: "Atenção: {$operatorName} exige a data de execução em guias SP-SADT.",
            ),
        ];
    }
}
