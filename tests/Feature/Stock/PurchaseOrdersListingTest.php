<?php

declare(strict_types=1);

use App\Enums\{ClientRule, PurchaseOrderStatus};
use App\Models\{Entity, EntityProduct, PurchaseOrder, Supplier, User};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Listagem de pedidos de compra no padrão de Panel/Patients: busca (código
 * ou fornecedor), ordenação por whitelist (PurchaseOrdersController::SORTABLE)
 * com desempate por id, filtros normalizados, textos via `t`
 * (lang/{locale}/stock_purchase_orders.php) e isolamento por clínica.
 */
beforeEach(function () {
    $this->entity      = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->otherEntity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    giveInventoryModuleAccess($this->entity);

    $this->admin           = User::factory()->create();
    $this->adminEntityUser = createEntityUser($this->entity, $this->admin, ClientRule::Admin->value);

    $this->alfa = Supplier::create(['entity_id' => $this->entity->id, 'name' => 'Alfa Óptica', 'active' => true]);
    $this->beta = Supplier::create(['entity_id' => $this->entity->id, 'name' => 'Beta Lentes', 'active' => true]);
});

function listingPurchaseOrder(Supplier $supplier, array $attributes = [], float $total = 0): PurchaseOrder
{
    // total_amount (só o service escreve) e id ficam fora do $fillable — aqui é fixture.
    $po = (new PurchaseOrder())->forceFill(array_merge([
        'entity_id'    => $supplier->entity_id,
        'supplier_id'  => $supplier->id,
        'order_date'   => '2026-09-01',
        'status'       => PurchaseOrderStatus::Draft,
        'total_amount' => $total,
    ], $attributes));
    $po->save();

    return $po;
}

function purchaseOrdersListingAs(array $query = [])
{
    return test()->actingAs(test()->admin)
        ->withSession(panelSession(test()->adminEntityUser))
        ->get(route('panel.stock.purchase-orders.index', $query));
}

/** @return array<int, mixed> */
function purchaseOrdersListingColumn(array $query, string $column): array
{
    $values = [];

    purchaseOrdersListingAs($query)->assertOk()->assertInertia(function (Assert $page) use (&$values, $column) {
        $values = array_column($page->toArray()['props']['items']['data'], $column);
    });

    return $values;
}

function poListingPayload(array $overrides = []): array
{
    return array_merge([
        'supplier_id' => test()->alfa->id,
        'items'       => [['entity_product_id' => test()->product->id, 'quantity_ordered' => 10, 'unit_cost' => 5]],
    ], $overrides);
}

/** Ação (store/update/send/…) disparada a partir de uma URL (Referer). */
function purchaseOrderActionAs(string $from)
{
    return test()->actingAs(test()->admin)
        ->withSession(panelSession(test()->adminEntityUser))
        ->from($from);
}

/**
 * SQL da query paginada da listagem (a que tem LIMIT 15), para provar a
 * cláusula ORDER BY — o resultado sozinho não prova o desempate por id,
 * porque o PostgreSQL costuma devolver a ordem de inserção em tabela pequena.
 */
function purchaseOrdersListingSql(array $query): string
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    purchaseOrdersListingAs($query)->assertOk();

    $sql = collect(DB::getQueryLog())
        ->pluck('query')
        ->first(fn (string $sql) => str_starts_with($sql, 'select * from "purchase_orders"') && str_contains($sql, 'limit 15'));

    DB::disableQueryLog();

    expect($sql)->toBeString();

    return $sql;
}

describe('Pedidos de compra — ordenação, busca e filtros como em pacientes', function () {
    it('sem parâmetros mantém a ordem de sempre (data desc, depois criação desc) e devolve filtros e textos', function () {
        $old    = listingPurchaseOrder($this->alfa, ['order_date' => '2026-08-01']);
        $first  = listingPurchaseOrder($this->alfa, ['order_date' => '2026-09-10']);
        $second = listingPurchaseOrder($this->beta, ['order_date' => '2026-09-10']);
        // Mesma data do pedido: desempata pela criação (created_at não é fillable).
        $first->forceFill(['created_at' => now()->subHour()])->save();
        $second->forceFill(['created_at' => now()])->save();

        expect(purchaseOrdersListingColumn([], 'id'))->toBe([$second->id, $first->id, $old->id]);

        purchaseOrdersListingAs()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Panel/Stock/PurchaseOrders/Index')
                ->where('filters.search', '')
                ->where('filters.status', 'all')
                ->where('filters.supplier_id', '')
                ->where('filters.sort', 'order_date')
                ->where('filters.direction', 'desc')
                ->where('t.page_title', 'Pedidos de compra')
                ->where('selectedSupplier', null)
                ->has('t.columns_label')
                ->has('t.close')
                ->has('t.pagination_suffix'));
    });

    it('rótulos de status vêm do enum traduzido (prop `statuses`), não duplicados em `t`', function () {
        $po = listingPurchaseOrder($this->alfa, ['status' => PurchaseOrderStatus::PartiallyReceived]);

        purchaseOrdersListingAs()
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('statuses', count(PurchaseOrderStatus::cases()))
                ->where('statuses.0', ['value' => 'draft', 'label' => 'Rascunho'])
                ->where('statuses.2', ['value' => 'partially_received', 'label' => 'Recebido parcialmente'])
                ->where('statuses.4', ['value' => 'cancelled', 'label' => 'Cancelado'])
                ->where('items.data.0.id', $po->id)
                ->where('items.data.0.status_label', 'Recebido parcialmente')
                ->missing('t.status_draft')
                ->missing('t.status_partially_received'));
    });

    it('ordena por total, fornecedor e código conforme a whitelist', function () {
        $a = listingPurchaseOrder($this->beta, [], 300);
        $b = listingPurchaseOrder($this->alfa, [], 100);
        $c = listingPurchaseOrder($this->beta, [], 200);

        expect(purchaseOrdersListingColumn(['sort' => 'total_amount', 'direction' => 'asc'], 'id'))
            ->toBe([$b->id, $c->id, $a->id]);

        expect(purchaseOrdersListingColumn(['sort' => 'supplier_name', 'direction' => 'asc'], 'supplier_name'))
            ->toBe(['Alfa Óptica', 'Beta Lentes', 'Beta Lentes']);

        expect(purchaseOrdersListingColumn(['sort' => 'code', 'direction' => 'desc'], 'code'))
            ->toBe([$c->code, $b->code, $a->code]);
    });

    it('ordenação/direção/status/fornecedor inválidos caem no padrão e voltam normalizados (sem erro 500)', function () {
        listingPurchaseOrder($this->alfa);

        purchaseOrdersListingAs([
            'sort'        => 'total_amount; drop table purchase_orders',
            'direction'   => 'sideways',
            'status'      => 'bogus',
            'supplier_id' => 'nao-e-uuid',
        ])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.sort', 'order_date')
                ->where('filters.direction', 'desc')
                ->where('filters.status', 'all')
                ->where('filters.supplier_id', '')
                ->where('items.total', 1));
    });

    it('busca por código ou nome do fornecedor (sem acento) e preserva status/fornecedor', function () {
        $po = listingPurchaseOrder($this->alfa, ['status' => PurchaseOrderStatus::Sent]);
        listingPurchaseOrder($this->alfa);
        listingPurchaseOrder($this->beta, ['status' => PurchaseOrderStatus::Sent]);

        expect(purchaseOrdersListingColumn(['search' => 'optica', 'status' => 'sent'], 'id'))->toBe([$po->id]);
        expect(purchaseOrdersListingColumn(['search' => $po->code], 'id'))->toBe([$po->id]);

        purchaseOrdersListingAs(['search' => 'optica', 'status' => 'sent', 'supplier_id' => $this->alfa->id])
            ->assertInertia(fn (Assert $page) => $page
                ->where('items.total', 1)
                ->where('filters.search', 'optica')
                ->where('filters.status', 'sent')
                ->where('filters.supplier_id', $this->alfa->id));
    });

    it('pagina sem repetir nem pular pedidos com o mesmo total (desempate por id)', function () {
        // Ids gravados em ordem DECRESCENTE: sem o desempate por id o
        // PostgreSQL tende a devolver a ordem física (inserção), que aqui é o
        // contrário da esperada — o teste só passa com `orderBy('purchase_orders.id')`.
        $ids = collect(range(1, 16))->map(fn () => (string) Str::uuid())->sort()->values();

        foreach ($ids->reverse() as $id) {
            listingPurchaseOrder($this->alfa, ['id' => $id], 50);
        }

        $listed = collect([1, 2])->flatMap(fn (int $page) => purchaseOrdersListingColumn(
            ['sort' => 'total_amount', 'direction' => 'asc', 'page' => $page],
            'id',
        ));

        expect($listed->all())->toBe($ids->all());
    });

    it('parâmetros de query em formato de array não derrubam a listagem (sem erro 500) e caem no padrão', function () {
        listingPurchaseOrder($this->alfa);

        purchaseOrdersListingAs([
            'search'      => ['x'],
            'status'      => ['sent'],
            'supplier_id' => [$this->alfa->id],
            'sort'        => ['code'],
            'direction'   => ['asc'],
            'page'        => ['2'],
        ])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.search', '')
                ->where('filters.status', 'all')
                ->where('filters.supplier_id', '')
                ->where('filters.sort', 'order_date')
                ->where('filters.direction', 'desc')
                ->where('selectedSupplier', null)
                ->where('items.total', 1));
    });

    it('toda chave da whitelist ordena pela coluna certa e desempata por id', function (string $sort, string $orderBy, string $direction) {
        listingPurchaseOrder($this->alfa, [], 10);

        purchaseOrdersListingAs(['sort' => $sort, 'direction' => $direction])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.sort', $sort)
                ->where('filters.direction', $direction)
                ->where('items.total', 1));

        expect(purchaseOrdersListingSql(['sort' => $sort, 'direction' => $direction]))
            ->toMatch('/' . str_replace('{dir}', $direction, $orderBy) . ', "purchase_orders"\."id" asc limit 15/');
    })->with([
        // Data: critério secundário = criação (ordem padrão de sempre da tela).
        'data'   => ['order_date', 'order by "purchase_orders"\."order_date" {dir}, "purchase_orders"\."created_at" {dir}'],
        'código' => ['code', 'order by "purchase_orders"\."code" {dir}'],
        'total'  => ['total_amount', 'order by "purchase_orders"\."total_amount" {dir}'],
        // Subquery correlacionada (o escopo de tenant do Supplier entra no meio).
        'fornecedor' => ['supplier_name', 'order by \(select "suppliers"\."name" from "suppliers" where "suppliers"\."id" = "purchase_orders"\."supplier_id" .*\) {dir}'],
    ])->with(['asc', 'desc']);

    it('ordem padrão (sem parâmetros) também desempata por id', function () {
        // paginate() só executa o SELECT com LIMIT quando o COUNT é > 0.
        listingPurchaseOrder($this->alfa);

        expect(purchaseOrdersListingSql([]))
            ->toContain('order by "purchase_orders"."order_date" desc, "purchase_orders"."created_at" desc, "purchase_orders"."id" asc limit 15');
    });

    it('ordena por data do pedido crescente', function () {
        $late  = listingPurchaseOrder($this->alfa, ['order_date' => '2026-09-20']);
        $early = listingPurchaseOrder($this->beta, ['order_date' => '2026-07-05']);
        $mid   = listingPurchaseOrder($this->alfa, ['order_date' => '2026-08-15']);

        expect(purchaseOrdersListingColumn(['sort' => 'order_date', 'direction' => 'asc'], 'id'))
            ->toBe([$early->id, $mid->id, $late->id]);
    });

    it('[ISOLAMENTO] busca/ordenação nunca trazem pedido de outra clínica', function () {
        $mine    = listingPurchaseOrder($this->alfa);
        $foreign = Supplier::create(['entity_id' => $this->otherEntity->id, 'name' => 'Alfa Óptica', 'active' => true]);
        listingPurchaseOrder($foreign);

        expect(purchaseOrdersListingColumn(['search' => 'Alfa', 'sort' => 'supplier_name', 'direction' => 'asc'], 'id'))
            ->toBe([$mine->id]);

        // Filtrar pelo fornecedor de outra clínica não vaza nada.
        purchaseOrdersListingAs(['supplier_id' => $foreign->id])
            ->assertInertia(fn (Assert $page) => $page->where('items.total', 0));

        // UUID válido mas inexistente: lista vazia, sem erro.
        purchaseOrdersListingAs(['supplier_id' => (string) Str::uuid()])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('items.total', 0));
    });
});

describe('Pedidos de compra — filtro por fornecedor fora da lista de ativos', function () {
    it('fornecedor INATIVO da clínica: o seletor recebe o nome dele (selectedSupplier)', function () {
        $inactive = Supplier::create(['entity_id' => $this->entity->id, 'name' => 'Gama Antigo', 'active' => false]);
        $po       = listingPurchaseOrder($inactive);

        purchaseOrdersListingAs(['supplier_id' => $inactive->id])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.supplier_id', $inactive->id)
                ->where('selectedSupplier', ['id' => $inactive->id, 'name' => 'Gama Antigo'])
                ->where('items.total', 1)
                ->where('items.data.0.id', $po->id));
    });

    it('fornecedor ativo já está em `suppliers` — selectedSupplier fica null', function () {
        purchaseOrdersListingAs(['supplier_id' => $this->alfa->id])
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.supplier_id', $this->alfa->id)
                ->where('selectedSupplier', null));
    });

    it('[ISOLAMENTO] fornecedor de outra clínica, excluído ou inexistente não expõe nome', function () {
        $foreign = Supplier::create(['entity_id' => $this->otherEntity->id, 'name' => 'Segredo Alheio', 'active' => false]);
        $deleted = Supplier::create(['entity_id' => $this->entity->id, 'name' => 'Excluído', 'active' => false]);
        $deleted->delete();

        foreach ([$foreign->id, $deleted->id, (string) Str::uuid()] as $supplierId) {
            purchaseOrdersListingAs(['supplier_id' => $supplierId])
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('filters.supplier_id', $supplierId)
                    ->where('selectedSupplier', null)
                    ->where('items.total', 0));
        }
    });
});

describe('Pedidos de compra — ações voltam para a listagem como estava', function () {
    beforeEach(function () {
        $this->product = EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'Lente IOL', 'unit' => 'un', 'active' => true]);

        $this->listingQuery = '?search=PC&status=all&supplier_id=' . $this->alfa->id . '&sort=total_amount&direction=asc&page=2';
        $this->listingFrom  = route('panel.stock.purchase-orders.index') . $this->listingQuery . '&foo=bar&next=https://evil.example';
        $this->expected     = route('panel.stock.purchase-orders.index') . $this->listingQuery;
    });

    it('criar, editar, enviar, receber e cancelar preservam só busca/filtros/ordenação/página', function () {
        purchaseOrderActionAs($this->listingFrom)
            ->post(route('panel.stock.purchase-orders.store'), poListingPayload())
            ->assertRedirect($this->expected)
            ->assertSessionHas('message', __('stock.purchase_order_created'));

        $po = PurchaseOrder::query()->where('entity_id', $this->entity->id)->with('items')->latest('created_at')->firstOrFail();

        purchaseOrderActionAs($this->listingFrom)
            ->put(route('panel.stock.purchase-orders.update', $po->id), poListingPayload())
            ->assertRedirect($this->expected)
            ->assertSessionHas('message', __('stock.purchase_order_updated'));

        purchaseOrderActionAs($this->listingFrom)
            ->post(route('panel.stock.purchase-orders.send', $po->id))
            ->assertRedirect($this->expected)
            ->assertSessionHas('message', __('stock.purchase_order_sent'));

        $item = $po->fresh('items')->items->first();

        purchaseOrderActionAs($this->listingFrom)
            ->post(route('panel.stock.purchase-orders.receive', $po->id), [
                'items' => [['purchase_order_item_id' => $item->id, 'quantity' => 4]],
            ])
            ->assertRedirect($this->expected);

        expect($po->fresh()->status)->toBe(PurchaseOrderStatus::PartiallyReceived);

        purchaseOrderActionAs($this->listingFrom)
            ->post(route('panel.stock.purchase-orders.cancel', $po->id))
            ->assertRedirect($this->expected)
            ->assertSessionHas('message', __('stock.purchase_order_cancelled'));

        expect($po->fresh()->status)->toBe(PurchaseOrderStatus::Cancelled);
    });

    it('excluir rascunho preserva a listagem', function () {
        $po = listingPurchaseOrder($this->alfa);

        purchaseOrderActionAs($this->listingFrom)
            ->delete(route('panel.stock.purchase-orders.destroy', $po->id))
            ->assertRedirect($this->expected)
            ->assertSessionHas('message', __('stock.purchase_order_deleted'));

        expect(PurchaseOrder::query()->whereKey($po->id)->exists())->toBeFalse();
    });

    it('[SEGURANÇA] Referer de outro domínio não vira redirect externo e descarta parâmetros estranhos', function () {
        $po = listingPurchaseOrder($this->alfa);

        $response = purchaseOrderActionAs('https://evil.example/panel/stock/purchase-orders?search=x&next=https://evil.example')
            ->post(route('panel.stock.purchase-orders.cancel', $po->id))
            ->assertSessionHas('message', __('stock.purchase_order_cancelled'));

        expect($response->headers->get('Location'))
            ->toStartWith(route('panel.stock.purchase-orders.index'))
            ->not->toContain('evil.example')
            ->not->toContain('next=');
    });

    it('vindo de outra tela, volta para a listagem padrão', function () {
        $po = listingPurchaseOrder($this->alfa);

        purchaseOrderActionAs(route('panel.stock.suppliers.index') . '?search=PC')
            ->post(route('panel.stock.purchase-orders.cancel', $po->id))
            ->assertRedirect(route('panel.stock.purchase-orders.index'));
    });

    it('falha de transição continua voltando com erro (back), sem trocar de tela', function () {
        $po = listingPurchaseOrder($this->alfa, ['status' => PurchaseOrderStatus::Received]);

        purchaseOrderActionAs($this->listingFrom)
            ->post(route('panel.stock.purchase-orders.cancel', $po->id))
            ->assertRedirect($this->listingFrom)
            ->assertSessionHasErrors('status');
    });
});
