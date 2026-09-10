<?php

declare(strict_types=1);

namespace App\Http\Controllers\Stock;

use App\Enums\StockMovementType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StockMovementRequest;
use App\Http\Resources\StockMovementResource;
use App\Models\{EntityProduct, StockLot, StockMovement};
use App\Services\Stock\StockService;
use Carbon\Carbon;
use Illuminate\Http\{RedirectResponse, Request};
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
    public function __construct(
        private readonly StockService $stockService,
    ) {
    }

    public function index(Request $request): InertiaResponse
    {
        $entityId  = (string) session('selected_entity_id');
        $productId = $request->string('entity_product_id')->trim()->value();
        $type      = $request->string('type')->trim()->value();

        $records = StockMovement::query()
            ->where('entity_id', $entityId)
            ->with(['product', 'creator', 'lot'])
            ->when($productId !== '', fn ($query) => $query->where('entity_product_id', $productId))
            ->when($type !== '', fn ($query) => $query->where('type', $type))
            ->orderByDesc('occurred_at')
            ->orderByDesc('created_at')
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
            'movementTypes' => collect(StockMovementType::manualTypes())
                ->map(fn ($t) => ['value' => $t->value, 'label' => $t->label(), 'direction' => $t->direction()])
                ->values(),
            'filters' => [
                'entity_product_id' => $productId,
                'type'              => $type,
            ],
            'routes' => [
                'index'          => route('panel.stock.movements.index'),
                'store'          => route('panel.stock.movements.store'),
                'products_index' => route('panel.stock.products.index'),
                // GAP fechado (revisão pós-Fase 4) — leitor de código de
                // barras, ver ProductsController::scanBarcode().
                'scan_barcode' => route('panel.stock.products.scan-barcode'),
            ],
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

        return redirect()
            ->route('panel.stock.movements.index')
            ->with('message', __('stock.movement_registered'));
    }
}
