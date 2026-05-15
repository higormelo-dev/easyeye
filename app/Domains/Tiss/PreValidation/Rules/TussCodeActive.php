<?php

declare(strict_types=1);

namespace App\Domains\Tiss\PreValidation\Rules;

use App\Domains\Tiss\Models\{TissGuide, TissGuideItem, TissTussCode};
use App\Domains\Tiss\PreValidation\Contracts\TissGuideValidationRule;
use App\Domains\Tiss\PreValidation\Enums\TissValidationSeverity;
use App\Domains\Tiss\PreValidation\TissGuideValidationIssue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Verifica se os códigos TUSS dos procedimentos existem e estão ativos na tabela de referência.
 * Resultados são cacheados por 1 hora para evitar queries repetidas na mesma requisição.
 */
final class TussCodeActive implements TissGuideValidationRule
{
    public function validate(TissGuide $guide): array
    {
        $items = $guide->relationLoaded('items') ? $guide->items : $guide->items()->get();

        if ($items->isEmpty()) {
            return [];
        }

        $codes       = $items->pluck('tuss_code')->filter()->unique()->values();
        $activeCodes = $this->resolveActiveCodes($codes);

        $issues = [];
        $today  = now()->toDateString();

        foreach ($items as $item) {
            /** @var TissGuideItem $item */
            $code = $item->tuss_code ?? '';

            if (blank($code)) {
                $issues[] = new TissGuideValidationIssue(
                    severity: TissValidationSeverity::Error,
                    code: 'TUSS_CODE_MISSING',
                    field: "items.{$item->id}.tuss_code",
                    message: 'Procedimento sem código TUSS.',
                    suggestion: 'Informe o código TUSS do procedimento conforme a Tabela 22 da ANS.',
                );

                continue;
            }

            $tuss = $activeCodes->get($code);

            if (! $tuss) {
                $issues[] = new TissGuideValidationIssue(
                    severity: TissValidationSeverity::Warning,
                    code: 'TUSS_CODE_NOT_FOUND',
                    field: "items.{$item->id}.tuss_code",
                    message: "Código TUSS {$code} não encontrado na tabela de referência.",
                    suggestion: 'Verifique se o código TUSS está correto ou atualize a tabela de códigos no sistema.',
                );

                continue;
            }

            if (! $tuss->active) {
                $issues[] = new TissGuideValidationIssue(
                    severity: TissValidationSeverity::Warning,
                    code: 'TUSS_CODE_INACTIVE',
                    field: "items.{$item->id}.tuss_code",
                    message: "Código TUSS {$code} está inativo.",
                    suggestion: 'Substitua o código TUSS por uma versão vigente ou contate o suporte.',
                );

                continue;
            }

            if ($tuss->effective_until && $tuss->effective_until->toDateString() < $today) {
                $until    = $tuss->effective_until->format('d/m/Y');
                $issues[] = new TissGuideValidationIssue(
                    severity: TissValidationSeverity::Warning,
                    code: 'TUSS_CODE_EXPIRED',
                    field: "items.{$item->id}.tuss_code",
                    message: "Código TUSS {$code} expirou em {$until}.",
                    suggestion: 'Utilize o código TUSS substituto vigente para evitar glosa por código expirado.',
                );
            }
        }

        return $issues;
    }

    /** @param Collection<int, string> $codes */
    private function resolveActiveCodes(Collection $codes): Collection
    {
        $cacheKey = 'tiss_tuss_active_' . md5($codes->sort()->implode(','));

        return Cache::remember($cacheKey, now()->addHour(), function () use ($codes): Collection {
            return TissTussCode::query()
                ->whereIn('code', $codes)
                ->get()
                ->keyBy('code');
        });
    }
}
