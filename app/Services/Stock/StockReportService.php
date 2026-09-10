<?php

declare(strict_types=1);

namespace App\Services\Stock;

use App\Enums\StockMovementType;
use App\Models\{EntityProduct, MedicalRecordProcedure, PurchaseOrder, StockMovement};
use Illuminate\Support\Collection;

/**
 * Relatórios de estoque (GAP fechado nesta revisão — Fase 4 original
 * previa "relatório de custo/giro/curva ABC" e ficou deliberadamente de
 * fora por ser feature de análise separada da fundação transacional).
 *
 * Leitura pura — nenhum método aqui grava nada. Todo valor monetário parte
 * de `stock_movements` (ledger imutável, já auditado desde a Fase 1), não
 * de estimativas.
 */
class StockReportService
{
    /**
     * Posição de estoque valorizada (qty_on_hand × cost_avg) + curva ABC
     * (classificação por contribuição acumulada de valor — 80/95/100%,
     * convenção padrão de gestão de estoque).
     *
     * @return array{items: list<array<string, mixed>>, total_value: float}
     */
    public function valuedInventory(string $entityId): array
    {
        $products = EntityProduct::query()
            ->where('entity_id', $entityId)
            ->active()
            ->with('category:id,name')
            ->get(['id', 'name', 'code', 'product_category_id', 'qty_on_hand', 'cost_avg', 'unit'])
            ->map(fn (EntityProduct $p) => [
                'id'            => $p->id,
                'name'          => $p->name,
                'code'          => $p->code,
                'category_name' => $p->category?->name,
                'unit'          => $p->unit?->label(),
                'qty_on_hand'   => (float) $p->qty_on_hand,
                'cost_avg'      => (float) $p->cost_avg,
                'total_value'   => round((float) $p->qty_on_hand * (float) $p->cost_avg, 2),
            ])
            ->sortByDesc('total_value')
            ->values();

        $totalValue = (float) $products->sum('total_value');

        $cumulative = 0.0;
        $items      = $products->map(function (array $row) use ($totalValue, &$cumulative) {
            $cumulative += $row['total_value'];
            $cumulativePct = $totalValue > 0 ? ($cumulative / $totalValue) * 100 : 0;

            $row['cumulative_pct'] = round($cumulativePct, 1);
            $row['abc_class']      = match (true) {
                $row['total_value'] <= 0 => null, // sem valor em estoque — não entra na curva
                $cumulativePct <= 80.0   => 'A',
                $cumulativePct <= 95.0   => 'B',
                default                  => 'C',
            };

            return $row;
        })->values()->all();

        return ['items' => $items, 'total_value' => round($totalValue, 2)];
    }

    /**
     * Giro de estoque por produto no período: saídas (consumo + manual +
     * perda + devolução, EXCLUI ajuste — ajuste é correção de contagem, não
     * "uso" real) valorizadas ao custo médio ATUAL / valor do saldo atual.
     * Aproximação deliberada: giro "de verdade" usaria saldo médio no
     * período (exigiria snapshot diário de saldo, que não existe) — este é
     * o giro instantâneo (saída no período ÷ posição atual), suficiente
     * pra identificar produto parado vs. produto girando.
     *
     * @return list<array<string, mixed>>
     */
    public function turnoverByProduct(string $entityId, string $from, string $to): array
    {
        $outboundTypes = [
            StockMovementType::ConsumptionOut->value,
            StockMovementType::ManualOut->value,
            StockMovementType::Loss->value,
            StockMovementType::ReturnOut->value,
        ];

        $consumedByProduct = StockMovement::query()
            ->where('entity_id', $entityId)
            ->whereIn('type', $outboundTypes)
            ->whereBetween('occurred_at', ["{$from} 00:00:00", "{$to} 23:59:59"])
            ->selectRaw('entity_product_id, SUM(quantity) as qty_out')
            ->groupBy('entity_product_id')
            ->pluck('qty_out', 'entity_product_id');

        return EntityProduct::query()
            ->where('entity_id', $entityId)
            ->active()
            ->get(['id', 'name', 'code', 'qty_on_hand', 'cost_avg'])
            ->map(function (EntityProduct $p) use ($consumedByProduct) {
                $qtyOut       = (float) ($consumedByProduct[$p->id] ?? 0);
                $currentValue = (float) $p->qty_on_hand * (float) $p->cost_avg;
                $outValue     = $qtyOut * (float) $p->cost_avg;

                return [
                    'id'             => $p->id,
                    'name'           => $p->name,
                    'code'           => $p->code,
                    'qty_out'        => $qtyOut,
                    'qty_on_hand'    => (float) $p->qty_on_hand,
                    'turnover_ratio' => $currentValue > 0 ? round($outValue / $currentValue, 2) : null,
                ];
            })
            ->filter(fn ($row) => $row['qty_out'] > 0 || $row['qty_on_hand'] > 0)
            ->sortByDesc('qty_out')
            ->values()
            ->all();
    }

    /**
     * Consumo de material por procedimento/médico no período — junta
     * stock_movements(consumption_out) ao MedicalRecordProcedure que gerou
     * a baixa (Fase 3), valorizado ao custo médio no momento do consumo
     * (`unit_cost` gravado NO PRÓPRIO movimento, não recalculado agora).
     *
     * @return list<array<string, mixed>>
     */
    public function consumptionByProcedure(string $entityId, string $from, string $to): array
    {
        $movements = StockMovement::query()
            ->where('entity_id', $entityId)
            ->where('type', StockMovementType::ConsumptionOut->value)
            ->where('reference_type', MedicalRecordProcedure::class)
            ->whereBetween('occurred_at', ["{$from} 00:00:00", "{$to} 23:59:59"])
            ->with('product:id,name')
            ->get();

        if ($movements->isEmpty()) {
            return [];
        }

        $procedureIds = $movements->pluck('reference_id')->unique()->all();
        $procedures   = MedicalRecordProcedure::query()
            ->whereIn('id', $procedureIds)
            ->with(['procedure:id,name', 'doctor.person:id,full_name'])
            ->get()
            ->keyBy('id');

        return $movements
            ->groupBy(fn (StockMovement $m) => (string) $m->reference_id)
            ->map(function (Collection $group, string $procedureExecId) use ($procedures) {
                $execution = $procedures->get($procedureExecId);

                return [
                    'procedure_name' => $execution?->procedure?->name ?? '—',
                    'doctor_name'    => $execution?->doctor?->person?->full_name ?? '—',
                    'executed_at'    => $execution?->executed_at?->format('d/m/Y'),
                    'items'          => $group->map(fn (StockMovement $m) => [
                        'product_name' => $m->product?->name,
                        'quantity'     => (float) $m->quantity,
                        'unit_cost'    => $m->unit_cost !== null ? (float) $m->unit_cost : null,
                        'total_cost'   => $m->unit_cost !== null ? round((float) $m->quantity * (float) $m->unit_cost, 2) : null,
                    ])->values()->all(),
                    'total_cost' => round($group->sum(fn (StockMovement $m) => (float) $m->quantity * (float) ($m->unit_cost ?? 0)), 2),
                ];
            })
            ->sortByDesc('total_cost')
            ->values()
            ->all();
    }

    /**
     * Gasto com compras por fornecedor no período — valorizado pelo que
     * REALMENTE entrou (stock_movements.purchase_in), não pelo total
     * pedido (um pedido parcialmente recebido/cancelado não deve inflar o
     * gasto real).
     *
     * @return list<array<string, mixed>>
     */
    public function purchasesBySupplier(string $entityId, string $from, string $to): array
    {
        $movements = StockMovement::query()
            ->where('entity_id', $entityId)
            ->where('type', StockMovementType::PurchaseIn->value)
            ->where('reference_type', PurchaseOrder::class)
            ->whereBetween('occurred_at', ["{$from} 00:00:00", "{$to} 23:59:59"])
            ->get(['reference_id', 'quantity', 'unit_cost']);

        if ($movements->isEmpty()) {
            return [];
        }

        $poIds            = $movements->pluck('reference_id')->unique()->all();
        $ordersBySupplier = PurchaseOrder::query()
            ->whereIn('id', $poIds)
            ->with('supplier:id,name')
            ->get()
            ->keyBy('id');

        return $movements
            ->groupBy(function (StockMovement $m) use ($ordersBySupplier) {
                return $ordersBySupplier->get($m->reference_id)?->supplier_id ?? 'unknown';
            })
            ->map(function (Collection $group) use ($ordersBySupplier) {
                $firstPo = $ordersBySupplier->get($group->first()->reference_id);

                return [
                    'supplier_name' => $firstPo?->supplier?->name ?? 'Fornecedor removido',
                    'total_spent'   => round($group->sum(fn (StockMovement $m) => (float) $m->quantity * (float) ($m->unit_cost ?? 0)), 2),
                    'orders_count'  => $group->pluck('reference_id')->unique()->count(),
                ];
            })
            ->sortByDesc('total_spent')
            ->values()
            ->all();
    }
}
