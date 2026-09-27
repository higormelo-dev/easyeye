<?php

declare(strict_types=1);

namespace App\Http\Controllers\Stock;

use App\Enums\StockMovementType;
use App\Http\Controllers\Concerns\RedirectsToListing;
use App\Http\Controllers\Controller;
use App\Http\Requests\StockMovementRequest;
use App\Http\Resources\StockMovementResource;
use App\Models\{EntityProduct, StockLot, StockMovement};
use App\Services\Stock\StockService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\{RedirectResponse, Request};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\{Inertia, Response as InertiaResponse};

/**
 * Extrato + lançamento MANUAL de movimentação de estoque
 * (App\Models\StockMovement, App\Services\Stock\StockService).
 *
 * Só index+store: um movimento é imutável (ver doc do model) — não há
 * edit/update/destroy aqui, uma correção lança uma NOVA movimentação em
 * sentido oposto (ex.: errou a quantidade? lança um ajuste corrigindo).
 */
class StockMovementsController extends Controller
{
    use RedirectsToListing;

    /** Parâmetros da listagem preservados ao voltar do lançamento (RedirectsToListing). */
    private const LISTING_PARAMS = ['search', 'entity_product_id', 'type', 'sort', 'direction', 'page'];

    public function __construct(
        private readonly StockService $stockService,
    ) {
    }

    /**
     * Colunas ordenáveis (whitelist) → coluna real no banco. `product`
     * ordena pelo nome do produto via subquery (ver sortExpression()), sem
     * join — o eager load de product/creator/lot continua igual.
     */
    private const SORTABLE = [
        'occurred_at'   => 'stock_movements.occurred_at',
        'product'       => 'entity_products.name',
        'quantity'      => 'stock_movements.quantity',
        'unit_cost'     => 'stock_movements.unit_cost',
        'balance_after' => 'stock_movements.balance_after',
    ];

    private const DEFAULT_SORT = 'occurred_at';

    private const DEFAULT_DIRECTION = 'desc';

    public function index(Request $request): InertiaResponse
    {
        $entityId  = (string) session('selected_entity_id');
        $search    = $this->stringParam($request, 'search');
        $productId = $this->stringParam($request, 'entity_product_id');
        $type      = StockMovementType::tryFrom($this->stringParam($request, 'type'))?->value ?? '';
        $sortBy    = $this->stringParam($request, 'sort', self::DEFAULT_SORT);
        $sortDir   = $this->stringParam($request, 'direction', self::DEFAULT_DIRECTION);

        // Coluna uuid no PostgreSQL: valor fora do formato derrubava a tela (500).
        $productId = Str::isUuid($productId) ? $productId : '';
        $sortBy    = array_key_exists($sortBy, self::SORTABLE) ? $sortBy : self::DEFAULT_SORT;
        $sortDir   = in_array($sortDir, ['asc', 'desc'], true) ? $sortDir : self::DEFAULT_DIRECTION;

        // Desempate: created_at (ordem de lançamento, como sempre foi) e por
        // fim o id — sem ele a paginação não é determinística no PostgreSQL.
        $tieDirection = $sortBy === 'occurred_at' ? $sortDir : 'desc';

        $records = StockMovement::query()
            ->where('stock_movements.entity_id', $entityId)
            ->with(['product', 'creator', 'lot'])
            ->when($productId !== '', fn (Builder $query) => $query->where('stock_movements.entity_product_id', $productId))
            ->when($type !== '', fn (Builder $query) => $query->where('stock_movements.type', $type))
            ->when($search !== '', fn (Builder $query) => $this->applySearch($query, $search))
            ->orderBy($this->sortExpression($sortBy), $sortDir)
            ->orderBy('stock_movements.created_at', $tieDirection)
            ->orderBy('stock_movements.id', $tieDirection)
            ->paginate(20)
            ->withQueryString()
            ->through(fn (StockMovement $record) => (new StockMovementResource($record))->resolve());

        return Inertia::render('Panel/Stock/Movements/Index', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('actions.sidemenu.stock'), 'url' => '#', 'active' => false],
                ['label' => __('actions.sidemenu.stock_movements'), 'url' => '#', 'active' => true],
            ],
            'items'    => $records,
            'products' => EntityProduct::query()
                ->where('entity_id', $entityId)
                ->active()
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'unit', 'qty_on_hand', 'requires_lot']),
            // Produto do filtro, mesmo INATIVO (atalho vindo de Produtos): o
            // select só tem os ativos e ficaria em branco. Escopado pela
            // clínica — id de outra clínica volta null (não vaza o nome).
            'filteredProduct' => $productId === '' ? null : EntityProduct::query()
                ->where('entity_id', $entityId)
                ->whereKey($productId)
                ->first(['id', 'name'])
                ?->only(['id', 'name']),
            // Lotes com saldo > 0 de TODOS os produtos ativos, agrupados por
            // produto — o form escolhe o grupo certo no client ao trocar o
            // produto selecionado, sem round-trip extra. Ordenado por
            // validade (FEFO): staff vê o lote que vence primeiro no topo.
            'lotsByProduct' => StockLot::query()
                ->where('entity_id', $entityId)
                ->active()
                ->withBalance()
                ->orderBy('expiry_date')
                ->get(['id', 'entity_product_id', 'lot_number', 'expiry_date', 'qty_on_hand'])
                ->groupBy('entity_product_id')
                ->map(fn ($lots) => $lots->map(fn (StockLot $l) => [
                    'id'          => $l->id,
                    'lot_number'  => $l->lot_number,
                    'expiry_date' => $l->expiry_date?->format('d/m/Y'),
                    'qty_on_hand' => (float) $l->qty_on_hand,
                    'is_expired'  => $l->isExpired(),
                ])->values()),
            // Form de lançamento: só os tipos manuais (ver manualTypes()).
            'movementTypes' => collect(StockMovementType::manualTypes())
                ->map(fn (StockMovementType $t) => $this->typeOption($t))
                ->values(),
            // Filtro da listagem: TODOS os tipos — o extrato também tem
            // entradas por compra e consumos de procedimento.
            'filterTypes' => collect(StockMovementType::cases())
                ->map(fn (StockMovementType $t) => $this->typeOption($t))
                ->values(),
            // Normalizados: a UI mostra a ordenação/filtro realmente aplicados.
            'filters' => [
                'search'            => $search,
                'entity_product_id' => $productId,
                'type'              => $type,
                'sort'              => $sortBy,
                'direction'         => $sortDir,
            ],
            'routes' => [
                'index'          => route('panel.stock.movements.index'),
                'store'          => route('panel.stock.movements.store'),
                'products_index' => route('panel.stock.products.index'),
                // GAP fechado (revisão pós-Fase 4) — leitor de código de
                // barras, ver ProductsController::scanBarcode().
                'scan_barcode' => route('panel.stock.products.scan-barcode'),
            ],
            't' => trans('stock_movements'),
        ]);
    }

    public function store(StockMovementRequest $request): RedirectResponse
    {
        // product() já filtra por entity_id da sessão (ver
        // StockMovementRequest::product()) — 404 antes de chegar aqui se o
        // produto não pertence à clínica ativa.
        $product    = $request->product();
        $unitCost   = $request->filled('unit_cost') ? (float) $request->input('unit_cost') : null;
        $occurredAt = $request->filled('occurred_at') ? Carbon::parse($request->input('occurred_at')) : null;

        // Lote (Fase 2): stock_lot_id (existente) tem prioridade; sem ele,
        // new_lot_number cria um lote novo (só faz sentido em entrada — já
        // validado em StockMovementRequest::withValidator()).
        // findOrCreateLot() é idempotente, mas NÃO abre a mesma transação da
        // movimentação — corrida real (2 lançamentos simultâneos com o MESMO
        // lot_number novo) é resolvida pela constraint unique do banco
        // dentro do próprio findOrCreateLot(), não aqui.
        $lot = $request->existingLot();

        if ($lot === null && $request->newLotNumber() !== null) {
            $lot = $this->stockService->findOrCreateLot($product, $request->newLotNumber(), $request->newLotExpiryDate());
        }

        $this->stockService->manual(
            product: $product,
            type: $request->movementType(),
            quantity: (float) $request->input('quantity'),
            unitCost: $unitCost,
            note: $request->input('note'),
            occurredAt: $occurredAt,
            lot: $lot,
        );

        // Volta para o extrato com a busca/filtros/ordem/página de antes (só
        // parâmetros da whitelist, URL montada pela nossa rota — ver trait).
        return $this->redirectToListing('panel.stock.movements.index', self::LISTING_PARAMS)
            ->with('message', __('stock.movement_registered'));
    }

    /** Busca por produto (nome/código/código de barras), lote ou observação. */
    private function applySearch(Builder $query, string $search): Builder
    {
        return $query->where(fn (Builder $w) => $w
            ->whereHas('product', fn (Builder $p) => $p->where(fn (Builder $pp) => $pp
                ->whereLikeUnaccent('entity_products.name', $search)
                ->orWhereLikeUnaccent('entity_products.code', $search)
                ->orWhereLikeUnaccent('entity_products.barcode', $search)))
            ->orWhereHas('lot', fn (Builder $l) => $l->whereLikeUnaccent('stock_lots.lot_number', $search))
            ->orWhereLikeUnaccent('stock_movements.note', $search));
    }

    /** Coluna (ou subquery, para o nome do produto) usada no ORDER BY. */
    private function sortExpression(string $sortBy): string|QueryBuilder
    {
        if ($sortBy !== 'product') {
            return self::SORTABLE[$sortBy];
        }

        return DB::table('entity_products')
            ->select(self::SORTABLE['product'])
            ->whereColumn('entity_products.id', 'stock_movements.entity_product_id');
    }

    /** @return array{value: string, label: string, direction: int} */
    private function typeOption(StockMovementType $type): array
    {
        return [
            'value'     => $type->value,
            'label'     => $type->label(), // lang/{locale}/stock_enums.php
            'direction' => $type->direction(),
        ];
    }

    /** Parâmetro de query como string; array/objeto (ex.: `sort[]=x`) volta ao padrão. */
    private function stringParam(Request $request, string $key, string $default = ''): string
    {
        $value = $request->input($key, $default);

        return is_string($value) ? trim($value) : $default;
    }
}
