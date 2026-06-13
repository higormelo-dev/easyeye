<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Models\ProcedurePrice;
use Illuminate\Support\Facades\DB;

/**
 * Resolução e gestão de preços por procedimento × convênio.
 * Fallback de preço: linha da entidade ativa → linha global (entity_id null) → null.
 */
class ProcedurePriceService
{
    public function getPrice(?string $procedureId, ?string $covenantId, string $entityId): ?float
    {
        if ($procedureId === null || $covenantId === null) {
            return null;
        }

        $price = ProcedurePrice::query()
            ->where('covenant_id', $covenantId)
            ->where('procedure_id', $procedureId)
            ->where('active', true)
            ->where(fn ($q) => $q->where('entity_id', $entityId)->orWhereNull('entity_id'))
            // prioriza a linha específica da entidade sobre a global
            ->orderByRaw('CASE WHEN entity_id IS NULL THEN 1 ELSE 0 END')
            ->value('price');

        return $price === null ? null : (float) $price;
    }

    /**
     * Mapa "covenant_id:procedure_id" => preço, para pré-preenchimento no caixa.
     * A linha da entidade sobrepõe a global em caso de empate.
     *
     * @return array<string, float>
     */
    public function priceMap(string $entityId): array
    {
        return ProcedurePrice::query()
            ->where('active', true)
            ->where(fn ($q) => $q->where('entity_id', $entityId)->orWhereNull('entity_id'))
            ->get(['covenant_id', 'procedure_id', 'price', 'entity_id'])
            ->sortBy(fn (ProcedurePrice $r) => $r->entity_id === null ? 0 : 1) // entidade por último -> vence no keyBy
            ->keyBy(fn (ProcedurePrice $r) => $r->covenant_id . ':' . $r->procedure_id)
            ->map(fn (ProcedurePrice $r) => (float) $r->price)
            ->all();
    }

    /**
     * Indica se o atendimento (procedimento × convênio) será faturado ao
     * convênio via guia — ou seja, NÃO deve ser recebido por inteiro no caixa
     * da chegada. Baseia-se na flag `charging` da tabela de preços.
     */
    public function isCharging(?string $procedureId, ?string $covenantId, string $entityId): bool
    {
        if ($covenantId === null) {
            return false;
        }

        $query = ProcedurePrice::query()
            ->where('covenant_id', $covenantId)
            ->where('charging', true)
            ->where('active', true)
            ->where(fn ($q) => $q->where('entity_id', $entityId)->orWhereNull('entity_id'));

        if ($procedureId !== null) {
            $query->where('procedure_id', $procedureId);
        }

        return $query->exists();
    }

    /**
     * Mapa "covenant_id:procedure_id" => true para as linhas cobráveis
     * (charging=true), usado na agenda para decidir auto-open/prefill.
     *
     * @return array<string, bool>
     */
    public function chargingMap(string $entityId): array
    {
        return ProcedurePrice::query()
            ->where('active', true)
            ->where('charging', true)
            ->where(fn ($q) => $q->where('entity_id', $entityId)->orWhereNull('entity_id'))
            ->get(['covenant_id', 'procedure_id'])
            ->keyBy(fn (ProcedurePrice $r) => $r->covenant_id . ':' . $r->procedure_id)
            ->map(fn () => true)
            ->all();
    }

    /**
     * Conjunto (set) de covenant_id que possuem ao menos um procedimento
     * cobrável (charging=true). Usado na agenda para decidir, por linha,
     * se o caixa de chegada deve auto-abrir/pré-preencher (particular) ou
     * tratar como co-participação de convênio.
     *
     * @return array<string, bool>
     */
    public function chargingCovenantIds(string $entityId): array
    {
        return ProcedurePrice::query()
            ->where('active', true)
            ->where('charging', true)
            ->where(fn ($q) => $q->where('entity_id', $entityId)->orWhereNull('entity_id'))
            ->distinct()
            ->pluck('covenant_id')
            ->flip()
            ->all();
    }

    /** Linhas de preço da entidade para um convênio (gestão). */
    public function pricesForCovenant(string $entityId, string $covenantId): array
    {
        return ProcedurePrice::query()
            ->where('entity_id', $entityId)
            ->where('covenant_id', $covenantId)
            ->get(['procedure_id', 'price', 'charging'])
            ->keyBy('procedure_id')
            ->map(fn (ProcedurePrice $r) => [
                'price'    => (float) $r->price,
                'charging' => (bool) $r->charging,
            ])
            ->all();
    }

    /**
     * Sincroniza os preços de um convênio para a entidade: faz upsert das linhas
     * com preço informado e remove (soft-delete) as que vierem com preço vazio.
     *
     * @param array<int, array{procedure_id:string, price:?float, charging?:bool}> $items
     */
    public function syncForCovenant(string $entityId, string $covenantId, array $items): void
    {
        DB::transaction(function () use ($entityId, $covenantId, $items): void {
            foreach ($items as $item) {
                $procedureId = $item['procedure_id'];
                $price       = $item['price'] ?? null;

                if ($price === null || $price === '') {
                    ProcedurePrice::query()
                        ->where('entity_id', $entityId)
                        ->where('covenant_id', $covenantId)
                        ->where('procedure_id', $procedureId)
                        ->delete();

                    continue;
                }

                ProcedurePrice::query()->updateOrCreate(
                    ['entity_id' => $entityId, 'covenant_id' => $covenantId, 'procedure_id' => $procedureId],
                    ['price' => (float) $price, 'charging' => (bool) ($item['charging'] ?? true), 'active' => true],
                );
            }
        });
    }
}
