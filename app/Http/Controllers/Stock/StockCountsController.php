<?php

declare(strict_types=1);

namespace App\Http\Controllers\Stock;

use App\Http\Controllers\Controller;
use App\Http\Requests\StockCountRequest;
use App\Models\{EntityProduct, ProductCategory};
use App\Services\Stock\StockService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

    /**
     * Colunas ordenáveis (whitelist) → coluna real no banco. `category`
     * ordena pelo nome da categoria via subquery (ver sortExpression()),
     * sem join — o eager load de `category` continua igual.
     */
    private const SORTABLE = [
        'name'        => 'entity_products.name',
        'code'        => 'entity_products.code',
        'category'    => 'product_categories.name',
        'qty_on_hand' => 'entity_products.qty_on_hand',
    ];

    private const DEFAULT_SORT = 'name';

    private const DEFAULT_DIRECTION = 'asc';

    /**
     * Página maior que a das outras listagens: a contagem é digitação em
     * sequência; os valores digitados ficam guardados na tela ao trocar de
     * página (ver Pages/Panel/Stock/Counts/Index.vue).
     */
    private const PER_PAGE = 50;

    public function index(Request $request): InertiaResponse
    {
        $entityId   = (string) session('selected_entity_id');
        $search     = $this->stringParam($request, 'search');
        $categoryId = $this->stringParam($request, 'category_id');
        $sortBy     = $this->stringParam($request, 'sort', self::DEFAULT_SORT);
        $sortDir    = $this->stringParam($request, 'direction', self::DEFAULT_DIRECTION);

        // Coluna uuid no PostgreSQL: valor fora do formato derrubava a tela (500).
        $categoryId = Str::isUuid($categoryId) ? $categoryId : '';
        $sortBy     = array_key_exists($sortBy, self::SORTABLE) ? $sortBy : self::DEFAULT_SORT;
        $sortDir    = in_array($sortDir, ['asc', 'desc'], true) ? $sortDir : self::DEFAULT_DIRECTION;

        // `id` desempata valores iguais (nome, saldo...) — sem ele a ordem
        // entre páginas não é determinística no PostgreSQL.
        $products = EntityProduct::query()
            ->where('entity_products.entity_id', $entityId)
            ->active()
            ->with('category:id,name')
            ->when($categoryId !== '', fn (Builder $q) => $q->where('entity_products.product_category_id', $categoryId))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->whereLikeUnaccent('entity_products.name', $search)
                ->orWhereLikeUnaccent('entity_products.code', $search)
                ->orWhereLikeUnaccent('entity_products.barcode', $search)))
            ->orderBy($this->sortExpression($sortBy), $sortDir)
            ->orderBy('entity_products.id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (EntityProduct $p) => [
                'id'            => $p->id,
                'name'          => $p->name,
                'code'          => $p->code,
                'unit'          => $p->unit?->value,
                'unit_label'    => $p->unit?->label(),
                'category_name' => $p->category?->name,
                'qty_on_hand'   => (float) $p->qty_on_hand,
                'requires_lot'  => (bool) $p->requires_lot,
                // Atalho "ver movimentações" — mesma permissão do grupo stock.
                'movements_url' => route('panel.stock.movements.index', ['entity_product_id' => $p->id]),
            ]);

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
            // Normalizados: a UI mostra a ordenação/filtro realmente aplicados.
            'filters' => [
                'search'      => $search,
                'category_id' => $categoryId,
                'sort'        => $sortBy,
                'direction'   => $sortDir,
            ],
            'routes' => [
                'index'           => route('panel.stock.counts.index'),
                'store'           => route('panel.stock.counts.store'),
                'movements_index' => route('panel.stock.movements.index'),
            ],
            't' => trans('stock_counts'),
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

    /** Coluna (ou subquery, para o nome da categoria) usada no ORDER BY. */
    private function sortExpression(string $sortBy): string|QueryBuilder
    {
        if ($sortBy !== 'category') {
            return self::SORTABLE[$sortBy];
        }

        return DB::table('product_categories')
            ->select(self::SORTABLE['category'])
            ->whereColumn('product_categories.id', 'entity_products.product_category_id');
    }

    /** Parâmetro de query como string; array/objeto (ex.: `sort[]=x`) volta ao padrão. */
    private function stringParam(Request $request, string $key, string $default = ''): string
    {
        $value = $request->input($key, $default);

        return is_string($value) ? trim($value) : $default;
    }
}
