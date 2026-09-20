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
 * TISS não tem campo estruturado de lateralidade (ver V202603TissXmlBuilder::
 * descriptionWithEyeSide()/appendConsultaGuide() — o dado vive em
 * TissGuideItem.metadata['eye_side']). Quando a descrição oficial do
 * procedimento (Tabela 22) indica MONOCULAR/BINOCULAR e a guia não
 * registrou o olho, isso não é erro de schema — é warning: lembrete pra
 * evitar duplicidade/erro no faturamento (ex.: cobrar monocular duas vezes
 * sem indicar OD numa e OE na outra).
 */
final class EyeSideRecommended implements TissGuideValidationRule
{
    private const LATERALITY_KEYWORDS = ['MONOCULAR', 'BINOCULAR'];

    public function validate(TissGuide $guide): array
    {
        $items = $guide->relationLoaded('items') ? $guide->items : $guide->items()->get();

        if ($items->isEmpty()) {
            return [];
        }

        $codes      = $items->pluck('tuss_code')->filter()->unique()->values();
        $tussByCode = $this->resolveTussCodes($codes);

        $issues = [];

        foreach ($items as $item) {
            /** @var TissGuideItem $item */
            if (filled($item->metadata['eye_side'] ?? null)) {
                continue;
            }

            $tuss        = $tussByCode->get((string) $item->tuss_code);
            $description = $tuss?->description ?? (string) $item->description;

            if (! $this->mentionsLaterality(mb_strtoupper($description))) {
                continue;
            }

            $issues[] = new TissGuideValidationIssue(
                severity: TissValidationSeverity::Warning,
                code: 'EYE_SIDE_RECOMMENDED',
                field: "items.{$item->id}.eye_side",
                message: "Procedimento \"{$description}\" indica lateralidade (monocular/binocular), mas o olho (OD/OE/AO) não foi informado.",
                suggestion: 'Informe o olho no faturamento — evita duplicidade e facilita auditoria/glosa.',
            );
        }

        return $issues;
    }

    private function mentionsLaterality(string $upperDescription): bool
    {
        foreach (self::LATERALITY_KEYWORDS as $keyword) {
            if (str_contains($upperDescription, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /** @param Collection<int, string> $codes */
    private function resolveTussCodes(Collection $codes): Collection
    {
        if ($codes->isEmpty()) {
            return collect();
        }

        $cacheKey = 'tiss_tuss_lookup_' . md5($codes->sort()->implode(','));

        return Cache::remember($cacheKey, now()->addHour(), function () use ($codes): Collection {
            return TissTussCode::query()
                ->whereIn('code', $codes)
                ->get()
                ->keyBy('code');
        });
    }
}
