<?php

declare(strict_types=1);

namespace App\Http\Controllers\Stock;

use App\Enums\StockUnit;
use App\Http\Controllers\Controller;
use App\Http\Requests\EntityProductRequest;
use App\Http\Resources\EntityProductResource;
use App\Models\{EntityProduct, ProductCategory};
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Support\Facades\DB;
use Inertia\{Inertia, Response as InertiaResponse};

/**
 * CRUD do catálogo de produtos/materiais de estoque DA CLÍNICA —
 * App\Models\EntityProduct. Mesmo padrão manual (sem BaseSettingController)
 * de IolLensesController: campos demais (categoria, unidade, custo, saldo)
 * pro catálogo genérico name/code/active comportar.
 *
 * Isolamento OBRIGATÓRIO: toda query filtra por entity_id da sessão; show/
 * update/destroy re-checam posse no model resolvido pelo route model
 * binding (nunca confiar só no binding pra isolamento entre clínicas —
 * mesma regra de IolLensesController::assertOwnership()).
 *
 * NÃO expõe qty_on_hand/cost_avg pra escrita — ver doc de EntityProduct e
 * EntityProductRequest. Ajuste de saldo é feito exclusivamente por
 * StockMovementsController (App\Services\Stock\StockService).
 */
class ProductsController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        $entityId     = (string) session('selected_entity_id');
        $search       = $request->string('search')->trim()->value();
        $status       = $request->string('status', 'all')->value(); // active|inactive|all
        $categoryId   = $request->string('category_id')->trim()->value();
        $lowStock     = $request->boolean('low_stock');
        $expiringLots = $request->boolean('expiring_lots');

        $records = EntityProduct::query()
            ->where('entity_id', $entityId)
            ->with(['category', 'lots' => fn ($q) => $q->active()->withBalance()->orderBy('expiry_date')])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->whereLikeUnaccent('name', $search)
                        ->orWhereLikeUnaccent('sku', $search)
                        ->orWhereLikeUnaccent('code', $search)
                        ->orWhereLikeUnaccent('barcode', $search);
                });
            })
            ->when($status === 'active', fn ($query) => $query->where('active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('active', false))
            ->when($categoryId !== '', fn ($query) => $query->where('product_category_id', $categoryId))
            ->when($lowStock, fn ($query) => $query->belowMinimum())
            // 30 dias fixo por ora — o dono do SaaS pode querer configurar
            // esse limiar por clínica no futuro; não é pedido hoje.
            ->when($expiringLots, fn ($query) => $query->withExpiringLots(30))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (EntityProduct $record) => (new EntityProductResource($record))->resolve());

        return Inertia::render('Panel/Stock/Products/Index', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('actions.sidemenu.stock'), 'url' => '#', 'active' => false],
                ['label' => __('actions.sidemenu.products'), 'url' => '#', 'active' => true],
            ],
            'items'      => $records,
            'categories' => ProductCategory::query()
                ->where('entity_id', $entityId)
                ->active()
                ->orderBy('name')
                ->get(['id', 'name']),
            'units' => collect(StockUnit::cases())
                ->map(fn (StockUnit $u) => ['value' => $u->value, 'label' => $u->label()])
                ->values(),
            'filters' => [
                'search'        => $search,
                'status'        => $status,
                'category_id'   => $categoryId,
                'low_stock'     => $lowStock,
                'expiring_lots' => $expiringLots,
            ],
            'routes' => [
                'index'           => route('panel.stock.products.index'),
                'store'           => route('panel.stock.products.store'),
                'show'            => route('panel.stock.products.show', ['__ID__']),
                'update'          => route('panel.stock.products.update', ['__ID__']),
                'destroy'         => route('panel.stock.products.destroy', ['__ID__']),
                'movements_index' => route('panel.stock.movements.index'),
                'lots_index'      => route('panel.stock.products.lots.index', ['__ID__']),
                'lots_update'     => route('panel.stock.lots.update', ['__ID__']),
                // GAP fechado (revisão pós-Fase 4) — importação em massa.
                'import_index' => route('panel.stock.products.import.index'),
            ],
        ]);
    }

    public function show(EntityProduct $entityProduct): JsonResponse
    {
        $this->assertOwnership($entityProduct);

        return response()->json(['data' => new EntityProductResource($entityProduct)]);
    }

    /**
     * Autocomplete de produtos (GAP-FILL pós-Fase 4) — consumido pelo
     * vínculo opcional Lente IOL ↔ Produto (ver EntityIolLensRequest,
     * IolLensFormModal.vue). Mesmo padrão de resposta de
     * ProcedureSearchController/IolLensesController::search(): `label` pronto
     * pra exibição, consumido por SearchSelect em modo remoto.
     */
    public function search(Request $request): JsonResponse
    {
        $term = $request->string('q')->trim()->value();

        if (mb_strlen($term, 'UTF-8') < 2) {
            return response()->json(['data' => []]);
        }

        $entityId = (string) session('selected_entity_id');

        $results = EntityProduct::query()
            ->where('entity_id', $entityId)
            ->where('active', true)
            ->where(fn ($q) => $q->whereLikeUnaccent('name', $term)
                ->orWhereLikeUnaccent('sku', $term)
                ->orWhereLikeUnaccent('code', $term))
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'code', 'unit', 'qty_on_hand']);

        return response()->json([
            // `product_name` (não `name`) DE PROPÓSITO: SearchSelect (modo
            // remoto) sobrescreve a chave `name` de cada linha com `label`
            // (ver watch(searchTerm) em SearchSelect.vue — normaliza pra
            // `[labelKey]: r.label ?? r[labelKey]`, labelKey default é
            // 'name'); se esta resposta também usasse `name` como campo
            // semântico, o consumidor (onProductOptionSelected) leria o
            // LABEL formatado ("Nome (CÓD)") em vez do nome puro. Mesmo
            // cuidado que IolLensesController::search() resolve lendo
            // manufacturer/model_name (nunca `name`) no consumidor.
            'data' => $results->map(fn (EntityProduct $p) => [
                'id'           => $p->id,
                'product_name' => $p->name,
                'code'         => $p->code,
                'unit_label'   => $p->unit?->label(),
                'qty_on_hand'  => (float) $p->qty_on_hand,
                'label'        => trim($p->name . ($p->code ? " ({$p->code})" : '')),
            ])->values(),
        ]);
    }

    /**
     * GAP fechado (revisão pós-Fase 4 — "melhorar o módulo de estoque"):
     * leitor de código de barras USB/Bluetooth funciona como teclado (digita
     * os dígitos + Enter) — não precisa de driver especial, só um campo de
     * texto que dispara esta busca por MATCH EXATO (não fuzzy — código de
     * barras tem que resolver pra UM produto só, nunca uma lista) ao
     * detectar o Enter. Consumido por MovementFormModal.vue.
     */
    public function scanBarcode(Request $request): JsonResponse
    {
        $barcode = $request->string('barcode')->trim()->value();

        if ($barcode === '') {
            return response()->json(['message' => __('stock.barcode_required')], 422);
        }

        $entityId = (string) session('selected_entity_id');

        $product = EntityProduct::query()
            ->where('entity_id', $entityId)
            ->where('barcode', $barcode)
            ->where('active', true)
            ->first();

        if (! $product) {
            return response()->json(['message' => __('stock.barcode_not_found')], 404);
        }

        return response()->json(['data' => new EntityProductResource($product)]);
    }

    public function store(EntityProductRequest $request): RedirectResponse
    {
        $entityId = (string) session('selected_entity_id');

        DB::transaction(function () use ($request, $entityId) {
            $data              = $request->validated();
            $data['entity_id'] = $entityId;

            EntityProduct::create($data);
        });

        return redirect()
            ->route('panel.stock.products.index')
            ->with('message', __('stock.product_created'));
    }

    public function update(EntityProductRequest $request, EntityProduct $entityProduct): RedirectResponse
    {
        $this->assertOwnership($entityProduct);

        $entityProduct->update($request->validated());

        return redirect()
            ->route('panel.stock.products.index')
            ->with('message', __('stock.product_updated'));
    }

    public function destroy(EntityProduct $entityProduct): RedirectResponse
    {
        $this->assertOwnership($entityProduct);

        $entityProduct->delete();

        return redirect()
            ->route('panel.stock.products.index')
            ->with('message', __('stock.product_deleted'));
    }

    private function assertOwnership(EntityProduct $entityProduct): void
    {
        abort_unless(
            (string) $entityProduct->entity_id === (string) session('selected_entity_id'),
            404,
        );
    }
}
