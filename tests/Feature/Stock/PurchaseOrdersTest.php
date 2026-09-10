<?php

declare(strict_types=1);

use App\Enums\{ClientRule, FeatureKey, PurchaseOrderStatus, SubscriptionStatus};
use App\Models\{Entity, EntityProduct, FinancialCashEntry, Plan, PlanFeature, PurchaseOrder, StockLot, Subscription, Supplier, User};

/**
 * Ciclo de vida completo do pedido de compra via HTTP (Fase 4) —
 * App\Http\Controllers\Stock\PurchaseOrdersController.
 *
 * `receive()` é o teste mais importante aqui: só rodando via HTTP de
 * verdade (sessão real) é que se prova a separação service/controller do
 * lançamento financeiro (ver docblock de PurchaseOrderService) — o teste
 * de unidade do service não cobre isso de propósito (sem sessão lá).
 */
beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->plan   = Plan::factory()->create(['active' => true]);
    PlanFeature::factory()->enabled(FeatureKey::HasInventoryModule)->for($this->plan)->create();
    Subscription::factory()->create([
        'entity_id' => $this->entity->id, 'plan_id' => $this->plan->id, 'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
    ]);

    $this->admin           = User::factory()->create();
    $this->adminEntityUser = createEntityUser($this->entity, $this->admin, ClientRule::Admin->value);

    $this->supplier = Supplier::create(['entity_id' => $this->entity->id, 'name' => 'Fornecedor Alfa', 'active' => true]);
    $this->product  = EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'Lente IOL', 'unit' => 'un', 'active' => true]);
});

function actingAsPoAdmin($test, ?User $admin = null, $entityUser = null)
{
    return $test->actingAs($admin ?? $test->admin)->withSession(panelSession($entityUser ?? $test->adminEntityUser));
}

function poPayload($test, array $overrides = []): array
{
    return array_merge([
        'supplier_id' => $test->supplier->id,
        'items'       => [
            ['entity_product_id' => $test->product->id, 'quantity_ordered' => 10, 'unit_cost' => 50.00],
        ],
    ], $overrides);
}

it('admin cria um pedido de compra em rascunho', function () {
    actingAsPoAdmin($this)
        ->post(route('panel.stock.purchase-orders.store'), poPayload($this), ['Accept' => 'application/json'])
        ->assertRedirect(route('panel.stock.purchase-orders.index'));

    $po = PurchaseOrder::query()->where('entity_id', $this->entity->id)->first();
    expect($po)->not->toBeNull()
        ->and($po->status)->toBe(PurchaseOrderStatus::Draft)
        ->and((float) $po->total_amount)->toBe(500.0)
        ->and($po->items)->toHaveCount(1);
});

it('admin edita o rascunho (substitui itens)', function () {
    actingAsPoAdmin($this)->post(route('panel.stock.purchase-orders.store'), poPayload($this), ['Accept' => 'application/json']);
    $po = PurchaseOrder::query()->first();

    actingAsPoAdmin($this)
        ->put(route('panel.stock.purchase-orders.update', $po->id), poPayload($this, [
            'items' => [['entity_product_id' => $this->product->id, 'quantity_ordered' => 3, 'unit_cost' => 100]],
        ]), ['Accept' => 'application/json'])
        ->assertRedirect(route('panel.stock.purchase-orders.index'));

    $po->refresh();
    expect((float) $po->total_amount)->toBe(300.0)
        ->and($po->items)->toHaveCount(1);
});

it('admin envia o pedido ao fornecedor (draft -> sent)', function () {
    actingAsPoAdmin($this)->post(route('panel.stock.purchase-orders.store'), poPayload($this), ['Accept' => 'application/json']);
    $po = PurchaseOrder::query()->first();

    actingAsPoAdmin($this)
        ->post(route('panel.stock.purchase-orders.send', $po->id))
        ->assertRedirect(route('panel.stock.purchase-orders.index'));

    expect($po->fresh()->status)->toBe(PurchaseOrderStatus::Sent);
});

it('[REGRA DE NEGÓCIO] não permite editar pedido que já foi enviado', function () {
    actingAsPoAdmin($this)->post(route('panel.stock.purchase-orders.store'), poPayload($this), ['Accept' => 'application/json']);
    $po = PurchaseOrder::query()->first();
    actingAsPoAdmin($this)->post(route('panel.stock.purchase-orders.send', $po->id));

    // Erro de REGRA DE NEGÓCIO (catch manual no controller) sempre
    // redireciona com sessão de erro — não é FormRequest, não respeita
    // Accept:application/json (diferente das validações abaixo).
    actingAsPoAdmin($this)
        ->put(route('panel.stock.purchase-orders.update', $po->id), poPayload($this))
        ->assertRedirect()
        ->assertSessionHasErrors('items');
});

it('admin cancela um pedido enviado', function () {
    actingAsPoAdmin($this)->post(route('panel.stock.purchase-orders.store'), poPayload($this), ['Accept' => 'application/json']);
    $po = PurchaseOrder::query()->first();
    actingAsPoAdmin($this)->post(route('panel.stock.purchase-orders.send', $po->id));

    actingAsPoAdmin($this)
        ->post(route('panel.stock.purchase-orders.cancel', $po->id))
        ->assertRedirect(route('panel.stock.purchase-orders.index'));

    expect($po->fresh()->status)->toBe(PurchaseOrderStatus::Cancelled);
});

it('admin recebe o pedido TOTALMENTE: estoque baixa, custo médio atualiza, lançamento financeiro de despesa é criado', function () {
    actingAsPoAdmin($this)->post(route('panel.stock.purchase-orders.store'), poPayload($this), ['Accept' => 'application/json']);
    $po = PurchaseOrder::query()->with('items')->first();
    actingAsPoAdmin($this)->post(route('panel.stock.purchase-orders.send', $po->id));
    $item = $po->items->first();

    actingAsPoAdmin($this)
        ->post(route('panel.stock.purchase-orders.receive', $po->id), [
            'items' => [['purchase_order_item_id' => $item->id, 'quantity' => 10]],
        ])
        ->assertRedirect(route('panel.stock.purchase-orders.index'));

    expect($po->fresh()->status)->toBe(PurchaseOrderStatus::Received)
        ->and((float) $this->product->fresh()->qty_on_hand)->toBe(10.0)
        ->and((float) $this->product->fresh()->cost_avg)->toBe(50.0);

    // Prova real da separação service/controller: só funciona com sessão
    // HTTP de verdade (CashFlowService lê session('selected_entity_id')) —
    // ver docblock de PurchaseOrderService::receive().
    $entry = FinancialCashEntry::query()
        ->where('reference_type', PurchaseOrder::class)
        ->where('reference_id', $po->id)
        ->first();
    expect($entry)->not->toBeNull()
        ->and($entry->entity_id)->toBe($this->entity->id)
        ->and($entry->type->value)->toBe('expense')
        ->and((float) $entry->amount)->toBe(500.0);
});

it('admin recebe o pedido PARCIALMENTE: status vira partially_received', function () {
    actingAsPoAdmin($this)->post(route('panel.stock.purchase-orders.store'), poPayload($this), ['Accept' => 'application/json']);
    $po = PurchaseOrder::query()->with('items')->first();
    actingAsPoAdmin($this)->post(route('panel.stock.purchase-orders.send', $po->id));
    $item = $po->items->first();

    actingAsPoAdmin($this)
        ->post(route('panel.stock.purchase-orders.receive', $po->id), [
            'items' => [['purchase_order_item_id' => $item->id, 'quantity' => 4]],
        ])
        ->assertRedirect(route('panel.stock.purchase-orders.index'));

    expect($po->fresh()->status)->toBe(PurchaseOrderStatus::PartiallyReceived)
        ->and((float) $this->product->fresh()->qty_on_hand)->toBe(4.0);
});

it('[REGRA DE NEGÓCIO] produto requires_lot=true sem lote no recebimento é rejeitado (422 — validação de FormRequest)', function () {
    $lotProduct = EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'OPM', 'unit' => 'un', 'requires_lot' => true, 'active' => true]);

    actingAsPoAdmin($this)->post(route('panel.stock.purchase-orders.store'), poPayload($this, [
        'items' => [['entity_product_id' => $lotProduct->id, 'quantity_ordered' => 3, 'unit_cost' => 900]],
    ]), ['Accept' => 'application/json']);
    $po = PurchaseOrder::query()->with('items')->first();
    actingAsPoAdmin($this)->post(route('panel.stock.purchase-orders.send', $po->id));
    $item = $po->items->first();

    actingAsPoAdmin($this)
        ->post(route('panel.stock.purchase-orders.receive', $po->id), [
            'items' => [['purchase_order_item_id' => $item->id, 'quantity' => 3]],
        ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('items.0.stock_lot_id');

    expect((float) $lotProduct->fresh()->qty_on_hand)->toBe(0.0);
});

it('recebimento com new_lot_number cria o lote (recebimento sempre pode criar lote novo)', function () {
    $lotProduct = EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'OPM', 'unit' => 'un', 'requires_lot' => true, 'active' => true]);

    actingAsPoAdmin($this)->post(route('panel.stock.purchase-orders.store'), poPayload($this, [
        'items' => [['entity_product_id' => $lotProduct->id, 'quantity_ordered' => 3, 'unit_cost' => 900]],
    ]), ['Accept' => 'application/json']);
    $po = PurchaseOrder::query()->with('items')->first();
    actingAsPoAdmin($this)->post(route('panel.stock.purchase-orders.send', $po->id));
    $item = $po->items->first();

    actingAsPoAdmin($this)
        ->post(route('panel.stock.purchase-orders.receive', $po->id), [
            'items' => [['purchase_order_item_id' => $item->id, 'quantity' => 3, 'new_lot_number' => 'LOTE-PO-X', 'new_lot_expiry_date' => now()->addYear()->toDateString()]],
        ])
        ->assertRedirect(route('panel.stock.purchase-orders.index'));

    $lot = StockLot::query()->where('entity_product_id', $lotProduct->id)->where('lot_number', 'LOTE-PO-X')->first();
    expect($lot)->not->toBeNull()
        ->and((float) $lot->qty_on_hand)->toBe(3.0);
});

it('[ISOLAMENTO] admin de outra clínica não consegue receber pedido alheio (bloqueado na validação — item não existe pro entity_id da sessão dele)', function () {
    actingAsPoAdmin($this)->post(route('panel.stock.purchase-orders.store'), poPayload($this), ['Accept' => 'application/json']);
    $po = PurchaseOrder::query()->with('items')->first();
    actingAsPoAdmin($this)->post(route('panel.stock.purchase-orders.send', $po->id));
    $item = $po->items->first();

    $otherEntity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $otherPlan   = Plan::factory()->create(['active' => true]);
    PlanFeature::factory()->enabled(FeatureKey::HasInventoryModule)->for($otherPlan)->create();
    Subscription::factory()->create([
        'entity_id' => $otherEntity->id, 'plan_id' => $otherPlan->id, 'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
    ]);
    $otherAdmin      = User::factory()->create();
    $otherEntityUser = createEntityUser($otherEntity, $otherAdmin, ClientRule::Admin->value);

    // Route model binding NÃO escopa por tenant (padrão do resto da base —
    // por isso todo controller re-checa posse) — mas aqui quem barra
    // primeiro é a própria validação de ReceivePurchaseOrderRequest: o
    // item buscado com entity_id da sessão de OUTRA clínica não bate com
    // nenhuma linha de purchase_order_items (que pertence à clínica
    // original), 422 antes de chegar no assertOwnership() do controller.
    actingAsPoAdmin($this, $otherAdmin, $otherEntityUser)
        ->post(route('panel.stock.purchase-orders.receive', $po->id), [
            'items' => [['purchase_order_item_id' => $item->id, 'quantity' => 10]],
        ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('items.0.purchase_order_item_id');

    expect((float) $this->product->fresh()->qty_on_hand)->toBe(0.0);
});
