<?php

declare(strict_types=1);

namespace App\Http\Controllers\Stock;

use App\Http\Controllers\Controller;
use App\Http\Requests\StockCountRequest;
use App\Models\{EntityProduct, ProductCategory};
use App\Services\Stock\StockService;
use Illuminate\Http\{JsonResponse, Request};
use Inertia\{Inertia, Response as InertiaResponse};

/**
 * Contagem física de estoque em MASSA (GAP fechado — revisão pós-Fase 4,
 * "melhorar o módulo de estoque"): antes só dava pra ajustar saldo produto
 * a produto na tela de Movimentação (App\Services\Stock\StockService::
 * adjustToCountedQuantity() já existia desde a Fase 1, mas sem tela
 * própria pra contar MUITOS produtos de uma vez). Aqui a clínica lista
 * tudo, digita o contado ao lado do saldo do sistema, e envia junto — cada
 * divergência vira uma movimentação `adjustment_in`/`adjustment_out`
 * rastreável (StockMovement.note identifica a origem); item cuja contagem
 * bate com o sistema não gera movimento nenhum (comportamento já embutido
 * no service).
 *
 * Mesmo grupo de rota `stock.` (permission:stock.manage +
 * feature:has_inventory_module) do resto do módulo.
 */
class StockCountsController extends Controller
{
    public function __construct(
        private readonly StockService $stockService,
    ) {
    }

    public function index(Request $request): InertiaResponse
    {
        $entityId   = (string) session('selected_entity_id');
        $categoryId = $request->string('category_id')->trim()->value();

        $products = EntityProduct::query()
            ->where('entity_id', $entityId)
            ->active()
            ->with('category:id,name')
            ->when($categoryId !== '', fn ($q) => $q->where('product_category_id', $categoryId))
            ->orderBy('name')
            ->get()
            ->map(fn (EntityProduct $p) => [
                'id'            => $p->id,
                'name'          => $p->name,
                'code'          => $p->code,
                'unit_label'    => $p->unit?->label(),
                'category_name' => $p->category?->name,
                'qty_on_hand'   => (float) $p->qty_on_hand,
                'requires_lot'  => (bool) $p->requires_lot,
            ])
            ->values();

        return Inertia::render('Panel/Stock/Counts/Index', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('actions.sidemenu.stock'), 'url' => '#', 'active' => false],
                ['label' => __('actions.sidemenu.stock_counts'), 'url' => '#', 'active' => true],
            ],
            'products'   => $products,
            'categories' => ProductCategory::query()
                ->where('entity_id', $entityId)
                ->active()
                ->orderBy('name')
                ->get(['id', 'name']),
            'filters' => ['category_id' => $categoryId],
            'routes'  => [
                'index' => route('panel.stock.counts.index'),
                'store' => route('panel.stock.counts.store'),
            ],
        ]);
    }

    /**
     * Aplica a contagem — resposta JSON (não redirect) porque a tela
     * mostra o relatório de divergência de volta na mesma página, sem
     * navegar pra outro lugar (mesmo padrão de StockMovementsController
     * seria redirect, mas aqui o usuário quer VER o resultado de imediato,
     * não só uma mensagem de flash).
     */
    public function store(StockCountRequest $request): JsonResponse
    {
        $entityId = (string) session('selected_entity_id');
        $items    = $request->items();

        $variances = [];

        foreach ($items as $item) {
            $product = EntityProduct::query()
                ->where('entity_id', $entityId)
                ->findOrFail($item['entity_product_id']);

            $before = (float) $product->qty_on_hand;

            $movement = $this->stockService->adjustToCountedQuantity(
                $product,
                $item['counted_qty'],
                note: __('stock.count_adjustment_note'),
            );

            if ($movement !== null) {
                $variances[] = [
                    'entity_product_id' => $product->id,
                    'product_name'      => $product->name,
                    'before'            => $before,
                    'counted'           => $item['counted_qty'],
                    'delta'             => round($item['counted_qty'] - $before, 3),
                    'type'              => $movement->type->value,
                ];
            }
        }

        return response()->json([
            'message'   => __('stock.count_applied', ['count' => count($variances)]),
            'variances' => $variances,
        ]);
    }
}
