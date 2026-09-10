<?php

declare(strict_types=1);

namespace App\Http\Controllers\Stock;

use App\Enums\{FinancialEntryStatus, FinancialEntryType};
use App\Http\Controllers\Controller;
use App\Http\Requests\{PurchaseOrderRequest, ReceivePurchaseOrderRequest};
use App\Http\Resources\PurchaseOrderResource;
use App\Models\{Entity, EntityProduct, PurchaseOrder, StockLot, Supplier};
use App\Services\Financial\CashFlowService;
use App\Services\Stock\PurchaseOrderService;
use Barryvdh\Snappy\Facades\SnappyPdf;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Support\Facades\Log;
use Inertia\{Inertia, Response as InertiaResponse};
use InvalidArgumentException;
use Throwable;

/**
 * Pedido de compra (Fase 4) — App\Services\Stock\PurchaseOrderService.
 *
 * `receive()` é o único método que aciona App\Services\Financial\
 * CashFlowService — de propósito NÃO mora no service (tenant-agnóstico,
 * sem sessão HTTP): aqui SIM há sessão de verdade, é o lugar certo. Ver
 * docblock de PurchaseOrderService.
 *
 * Isolamento OBRIGATÓRIO: toda query filtra por entity_id da sessão;
 * mutações re-checam posse no model resolvido pelo route model binding.
 */
class PurchaseOrdersController extends Controller
{
    public function __construct(
        private readonly PurchaseOrderService $purchaseOrderService,
        private readonly CashFlowService $cashFlowService,
    ) {
    }

    public function index(Request $request): InertiaResponse
    {
        $entityId   = (string) session('selected_entity_id');
        $status     = $request->string('status', 'all')->value();
        $supplierId = $request->string('supplier_id')->trim()->value();

        $records = PurchaseOrder::query()
            ->where('entity_id', $entityId)
            ->with('supplier')
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->when($supplierId !== '', fn ($query) => $query->where('supplier_id', $supplierId))
            ->orderByDesc('order_date')
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (PurchaseOrder $po) => (new PurchaseOrderResource($po))->resolve());

        return Inertia::render('Panel/Stock/PurchaseOrders/Index', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('actions.sidemenu.stock'), 'url' => '#', 'active' => false],
                ['label' => __('actions.sidemenu.purchase_orders'), 'url' => '#', 'active' => true],
            ],
            'items'     => $records,
            'suppliers' => Supplier::query()->where('entity_id', $entityId)->active()->orderBy('name')->get(['id', 'name']),
            // GAP fechado (revisão pós-Fase 4 — max_qty existia no cadastro
            // do produto desde a Fase 1 mas nunca era lido em lugar nenhum
            // do sistema, decorativo): `suggested_qty` alimenta o botão
            // "Adicionar produtos abaixo do mínimo" do form de pedido —
            // repõe até max_qty quando configurado, senão só até min_qty.
            // Mesma regra de EntityProduct::isBelowMinimum() (min_qty > 0 —
            // produto nunca configurado não é falso-positivo).
            'products' => EntityProduct::query()
                ->where('entity_id', $entityId)
                ->active()
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'unit', 'requires_lot', 'qty_on_hand', 'min_qty', 'max_qty', 'cost_avg'])
                ->map(fn (EntityProduct $p) => [
                    'id'            => $p->id,
                    'name'          => $p->name,
                    'code'          => $p->code,
                    'unit'          => $p->unit?->value,
                    'requires_lot'  => (bool) $p->requires_lot,
                    'qty_on_hand'   => (float) $p->qty_on_hand,
                    'cost_avg'      => (float) $p->cost_avg,
                    'below_minimum' => $p->isBelowMinimum(),
                    'suggested_qty' => $p->isBelowMinimum()
                        ? round(max((float) ($p->max_qty ?? $p->min_qty) - (float) $p->qty_on_hand, 0), 3)
                        : null,
                ])
                ->values(),
            // Lotes com saldo, agrupados por produto — pré-preenche o
            // seletor de lote existente no modal de recebimento (mesmo
            // padrão de StockMovementsController::index()).
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
                ])->values()),
            'filters' => ['status' => $status, 'supplier_id' => $supplierId],
            'routes'  => [
                'index'           => route('panel.stock.purchase-orders.index'),
                'store'           => route('panel.stock.purchase-orders.store'),
                'show'            => route('panel.stock.purchase-orders.show', ['__ID__']),
                'update'          => route('panel.stock.purchase-orders.update', ['__ID__']),
                'destroy'         => route('panel.stock.purchase-orders.destroy', ['__ID__']),
                'send'            => route('panel.stock.purchase-orders.send', ['__ID__']),
                'cancel'          => route('panel.stock.purchase-orders.cancel', ['__ID__']),
                'receive'         => route('panel.stock.purchase-orders.receive', ['__ID__']),
                'suppliers_index' => route('panel.stock.suppliers.index'),
                'pdf'             => route('panel.stock.purchase-orders.pdf', ['__ID__']),
            ],
        ]);
    }

    public function show(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->assertOwnership($purchaseOrder);

        $purchaseOrder->load(['items.product', 'supplier']);

        return response()->json(['data' => new PurchaseOrderResource($purchaseOrder)]);
    }

    /**
     * GAP fechado (revisão pós-Fase 4): status "Enviado" existia mas nada
     * de fato gerava um documento pra mandar ao fornecedor — "enviar" era
     * só uma troca de status no sistema. PDF disponível em QUALQUER status
     * (inclusive rascunho — impressão interna de conferência antes de
     * enviar de verdade), mesmo padrão de
     * FinancialReportsController::cashFlowPdfResponse().
     */
    public function pdf(PurchaseOrder $purchaseOrder)
    {
        $this->assertOwnership($purchaseOrder);

        $purchaseOrder->load(['items.product', 'supplier']);
        $entity = Entity::findOrFail($purchaseOrder->entity_id);

        try {
            return SnappyPdf::loadView('pdf.stock_purchase_order', [
                'po'          => $purchaseOrder,
                'entity'      => $entity,
                'generatedAt' => now(),
            ])->setPaper('a4')->setOrientation('portrait')->download("pedido_compra_{$purchaseOrder->code}.pdf");
        } catch (Throwable) {
            abort(500, 'Falha ao gerar PDF. Verifique a configuração do wkhtmltopdf.');
        }
    }

    public function store(PurchaseOrderRequest $request): RedirectResponse
    {
        $entityId = (string) session('selected_entity_id');

        $this->purchaseOrderService->createDraft($entityId, $request->headerData(), $request->items());

        return redirect()->route('panel.stock.purchase-orders.index')->with('message', __('stock.purchase_order_created'));
    }

    public function update(PurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->assertOwnership($purchaseOrder);

        try {
            $this->purchaseOrderService->updateDraft($purchaseOrder, $request->headerData(), $request->items());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['items' => $e->getMessage()]);
        }

        return redirect()->route('panel.stock.purchase-orders.index')->with('message', __('stock.purchase_order_updated'));
    }

    public function destroy(PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->assertOwnership($purchaseOrder);

        if (! $purchaseOrder->isEditable()) {
            return back()->withErrors(['status' => __('stock.purchase_order_not_editable', ['status' => $purchaseOrder->status->label()])]);
        }

        $purchaseOrder->delete();

        return redirect()->route('panel.stock.purchase-orders.index')->with('message', __('stock.purchase_order_deleted'));
    }

    public function send(PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->assertOwnership($purchaseOrder);

        try {
            $this->purchaseOrderService->send($purchaseOrder);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return redirect()->route('panel.stock.purchase-orders.index')->with('message', __('stock.purchase_order_sent'));
    }

    public function cancel(PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->assertOwnership($purchaseOrder);

        try {
            $this->purchaseOrderService->cancel($purchaseOrder);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return redirect()->route('panel.stock.purchase-orders.index')->with('message', __('stock.purchase_order_cancelled'));
    }

    public function receive(ReceivePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->assertOwnership($purchaseOrder);

        try {
            $result = $this->purchaseOrderService->receive($purchaseOrder, $request->items());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['items' => $e->getMessage()]);
        }

        /** @var PurchaseOrder $po */
        $po            = $result['purchase_order'];
        $receivedValue = $result['received_value'];

        // Lançamento financeiro é BEST-EFFORT — ver docblock da classe e de
        // PurchaseOrderService::receive(). O recebimento físico JÁ commitou
        // (StockService rodou dentro do service); se isto falhar (ex.:
        // período de caixa fechado), só loga e avisa — nunca desfaz estoque.
        $financialMessage = null;

        if ($receivedValue > 0) {
            try {
                $this->cashFlowService->create([
                    'entity_id'      => $po->entity_id,
                    'entry_date'     => now()->toDateString(),
                    'description'    => "Recebimento de compra — pedido {$po->code}",
                    'type'           => FinancialEntryType::Expense->value,
                    'status'         => FinancialEntryStatus::Paid->value,
                    'amount'         => $receivedValue,
                    'reference_type' => PurchaseOrder::class,
                    'reference_id'   => $po->id,
                ]);
            } catch (Throwable $e) {
                Log::warning('PurchaseOrdersController::receive() — lançamento financeiro não criado', [
                    'purchase_order_id' => $po->id,
                    'error'             => $e->getMessage(),
                ]);
                $financialMessage = __('stock.purchase_order_received_financial_pending');
            }
        }

        return redirect()
            ->route('panel.stock.purchase-orders.index')
            ->with('message', $financialMessage ?? __('stock.purchase_order_received'));
    }

    private function assertOwnership(PurchaseOrder $purchaseOrder): void
    {
        abort_unless((string) $purchaseOrder->entity_id === (string) session('selected_entity_id'), 404);
    }
}
