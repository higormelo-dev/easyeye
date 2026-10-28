<?php

declare(strict_types=1);

namespace App\Http\Controllers\Financial\Concerns;

use App\Domains\Tiss\Models\TissGuide;
use App\Domains\Tiss\PreValidation\Enums\TissValidationSeverity;
use App\Domains\Tiss\PreValidation\{TissGuideValidationIssue, TissGuideValidationResult};

/**
 * Resultado da pré-validação TISS no formato que a tela de Faturamento lê
 * (PreValidationResult.vue) — o mesmo de TissGuidePreValidateController.
 */
trait PresentsTissValidation
{
    /** @return array{passes: bool, errors: mixed, warnings: mixed, summary: string} */
    protected function validationPayload(TissGuideValidationResult $validation): array
    {
        return [
            'passes'   => $validation->passes(),
            'errors'   => $validation->errors()->map(fn (TissGuideValidationIssue $i) => $i->toArray())->values(),
            'warnings' => $validation->warnings()->map(fn (TissGuideValidationIssue $i) => $i->toArray())->values(),
            'summary'  => __('financial_billing.pre_validation.' . match (true) {
                $validation->isEmpty()   => 'ok',
                $validation->hasErrors() => 'errors',
                default                  => 'warnings',
            }),
        ];
    }

    /**
     * A última pré-validação deixou erro (não só aviso)? Guia com erro não
     * entra no lote TISS; com aviso entra. Formato de tiss_guides.errors:
     * lista de TissGuideValidationIssue::toArray() — item sem severidade
     * (legado) conta como erro.
     */
    protected function guideHasErrors(?TissGuide $guide): bool
    {
        $issues = $guide?->errors;

        if (! is_array($issues)) {
            return false;
        }

        foreach ($issues as $issue) {
            if (! is_array($issue) || ($issue['severity'] ?? TissValidationSeverity::Error->value) === TissValidationSeverity::Error->value) {
                return true;
            }
        }

        return false;
    }
}
