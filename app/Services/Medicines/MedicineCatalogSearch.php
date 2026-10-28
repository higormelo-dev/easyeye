<?php

declare(strict_types=1);

namespace App\Services\Medicines;

use App\Enums\MedicineSource;
use App\Models\Medicine;
use Illuminate\Database\Eloquent\Builder;

/**
 * Busca no catálogo de medicamentos (receituário do prontuário e manager).
 *
 * Cada palavra digitada tem que aparecer em search_text (nome comercial,
 * princípio ativo/genérico, concentração, forma, apresentação, laboratório —
 * ver Medicine::searchTextFor), em qualquer ordem: "pred oft" acha
 * "PREDOPTIC 10 MG/ML suspensão oftálmica". LIKE '%termo%' usa o índice
 * trigram (pg_trgm) de search_text.
 */
class MedicineCatalogSearch
{
    /** @return list<string> */
    public function tokens(string $term): array
    {
        $normalized = Medicine::normalizeSearch($term);

        return array_values(array_filter(
            array_unique(explode(' ', $normalized)),
            fn (string $token) => $token !== '',
        ));
    }

    /**
     * @param Builder<Medicine> $query
     *
     * @return Builder<Medicine>
     */
    public function apply(Builder $query, string $term): Builder
    {
        foreach ($this->tokens($term) as $token) {
            $query->where('medicines.search_text', 'like', '%' . $this->escapeLike($token) . '%');
        }

        return $query;
    }

    /**
     * Ordem do receituário: curados (com posologia sugerida) → oftálmicos →
     * nome/genérico que COMEÇA com o termo → comercializados → nome.
     *
     * @param Builder<Medicine> $query
     *
     * @return Builder<Medicine>
     */
    public function rank(Builder $query, string $term): Builder
    {
        $first = $this->tokens($term)[0] ?? null;

        $query->orderByRaw('CASE WHEN medicines.source = ? THEN 0 ELSE 1 END', [MedicineSource::Manual->value])
            ->orderByDesc('medicines.is_ophthalmic');

        if ($first !== null) {
            $query->orderByRaw(
                "CASE WHEN CONCAT(' ', medicines.search_text) LIKE ? THEN 0 ELSE 1 END",
                ['% ' . $this->escapeLike($first) . '%'],
            );
        }

        return $query->orderByDesc('medicines.is_marketed')
            ->orderBy('medicines.name')
            ->orderBy('medicines.concentration');
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
