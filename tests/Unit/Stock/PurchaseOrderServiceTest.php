<?php

declare(strict_types=1);

use App\Enums\PurchaseOrderStatus;
use App\Exceptions\LotRequiredException;
use App\Models\{Entity, EntityProduct, StockLot, Supplier};
use App\Services\Stock\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// tests/Unit não herda TestCase::class + RefreshDatabase de Pest.php (só
// 'Feature' herda) — mesmo padrão dos demais testes de Unit/Stock.
uses(TestCase::class, RefreshDatabase::class);

/**
 * Fase 4 — compras. Testa o ciclo de vida do pedido direto no service, sem
 * HTTP/ACL (isso fica em tests/Feature/Stock/PurchaseOrdersTest.php).
 */
beforeEach(function () {
    $this->service  = app(PurchaseOrderService::class);
    $this->entity   = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->supplier = Supplier::create(['entity_id' => $this->entity->id, 'name' => 'Fornecedor Alfa', 'active' => true]);
    $this->product  = EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'Lente IOL', 'unit' => 'un', 'active' => true]);
});

function createDraftPo($test, ?array $items = null)
{
    return $test->service->createDraft($test->entity->id, ['supplier_id' => $test->supplier->id], $items ?? [
        ['entity_product_id' => $test->product->id, 'quantity_ordered' => 10, 'unit_cost' => 50.00],
    ]);
}

it('cria rascunho com itens e calcula subtotal/total corretamente', function () {
    $po = createDraftPo($this);

    expect($po->status)->toBe(PurchaseOrderStatus::Draft)
        ->and($po->items)->toHaveCount(1)
        ->and((float) $po->items->first()->subtotal)->toBe(500.0)
        ->and((float) $po->total_amount)->toBe(500.0);
});

it('updateDraft substitui os itens e recalcula o total', function () {
    $po = createDraftPo($this);

    $updated = $this->service->updateDraft($po, [], [
        ['entity_product_id' => $this->product->id, 'quantity_ordered' => 4, 'unit_cost' => 100.00],
    ]);

    expect($updated->items)->toHaveCount(1)
        ->and((float) $updated->total_amount)->toBe(400.0);
});

it('não permite editar pedido que não está em rascunho', function () {
    $po = createDraftPo($this);
    $this->service->send($po);

    expect(fn () => $this->service->updateDraft($po->fresh(), [], []))
        ->toThrow(InvalidArgumentException::class);
});

it('send() rejeita pedido sem itens', function () {
    $po = createDraftPo($this, []);

    expect(fn () => $this->service->send($po))->toThrow(InvalidArgumentException::class);
});

it('send() move draft -> sent; segundo send() é rejeitado (já não é draft)', function () {
    $po   = createDraftPo($this);
    $sent = $this->service->send($po);

    expect($sent->status)->toBe(PurchaseOrderStatus::Sent);
    expect(fn () => $this->service->send($sent))->toThrow(InvalidArgumentException::class);
});

it('cancel() funciona a partir de sent; rejeita a partir de received', function () {
    $po        = $this->service->send(createDraftPo($this));
    $cancelled = $this->service->cancel($po);
    expect($cancelled->status)->toBe(PurchaseOrderStatus::Cancelled);

    $po2  = $this->service->send(createDraftPo($this));
    $item = $po2->items->first();
    $this->service->receive($po2, [['purchase_order_item_id' => $item->id, 'quantity' => 10, 'stock_lot_id' => null, 'new_lot_number' => null, 'new_lot_expiry_date' => null]]);

    expect(fn () => $this->service->cancel($po2->fresh()))->toThrow(InvalidArgumentException::class);
});

it('receive() total baixa TODO o pedido: produto ganha saldo/custo médio, status vira received, received_value correto', function () {
    // Lançamento financeiro (CashFlowService) é responsabilidade do
    // CONTROLLER, não deste service (tenant-agnóstico, sem sessão HTTP) —
    // ver docblock da classe. Coberto em tests/Feature/Stock/PurchaseOrdersTest.php.
    $po   = $this->service->send(createDraftPo($this));
    $item = $po->items->first();

    $result = $this->service->receive($po, [
        ['purchase_order_item_id' => $item->id, 'quantity' => 10, 'stock_lot_id' => null, 'new_lot_number' => null, 'new_lot_expiry_date' => null],
    ]);

    expect($result['purchase_order']->status)->toBe(PurchaseOrderStatus::Received)
        ->and($result['purchase_order']->received_at)->not->toBeNull()
        ->and($result['received_value'])->toBe(500.0)
        ->and((float) $this->product->fresh()->qty_on_hand)->toBe(10.0)
        ->and((float) $this->product->fresh()->cost_avg)->toBe(50.0);
});

it('receive() PARCIAL deixa status partially_received; segundo recebimento completa e vira received', function () {
    $po   = $this->service->send(createDraftPo($this));
    $item = $po->items->first();

    $r1 = $this->service->receive($po, [
        ['purchase_order_item_id' => $item->id, 'quantity' => 4, 'stock_lot_id' => null, 'new_lot_number' => null, 'new_lot_expiry_date' => null],
    ]);
    expect($r1['purchase_order']->status)->toBe(PurchaseOrderStatus::PartiallyReceived)
        ->and((float) $this->product->fresh()->qty_on_hand)->toBe(4.0);

    $r2 = $this->service->receive($r1['purchase_order'], [
        ['purchase_order_item_id' => $item->id, 'quantity' => 6, 'stock_lot_id' => null, 'new_lot_number' => null, 'new_lot_expiry_date' => null],
    ]);
    expect($r2['purchase_order']->status)->toBe(PurchaseOrderStatus::Received)
        ->and((float) $this->product->fresh()->qty_on_hand)->toBe(10.0);
});

it('[REGRA DE NEGÓCIO] não deixa receber mais do que o saldo pendente do item', function () {
    $po   = $this->service->send(createDraftPo($this));
    $item = $po->items->first();

    expect(fn () => $this->service->receive($po, [
        ['purchase_order_item_id' => $item->id, 'quantity' => 999, 'stock_lot_id' => null, 'new_lot_number' => null, 'new_lot_expiry_date' => null],
    ]))->toThrow(InvalidArgumentException::class);

    expect((float) $this->product->fresh()->qty_on_hand)->toBe(0.0)
        ->and((float) $item->fresh()->quantity_received)->toBe(0.0);
});

it('[ATOMICIDADE] se um item da mesma chamada falhar, NENHUM item desta chamada baixa', function () {
    $productB = EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'Viscoelástico', 'unit' => 'un', 'active' => true]);

    $po = $this->service->send($this->service->createDraft($this->entity->id, ['supplier_id' => $this->supplier->id], [
        ['entity_product_id' => $this->product->id, 'quantity_ordered' => 10, 'unit_cost' => 50.00],
        ['entity_product_id' => $productB->id, 'quantity_ordered' => 5, 'unit_cost' => 20.00],
    ]));

    $itemA = $po->items->firstWhere('entity_product_id', $this->product->id);
    $itemB = $po->items->firstWhere('entity_product_id', $productB->id);

    expect(fn () => $this->service->receive($po, [
        ['purchase_order_item_id' => $itemA->id, 'quantity' => 10, 'stock_lot_id' => null, 'new_lot_number' => null, 'new_lot_expiry_date' => null],
        ['purchase_order_item_id' => $itemB->id, 'quantity' => 999, 'stock_lot_id' => null, 'new_lot_number' => null, 'new_lot_expiry_date' => null], // estoura o pendente
    ]))->toThrow(InvalidArgumentException::class);

    // item A também não commitou, mesmo tendo uma quantidade válida sozinho.
    expect((float) $this->product->fresh()->qty_on_hand)->toBe(0.0)
        ->and((float) $itemA->fresh()->quantity_received)->toBe(0.0);
});

it('[REGRA DE NEGÓCIO] produto requires_lot=true sem lote informado no recebimento lança LotRequiredException', function () {
    $lotProduct = EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'OPM', 'unit' => 'un', 'requires_lot' => true, 'active' => true]);

    $po = $this->service->send($this->service->createDraft($this->entity->id, ['supplier_id' => $this->supplier->id], [
        ['entity_product_id' => $lotProduct->id, 'quantity_ordered' => 3, 'unit_cost' => 900.00],
    ]));
    $item = $po->items->first();

    expect(fn () => $this->service->receive($po, [
        ['purchase_order_item_id' => $item->id, 'quantity' => 3, 'stock_lot_id' => null, 'new_lot_number' => null, 'new_lot_expiry_date' => null],
    ]))->toThrow(LotRequiredException::class);
});

it('receive() com new_lot_number cria o lote e vincula o stock_movement a ele', function () {
    $lotProduct = EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'OPM', 'unit' => 'un', 'requires_lot' => true, 'active' => true]);

    $po = $this->service->send($this->service->createDraft($this->entity->id, ['supplier_id' => $this->supplier->id], [
        ['entity_product_id' => $lotProduct->id, 'quantity_ordered' => 3, 'unit_cost' => 900.00],
    ]));
    $item = $po->items->first();

    $this->service->receive($po, [
        ['purchase_order_item_id' => $item->id, 'quantity' => 3, 'stock_lot_id' => null, 'new_lot_number' => 'LOTE-PO-1', 'new_lot_expiry_date' => now()->addYear()],
    ]);

    $lot = StockLot::query()->where('entity_product_id', $lotProduct->id)->where('lot_number', 'LOTE-PO-1')->first();
    expect($lot)->not->toBeNull()
        ->and((float) $lot->qty_on_hand)->toBe(3.0);
});
