<?php

declare(strict_types=1);

namespace App\Services\Medicines;

use App\Enums\{MedicinePosologySource, MedicineSource};
use App\Models\Medicine;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filtros e ordenação do catálogo GLOBAL de medicamentos (Manager →
 * Medicamentos). Fonte única: a listagem da tela e o lote "Gerar posologia
 * com IA" usam exatamente a mesma consulta — o lote alcança o que o admin
 * está vendo no filtro.
 */
class MedicineCatalogFilters
{
    /** Ordenação aceita pela tabela (whitelist — vai direto pro ORDER BY). */
    public const SORTS = ['name', 'laboratory', 'source', 'cmed_situation', 'active'];

    /** Situação do item na lista de preços da CMED (coluna "Situação na CMED"). */
    public const CMED_SITUATIONS = ['marketed', 'not_marketed', 'left_list'];

    /**
     * Situação da posologia sugerida: sem posologia, gerada por IA e ainda
     * não revisada, revisada (manual).
     */
    public const POSOLOGY = ['empty', 'ai_pending', 'reviewed'];

    /**
     * Ordem da situação na CMED: comercializado, não comercializado, fora da
     * lista atual e, por último, os curados (não se aplica).
     */
    private const CMED_SITUATION_ORDER = "CASE WHEN medicines.source <> 'cmed' THEN 3 WHEN medicines.active = false THEN 2 WHEN medicines.is_marketed THEN 0 ELSE 1 END";

    public function __construct(
        private readonly MedicineCatalogSearch $catalogSearch,
    ) {
    }

    /**
     * Filtros normalizados (valores fora da whitelist viram o padrão).
     *
     * @param array<string, mixed> $input
     *
     * @return array{search: string, source: string, status: string, cmed_situation: string, posology: string, ophthalmic: bool, sort: string, direction: string}
     */
    public function normalize(array $input): array
    {
        $pick = static fn (string $key, array $allowed): string => in_array($input[$key] ?? null, $allowed, true) ? (string) $input[$key] : '';

        return [
            'search'         => mb_substr(trim(is_scalar($input['search'] ?? null) ? (string) $input['search'] : ''), 0, 200),
            'source'         => $pick('source', ['manual', 'cmed']),
            'status'         => $pick('status', ['active', 'inactive']),
            'cmed_situation' => $pick('cmed_situation', self::CMED_SITUATIONS),
            'posology'       => $pick('posology', self::POSOLOGY),
            'ophthalmic'     => filter_var($input['ophthalmic'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'sort'           => $pick('sort', self::SORTS) ?: 'name',
            'direction'      => ($input['direction'] ?? null) === 'desc' ? 'desc' : 'asc',
        ];
    }

    /** @return Builder<Medicine> catálogo global (nunca item de clínica nem excluído) */
    public function globalCatalog(): Builder
    {
        return Medicine::withoutGlobalScopes()->whereNull('medicines.entity_id')->whereNull('medicines.deleted_at');
    }

    /**
     * Catálogo global com os filtros e a ordenação da tela.
     *
     * @param array<string, mixed> $filters já normalizados (normalize())
     *
     * @return Builder<Medicine>
     */
    public function query(array $filters): Builder
    {
        $query = $this->globalCatalog();

        if (($filters['search'] ?? '') !== '') {
            $this->catalogSearch->apply($query, $filters['search']);
        }

        $query->when($filters['source'] ?? '', fn (Builder $q, string $source) => $q->where('medicines.source', $source))
            ->when(($filters['status'] ?? '') === 'active', fn (Builder $q) => $q->where('medicines.active', true))
            ->when(($filters['status'] ?? '') === 'inactive', fn (Builder $q) => $q->where('medicines.active', false))
            ->when($filters['ophthalmic'] ?? false, fn (Builder $q) => $q->where('medicines.is_ophthalmic', true))
            ->when($filters['cmed_situation'] ?? '', fn (Builder $q, string $situation) => $this->whereCmedSituation($q, $situation))
            ->when($filters['posology'] ?? '', fn (Builder $q, string $posology) => $this->wherePosology($q, $posology));

        $direction = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $sort      = in_array($filters['sort'] ?? null, self::SORTS, true) ? $filters['sort'] : 'name';

        // Coluna derivada: ordena pela expressão; as demais, pela coluna (whitelist).
        $sort === 'cmed_situation'
            ? $query->orderByRaw(self::CMED_SITUATION_ORDER . ' ' . $direction)
            : $query->orderBy('medicines.' . $sort, $direction);

        return $query->orderBy('medicines.name')
            ->orderBy('medicines.concentration')
            ->orderBy('medicines.id');
    }

    /** Mesmo critério da coluna "Situação na CMED", como filtro SQL. */
    public function whereCmedSituation(Builder $query, string $situation): void
    {
        $query->where('medicines.source', MedicineSource::Cmed->value);

        match ($situation) {
            'marketed'     => $query->where('medicines.active', true)->where('medicines.is_marketed', true),
            'not_marketed' => $query->where('medicines.active', true)->where('medicines.is_marketed', false),
            default        => $query->where('medicines.active', false),
        };
    }

    /** Situação da posologia sugerida (ver POSOLOGY). */
    public function wherePosology(Builder $query, string $posology): void
    {
        match ($posology) {
            'empty'      => $this->whereWithoutPosology($query),
            'ai_pending' => $query->where('medicines.posology_source', MedicinePosologySource::Ai->value),
            default      => $query->where(function (Builder $q) {
                $this->whereHasPosology($q);
                $q->where(fn (Builder $source) => $source
                    ->whereNull('medicines.posology_source')
                    ->orWhere('medicines.posology_source', '<>', MedicinePosologySource::Ai->value));
            }),
        };
    }

    /** Dose, frequência, duração e orientações TODAS vazias. */
    public function whereWithoutPosology(Builder $query): Builder
    {
        foreach (Medicine::POSOLOGY_FIELDS as $field) {
            $query->whereRaw("COALESCE(TRIM(medicines.{$field}), '') = ''");
        }

        return $query;
    }

    private function whereHasPosology(Builder $query): void
    {
        $query->where(function (Builder $q) {
            foreach (Medicine::POSOLOGY_FIELDS as $field) {
                $q->orWhereRaw("COALESCE(TRIM(medicines.{$field}), '') <> ''");
            }
        });
    }
}
