<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Domains\Tiss\Actions\ResolveTissOperatorForCovenantAction;
use App\Models\{Covenant, ProcedurePrice};
use Illuminate\Support\Facades\DB;

/**
 * Resolução e gestão de preços por procedimento × convênio.
 * Fallback de preço: linha da entidade ativa → linha global (entity_id null) → null.
 */
class ProcedurePriceService
{
    public function __construct(
        private readonly ResolveTissOperatorForCovenantAction $tissOperator,
    ) {
    }

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
     * Preço padrão do sistema (linha global, entity_id null e ativa) de cada
     * procedimento do convênio — o que getPrice() usa quando a clínica não tem
     * preço próprio. A tela mostra como valor herdado (placeholder). Nunca lê
     * linhas de outra clínica.
     *
     * @return array<string, float> procedure_id => preço
     */
    public function inheritedPricesForCovenant(string $covenantId): array
    {
        return ProcedurePrice::query()
            ->whereNull('entity_id')
            ->where('covenant_id', $covenantId)
            ->where('active', true)
            ->get(['procedure_id', 'price'])
            ->mapWithKeys(fn (ProcedurePrice $r) => [(string) $r->procedure_id => (float) $r->price])
            ->all();
    }

    /**
     * Preço de cada procedimento do convênio como a grade dele mostra: o preço
     * próprio da clínica ou, sem ele, o padrão do sistema. Base do "Copiar de
     * outro convênio" da Tabela de Preços. Nunca lê linhas de outra clínica.
     *
     * @return array<string, float> procedure_id => preço
     */
    public function effectivePricesForCovenant(string $entityId, string $covenantId): array
    {
        $own = [];

        foreach ($this->pricesForCovenant($entityId, $covenantId) as $procedureId => $row) {
            $own[(string) $procedureId] = $row['price'];
        }

        return $own + $this->inheritedPricesForCovenant($covenantId);
    }

    /**
     * O convênio (da clínica ou global) gera guia TISS? Mesmo critério do
     * faturamento (BillingService): registro ANS preenchido. Sem registro ANS
     * (ex.: Particular) o atendimento é recebido no caixa, nunca por guia.
     */
    public function billsViaTiss(string $entityId, string $covenantId): bool
    {
        $covenant = Covenant::query()
            ->whereKey($covenantId)
            ->where(fn ($q) => $q->where('entity_id', $entityId)->orWhereNull('entity_id'))
            ->first(['id', 'entity_id', 'ans_registry']);

        return $covenant !== null && $this->tissOperator->isEligible($covenant);
    }

    /**
     * Grava os preços de um convênio para a entidade com semântica EXPLÍCITA por
     * linha — a tela envia só as linhas alteradas:
     *
     * - `price` informado → upsert do preço da clínica;
     * - `price` null/vazio → remove (soft delete) AQUELE preço;
     * - procedimento que não veio no lote, ou item sem a chave `price` → não
     *   muda. Nada é removido por omissão.
     *
     * - A remoção é feita pelo MODEL (não no query builder) para disparar
     *   Auditable (evento "deleted") e HasAuditColumns (deleted_by).
     * - O UNIQUE (entity_id, covenant_id, procedure_id) não considera
     *   deleted_at: recadastrar um preço apagado RESTAURA a linha trashed em vez
     *   de tentar um INSERT (que violava o índice e desfazia o lote inteiro).
     * - "Cobrar do convênio (guia TISS)" (charging) só vale para convênio com
     *   operadora TISS; nos demais é gravado false mesmo que o request mande true
     *   (basta uma linha marcada para a agenda tratar o convênio inteiro como
     *   faturado por guia e o caixa da chegada não abrir).
     * - As linhas atuais dos procedimentos do lote vêm numa consulta só.
     *
     * @param array<int, array{procedure_id:string, price?:?float, charging?:bool}> $items
     */
    public function syncForCovenant(string $entityId, string $covenantId, array $items): void
    {
        $changes = $this->explicitChanges($items);

        if ($changes === []) {
            return;
        }

        $billsViaTiss = $this->billsViaTiss($entityId, $covenantId);

        DB::transaction(function () use ($entityId, $covenantId, $changes, $billsViaTiss): void {
            $current = ProcedurePrice::withTrashed()
                ->where('entity_id', $entityId)
                ->where('covenant_id', $covenantId)
                ->whereIn('procedure_id', array_keys($changes))
                ->get()
                ->keyBy(fn (ProcedurePrice $row) => strtolower((string) $row->procedure_id));

            foreach ($changes as $procedureId => $change) {
                $row = $current->get($procedureId);

                if ($change['price'] === null) {
                    $this->removePrice($row);

                    continue;
                }

                $this->upsertPrice($row, $entityId, $covenantId, $procedureId, [
                    'price'    => $change['price'],
                    'charging' => $billsViaTiss && $change['charging'],
                    'active'   => true,
                ]);
            }
        });
    }

    /**
     * Só os itens com procedimento E a chave `price` (mesmo que null) viram
     * mudança; repetido no lote, vale o último.
     *
     * @param array<int, mixed> $items
     *
     * @return array<string, array{price: ?float, charging: bool}> procedure_id => mudança
     */
    private function explicitChanges(array $items): array
    {
        $changes = [];

        foreach ($items as $item) {
            if (! is_array($item) || blank($item['procedure_id'] ?? null) || ! array_key_exists('price', $item)) {
                continue;
            }

            $price = $item['price'];

            $changes[strtolower((string) $item['procedure_id'])] = [
                'price'    => $price === null || $price === '' ? null : (float) $price,
                'charging' => (bool) ($item['charging'] ?? true),
            ];
        }

        return $changes;
    }

    private function removePrice(?ProcedurePrice $row): void
    {
        if ($row !== null && ! $row->trashed()) {
            $row->delete();
        }
    }

    /**
     * @param array{price: float, charging: bool, active: bool} $attributes
     */
    private function upsertPrice(?ProcedurePrice $row, string $entityId, string $covenantId, string $procedureId, array $attributes): void
    {
        if ($row === null) {
            ProcedurePrice::query()->create([
                'entity_id'    => $entityId,
                'covenant_id'  => $covenantId,
                'procedure_id' => $procedureId,
                ...$attributes,
            ]);

            return;
        }

        $row->fill($attributes);

        if ($row->trashed()) {
            // restore() salva junto os atributos novos (um único UPDATE) e
            // dispara "restoring" (auditoria); deleted_by volta a null.
            $row->deleted_by = null;
            $row->restore();

            return;
        }

        $row->save();
    }
}
