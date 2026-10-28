<?php

declare(strict_types=1);

namespace App\Services\Stock;

use App\Enums\PurchaseOrderStatus;
use App\Models\{EntityProduct, PurchaseOrder, PurchaseOrderItem, StockLot};
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Ciclo de vida do pedido de compra (Fase 4). Único ponto de escrita de
 * `purchase_orders`/`purchase_order_items` além de create/delete simples de
 * cabeçalho — status, total_amount e quantity_received nunca são setados
 * fora daqui.
 *
 * Tenant-agnóstico DE PROPÓSITO (mesmo princípio de StockService): não lê
 * session() em lugar nenhum, só usa o entity_id já resolvido no
 * PurchaseOrder/parâmetros recebidos. Por isso o lançamento financeiro
 * (App\Services\Financial\CashFlowService::create()) NÃO mora aqui — aquele
 * service lê `session('selected_entity_id')` internamente (acoplado a
 * request HTTP), o que quebraria receive() rodando fora de request (job/CLI)
 * ou em teste. `receive()` devolve `received_value` pro CONTROLLER (que tem
 * sessão de verdade) decidir se/como lançar a despesa — ver
 * Stock\PurchaseOrdersController::receive().
 */
class PurchaseOrderService
{
    public function __construct(
        private readonly StockService $stockService,
    ) {
    }

    /**
     * @param array{supplier_id: string, order_date?: string, expected_delivery_date?: ?string, notes?: ?string} $header
     * @param list<array{entity_product_id: string, quantity_ordered: float, unit_cost: float}>                  $items
     */
    public function createDraft(string $entityId, array $header, array $items): PurchaseOrder
    {
        return DB::transaction(function () use ($entityId, $header, $items) {
            $po = PurchaseOrder::create([
                'entity_id'              => $entityId,
                'supplier_id'            => $header['supplier_id'],
                'order_date'             => $header['order_date'] ?? now()->toDateString(),
                'expected_delivery_date' => $header['expected_delivery_date'] ?? null,
                'notes'                  => $header['notes'] ?? null,
            ]);

            $this->syncItems($po, $items);

            return $po->fresh(['items.product', 'supplier']);
        });
    }

    /**
     * @param array{supplier_id?: string, order_date?: string, expected_delivery_date?: ?string, notes?: ?string} $header
     * @param list<array{entity_product_id: string, quantity_ordered: float, unit_cost: float}>                   $items
     */
    public function updateDraft(PurchaseOrder $po, array $header, array $items): PurchaseOrder
    {
        $this->assertEditable($po);

        return DB::transaction(function () use ($po, $header, $items) {
            // Só atualiza a chave que o caller de fato mandou (edição
            // parcial de cabeçalho) — array_key_exists, não `?? valor
            // atual`, porque `expected_delivery_date`/`notes` são
            // legitimamente anuláveis (remover a data prevista, por
            // exemplo), e `$header['x'] ?? $po->x` nunca deixaria voltar a
            // null.
            $updates = [];

            foreach (['supplier_id', 'order_date', 'expected_delivery_date', 'notes'] as $field) {
                if (array_key_exists($field, $header)) {
                    $updates[$field] = $header[$field];
                }
            }

            if ($updates !== []) {
                $po->update($updates);
            }

            $this->syncItems($po, $items);

            return $po->fresh(['items.product', 'supplier']);
        });
    }

    public function send(PurchaseOrder $po): PurchaseOrder
    {
        $this->assertTransition($po, PurchaseOrderStatus::Sent);

        if ($po->items()->count() === 0) {
            throw new InvalidArgumentException('Pedido sem itens não pode ser enviado.');
        }

        $po->update(['status' => PurchaseOrderStatus::Sent]);

        return $po->fresh();
    }

    public function cancel(PurchaseOrder $po): PurchaseOrder
    {
        $this->assertTransition($po, PurchaseOrderStatus::Cancelled);

        $po->update(['status' => PurchaseOrderStatus::Cancelled]);

        return $po->fresh();
    }

    /**
     * Recebe (total ou parcialmente) os itens do pedido. Cada item confirma
     * a quantidade REALMENTE entregue nesta entrega (pode ser menor que o
     * pendente — entregas parciais do fornecedor são normais). Atômico: se
     * um item falhar (ex.: exige lote e não veio), NENHUM item desta chamada
     * baixa (mesma garantia de MedicalRecordProcedureExecutionService::markDone()).
     *
     * @param list<array{purchase_order_item_id: string, quantity: float, stock_lot_id: ?string, new_lot_number: ?string, new_lot_expiry_date: ?CarbonInterface}> $receivedItems
     *
     * @return array{purchase_order: PurchaseOrder, received_value: float}
     */
    public function receive(PurchaseOrder $po, array $receivedItems): array
    {
        if ($po->status === PurchaseOrderStatus::Draft || $po->status->isTerminal()) {
            throw new InvalidArgumentException("Pedido '{$po->status->label()}' não pode receber itens.");
        }

        if ($receivedItems === []) {
            throw new InvalidArgumentException('Informe ao menos um item recebido.');
        }

        $receivedValue = 0.0;

        $po = DB::transaction(function () use ($po, $receivedItems, &$receivedValue) {
            foreach ($receivedItems as $received) {
                /** @var PurchaseOrderItem $item */
                $item = PurchaseOrderItem::query()
                    ->where('purchase_order_id', $po->id)
                    ->lockForUpdate()
                    ->findOrFail($received['purchase_order_item_id']);

                $qty = (float) $received['quantity'];

                if ($qty <= 0) {
                    throw new InvalidArgumentException('Quantidade recebida deve ser maior que zero.');
                }

                $remaining = $item->remainingQuantity();

                // Tolerância de arredondamento de ponto flutuante (3 casas).
                if ($qty > $remaining + 0.001) {
                    throw new InvalidArgumentException(
                        "Quantidade recebida ({$qty}) maior que o saldo pendente ({$remaining}) do item.",
                    );
                }

                /** @var EntityProduct $product */
                $product = $item->product;

                $lot = null;

                if (! empty($received['stock_lot_id'])) {
                    $lot = StockLot::query()->where('entity_id', $po->entity_id)->findOrFail($received['stock_lot_id']);
                } elseif (! empty($received['new_lot_number'])) {
                    $lot = $this->stockService->findOrCreateLot($product, $received['new_lot_number'], $received['new_lot_expiry_date'] ?? null);
                }

                $this->stockService->purchaseIn(
                    product: $product,
                    quantity: $qty,
                    unitCost: (float) $item->unit_cost,
                    note: "Recebimento — pedido {$po->code}",
                    referenceType: PurchaseOrder::class,
                    referenceId: $po->id,
                    lot: $lot,
                );

                // quantity_received é guardado (fora de $fillable) — update()
                // mass-assignment seria IGNORADO EM SILÊNCIO (mesma lição de
                // EntityProduct.qty_on_hand em StockService). Atribuição
                // direta + save() é o único jeito certo de escrever aqui.
                $item->quantity_received = round((float) $item->quantity_received + $qty, 3);
                $item->save();

                $receivedValue += $qty * (float) $item->unit_cost;
            }

            $this->recalculateStatus($po);

            return $po->fresh(['items.product', 'supplier']);
        });

        return [
            'purchase_order' => $po,
            'received_value' => round($receivedValue, 2),
        ];
    }

    /**
     * @param list<array{entity_product_id: string, quantity_ordered: float, unit_cost: float}> $items
     */
    private function syncItems(PurchaseOrder $po, array $items): void
    {
        $this->assertEditable($po);

        // Rascunho não tem histórico de recebimento a preservar — substitui
        // por completo é mais simples e igualmente correto que um diff.
        $po->items()->delete();

        $total = 0.0;

        foreach ($items as $item) {
            $qty      = (float) $item['quantity_ordered'];
            $cost     = (float) $item['unit_cost'];
            $subtotal = round($qty * $cost, 2);
            $total += $subtotal;

            PurchaseOrderItem::create([
                'entity_id'         => $po->entity_id,
                'purchase_order_id' => $po->id,
                'entity_product_id' => $item['entity_product_id'],
                'quantity_ordered'  => $qty,
                'unit_cost'         => $cost,
                'subtotal'          => $subtotal,
            ]);
        }

        // total_amount é guardado (fora de $fillable) — mesma razão/fix de
        // quantity_received acima: atribuição direta + save(), nunca update().
        $po->total_amount = round($total, 2);
        $po->save();
    }

    private function recalculateStatus(PurchaseOrder $po): void
    {
        $items = $po->items()->get();

        $allFullyReceived = $items->isNotEmpty() && $items->every(fn (PurchaseOrderItem $i) => $i->isFullyReceived());
        $anyReceived      = $items->contains(fn (PurchaseOrderItem $i) => (float) $i->quantity_received > 0);

        $newStatus = match (true) {
            $allFullyReceived => PurchaseOrderStatus::Received,
            $anyReceived      => PurchaseOrderStatus::PartiallyReceived,
            default           => $po->status,
        };

        $po->update([
            'status'      => $newStatus,
            'received_at' => $newStatus === PurchaseOrderStatus::Received ? now() : $po->received_at,
        ]);
    }

    private function assertEditable(PurchaseOrder $po): void
    {
        if (! $po->status->isEditable()) {
            throw new InvalidArgumentException("Pedido '{$po->status->label()}' não pode ser editado — só rascunho.");
        }
    }

    private function assertTransition(PurchaseOrder $po, PurchaseOrderStatus $target): void
    {
        if (! $po->status->canTransitionTo($target)) {
            throw new InvalidArgumentException(
                "Pedido está '{$po->status->label()}' — não pode virar '{$target->label()}'.",
            );
        }
    }
}
