<?php

declare(strict_types=1);

namespace App\Domains\Tiss\PreValidation\Rules;

use App\Domains\Tiss\Models\TissGuide;
use App\Domains\Tiss\PreValidation\Contracts\TissGuideValidationRule;
use App\Domains\Tiss\PreValidation\Enums\TissValidationSeverity;
use App\Domains\Tiss\PreValidation\TissGuideValidationIssue;

final class DoctorRequired implements TissGuideValidationRule
{
    public function validate(TissGuide $guide): array
    {
        if (filled($guide->doctor_id)) {
            return [];
        }

        $operatorName = $guide->relationLoaded('operator')
            ? ($guide->operator?->trade_name ?? $guide->operator?->name ?? 'a operadora')
            : 'a operadora';

        return [
            new TissGuideValidationIssue(
                severity: TissValidationSeverity::Error,
                code: 'DOCTOR_REQUIRED',
                field: 'doctor_id',
                message: 'Médico executante não vinculado à guia.',
                suggestion: 'Associe o médico responsável pelo atendimento. O CRM será incluído automaticamente no XML.',
                operatorHint: "Atenção: {$operatorName} exige o CRM do médico executante para processar o pagamento.",
            ),
        ];
    }
}
