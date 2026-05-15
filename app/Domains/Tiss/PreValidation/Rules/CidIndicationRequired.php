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
            ? ($guide->operator?->trade_name ?? $guide->operator?->name ?? 'a operadora')
            : 'a operadora';

        $cid = trim((string) ($guide->clinical_indication ?? ''));

        if ($cid === '') {
            return [
                new TissGuideValidationIssue(
                    severity: TissValidationSeverity::Error,
                    code: 'CID_REQUIRED',
                    field: 'clinical_indication',
                    message: 'Código CID-10 não informado.',
                    suggestion: 'Informe o CID-10 correspondente ao diagnóstico (ex.: H40.1 para glaucoma, H25.9 para catarata).',
                    operatorHint: "Atenção: {$operatorName} vai glosar esta guia pois falta o CID-10.",
                ),
            ];
        }

        if (! preg_match(self::CID10_PATTERN, strtoupper($cid))) {
            return [
                new TissGuideValidationIssue(
                    severity: TissValidationSeverity::Error,
                    code: 'CID_FORMAT_INVALID',
                    field: 'clinical_indication',
                    message: "Código CID-10 \"{$cid}\" está em formato inválido.",
                    suggestion: 'O código CID deve seguir o padrão: uma letra maiúscula + 2 dígitos + sufixo opcional (ex.: H40.1, Z00.0, A09).',
                    operatorHint: "Atenção: {$operatorName} pode rejeitar esta guia por formato de CID inválido.",
                ),
            ];
        }

        return [];
    }
}
