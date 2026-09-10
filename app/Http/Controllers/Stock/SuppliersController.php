<?php

declare(strict_types=1);

namespace App\Http\Controllers\Stock;

use App\Http\Controllers\Controller;
use App\Http\Requests\SupplierRequest;
use App\Http\Resources\SupplierResource;
use App\Models\Supplier;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Inertia\{Inertia, Response as InertiaResponse};

/**
 * CRUD de fornecedores DA CLÍNICA (Fase 4) — App\Models\Supplier. Mesmo
 * padrão manual (sem BaseSettingController) de ProductsController: campos
 * demais (documento, contato) pro catálogo genérico name/code/active
 * comportar.
 *
 * Isolamento OBRIGATÓRIO: toda query filtra por entity_id da sessão;
 * update/destroy re-checam posse — mesma regra de ProductsController.
 */
class SuppliersController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        $entityId = (string) session('selected_entity_id');
        $search   = $request->string('search')->trim()->value();
        $status   = $request->string('status', 'all')->value();

        $records = Supplier::query()
            ->where('entity_id', $entityId)
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->whereLikeUnaccent('name', $search)
                        ->orWhereLikeUnaccent('document', $search)
                        ->orWhereLikeUnaccent('code', $search);
                });
            })
            ->when($status === 'active', fn ($query) => $query->where('active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('active', false))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Supplier $record) => (new SupplierResource($record))->resolve());

        return Inertia::render('Panel/Stock/Suppliers/Index', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('actions.sidemenu.stock'), 'url' => '#', 'active' => false],
                ['label' => __('actions.sidemenu.suppliers'), 'url' => '#', 'active' => true],
            ],
            'items'   => $records,
            'filters' => ['search' => $search, 'status' => $status],
            'routes'  => [
                'index'                 => route('panel.stock.suppliers.index'),
                'store'                 => route('panel.stock.suppliers.store'),
                'update'                => route('panel.stock.suppliers.update', ['__ID__']),
                'destroy'               => route('panel.stock.suppliers.destroy', ['__ID__']),
                'purchase_orders_index' => route('panel.stock.purchase-orders.index'),
            ],
        ]);
    }

    public function show(Supplier $supplier): JsonResponse
    {
        $this->assertOwnership($supplier);

        return response()->json(['data' => new SupplierResource($supplier)]);
    }

    public function store(SupplierRequest $request): RedirectResponse
    {
        $data              = $request->validated();
        $data['entity_id'] = (string) session('selected_entity_id');

        Supplier::create($data);

        return redirect()->route('panel.stock.suppliers.index')->with('message', __('stock.supplier_created'));
    }

    public function update(SupplierRequest $request, Supplier $supplier): RedirectResponse
    {
        $this->assertOwnership($supplier);

        $supplier->update($request->validated());

        return redirect()->route('panel.stock.suppliers.index')->with('message', __('stock.supplier_updated'));
    }

    public function destroy(Supplier $supplier): RedirectResponse
    {
        $this->assertOwnership($supplier);

        $supplier->delete();

        return redirect()->route('panel.stock.suppliers.index')->with('message', __('stock.supplier_deleted'));
    }

    private function assertOwnership(Supplier $supplier): void
    {
        abort_unless((string) $supplier->entity_id === (string) session('selected_entity_id'), 404);
    }
}
