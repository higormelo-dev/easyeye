<?php

declare(strict_types=1);

use App\Enums\{ClientRule, FeatureKey, SubscriptionStatus};
use App\Models\{Entity, EntityProduct, Plan, PlanFeature, StockMovement, Subscription, User};
use App\Services\Stock\StockService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Listagem de movimentação igualada à de pacientes — StockMovementsController::index:
 * ordenação por whitelist (inválida volta ao padrão), filtros normalizados,
 * busca, textos via `t`, desempate estável por id e isolamento por clínica.
 */
function movementsListingEntity(): Entity
{
    $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $plan   = Plan::factory()->create(['active' => true]);
    PlanFeature::factory()->enabled(FeatureKey::HasInventoryModule)->for($plan)->create();
    Subscription::factory()->create([
        'entity_id' => $entity->id, 'plan_id' => $plan->id, 'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
    ]);

    return $entity;
}

function movementsListingProduct(Entity $entity, string $name, array $extra = []): EntityProduct
{
    return EntityProduct::create(array_merge(['entity_id' => $entity->id, 'name' => $name, 'unit' => 'un', 'active' => true], $extra));
}

function movementsListingAs(array $query = [])
{
    return test()->actingAs(test()->admin)
        ->withSession(panelSession(test()->adminEntityUser))
        ->get(route('panel.stock.movements.index', $query));
}

/** @return array<string, mixed> props da página Inertia */
function movementsListingProps(array $query = []): array
{
    $props = [];
    movementsListingAs($query)->assertOk()->assertInertia(function (Assert $page) use (&$props) {
        $props = $page->toArray()['props'];
    });

    return $props;
}

/**
 * SQL da consulta paginada da listagem (a que tem LIMIT/OFFSET): a ordem de
 * linhas empatadas no PostgreSQL é "estável por acaso" em tabela pequena, então
 * o desempate por id é verificado no ORDER BY emitido, não só no resultado.
 */
function movementsListingPageSql(array $query = []): string
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    movementsListingAs($query)->assertOk();
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    return (string) collect($log)->pluck('query')->first(fn (string $sql) => str_contains($sql, 'from "stock_movements"')
        && ! str_contains($sql, 'count(*)')
        && preg_match('/ limit \d+ offset \d+$/', $sql) === 1);
}

beforeEach(function () {
    $this->entity          = movementsListingEntity();
    $this->admin           = User::factory()->create();
    $this->adminEntityUser = createEntityUser($this->entity, $this->admin, ClientRule::Admin->value);
    $this->stock           = app(StockService::class);
});

it('mantém o padrão (data desc), normaliza sort/direction inválidos e envia os textos `t`', function () {
    $product = movementsListingProduct($this->entity, 'Colírio Padrão');
    $this->stock->manualIn($product, 1, 2.0, occurredAt: Carbon::parse('2026-09-01 10:00'));
    $this->stock->manualIn($product, 2, 2.0, occurredAt: Carbon::parse('2026-09-03 10:00'));
    $this->stock->manualIn($product, 3, 2.0, occurredAt: Carbon::parse('2026-09-02 10:00'));

    movementsListingAs(['sort' => 'drop;table', 'direction' => 'sideways', 'type' => 'nao_existe', 'entity_product_id' => 'abc'])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Panel/Stock/Movements/Index')
            ->has('items.data', 3)
            ->where('items.data.0.quantity', 2)
            ->where('items.data.1.quantity', 3)
            ->where('items.data.2.quantity', 1)
            ->where('items.data.0.occurred_at_iso', '2026-09-03T10:00:00')
            // Rótulos dos tipos traduzidos no backend (lang/{locale}/stock_enums.php).
            ->where('items.data.0.type_label', __('stock_enums.movement_types.manual_in'))
            ->where('filterTypes.0.label', __('stock_enums.movement_types.purchase_in'))
            ->where('filteredProduct', null)
            ->where('filters', [
                'search'            => '',
                'entity_product_id' => '',
                'type'              => '',
                'sort'              => 'occurred_at',
                'direction'         => 'desc',
            ])
            ->has('t.col_product')
            ->has('t.columns_label')
            ->missing('t.type_purchase_in')
            ->has('filterTypes', 8)
            ->has('movementTypes', 6));
});

it('parâmetros em formato de array não quebram a tela (voltam ao padrão)', function () {
    movementsListingAs(['sort' => ['x'], 'direction' => ['y'], 'search' => ['z'], 'type' => ['w'], 'entity_product_id' => ['v'], 'page' => ['2']])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.sort', 'occurred_at')
            ->where('filters.direction', 'desc')
            ->where('filters.search', '')
            ->where('filters.type', '')
            ->where('filters.entity_product_id', '')
            ->where('filteredProduct', null));
});

it('filtrando por produto INATIVO (atalho de Produtos) envia o nome dele para o select; de outra clínica, não', function () {
    $inactive = movementsListingProduct($this->entity, 'Colírio Descontinuado', ['active' => false]);
    $this->stock->manualIn($inactive, 2, 1.0);

    movementsListingAs(['entity_product_id' => $inactive->id])
        ->assertInertia(fn (Assert $page) => $page
            ->has('items.data', 1)
            ->where('filteredProduct', ['id' => $inactive->id, 'name' => 'Colírio Descontinuado'])
            ->where('products', fn ($products) => collect($products)->doesntContain('id', $inactive->id)));

    $other        = movementsListingEntity();
    $otherProduct = movementsListingProduct($other, 'Produto Alheio', ['active' => false]);

    movementsListingAs(['entity_product_id' => $otherProduct->id])
        ->assertInertia(fn (Assert $page) => $page
            ->has('items.data', 0)
            ->where('filteredProduct', null));
});

it('ordena pelas colunas da whitelist (quantidade e produto)', function () {
    $b = movementsListingProduct($this->entity, 'B Soro');
    $a = movementsListingProduct($this->entity, 'A Álcool');
    $this->stock->manualIn($b, 5, 1.0);
    $this->stock->manualIn($a, 9, 1.0);
    $this->stock->manualIn($b, 1, 1.0);

    movementsListingAs(['sort' => 'quantity', 'direction' => 'asc'])
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.sort', 'quantity')
            ->where('filters.direction', 'asc')
            ->where('items.data.0.quantity', 1)
            ->where('items.data.1.quantity', 5)
            ->where('items.data.2.quantity', 9));

    movementsListingAs(['sort' => 'product', 'direction' => 'asc'])
        ->assertInertia(fn (Assert $page) => $page
            ->where('items.data.0.product_name', 'A Álcool')
            ->where('items.data.1.product_name', 'B Soro')
            ->where('items.data.2.product_name', 'B Soro'));
});

it('busca por produto (sem acento), lote e observação, preservando os demais filtros', function () {
    $colirio = movementsListingProduct($this->entity, 'Colírio Diclofenaco');
    $soro    = movementsListingProduct($this->entity, 'Soro Fisiológico', ['requires_lot' => true]);
    $lot     = $this->stock->findOrCreateLot($soro, 'LOTE-XYZ-9');
    $this->stock->manualIn($colirio, 1, 1.0);
    $this->stock->manualIn($soro, 2, 1.0, lot: $lot);
    $this->stock->manualIn($colirio, 3, 1.0, note: 'doação da farmácia');

    movementsListingAs(['search' => 'colirio'])
        ->assertInertia(fn (Assert $page) => $page->has('items.data', 2)->where('filters.search', 'colirio'));

    movementsListingAs(['search' => 'xyz-9'])
        ->assertInertia(fn (Assert $page) => $page->has('items.data', 1)->where('items.data.0.lot_number', 'LOTE-XYZ-9'));

    movementsListingAs(['search' => 'farmacia', 'type' => 'manual_in', 'sort' => 'quantity', 'direction' => 'asc'])
        ->assertInertia(fn (Assert $page) => $page
            ->has('items.data', 1)
            ->where('items.data.0.note', 'doação da farmácia')
            ->where('filters.type', 'manual_in')
            ->where('filters.sort', 'quantity'));
});

it('[ISOLAMENTO] não lista movimentações de outra clínica, nem filtrando pelo produto alheio', function () {
    $mine = movementsListingProduct($this->entity, 'Isolado Meu');
    $this->stock->manualIn($mine, 1, 1.0);

    $other        = movementsListingEntity();
    $otherProduct = movementsListingProduct($other, 'Isolado Alheio');
    $this->stock->manualIn($otherProduct, 7, 1.0);

    movementsListingAs(['search' => 'Isolado'])
        ->assertInertia(fn (Assert $page) => $page
            ->has('items.data', 1)
            ->where('items.data.0.product_name', 'Isolado Meu'));

    movementsListingAs(['entity_product_id' => $otherProduct->id])
        ->assertInertia(fn (Assert $page) => $page->has('items.data', 0));
});

it('pagina sem repetir nem pular movimentações com os mesmos valores de ordenação (desempate por id)', function () {
    $product = movementsListingProduct($this->entity, 'Empate');
    $at      = Carbon::parse('2026-09-10 08:00');

    foreach (range(1, 25) as $i) {
        $this->stock->manualIn($product, 1, 1.0, occurredAt: $at);
    }
    // Mesmo instante de lançamento: só o id diferencia as linhas.
    DB::table('stock_movements')->where('entity_product_id', $product->id)->update(['created_at' => $at]);

    foreach ([['sort' => 'quantity', 'direction' => 'asc'], ['sort' => 'occurred_at', 'direction' => 'desc']] as $sort) {
        $ids = collect([1, 2])->flatMap(fn (int $page) => collect(movementsListingProps([...$sort, 'page' => $page])['items']['data'])->pluck('id'));

        expect($ids)->toHaveCount(25)
            ->and($ids->unique())->toHaveCount(25);
    }

    expect(StockMovement::query()->where('entity_product_id', $product->id)->count())->toBe(25);

    // O id é SEMPRE o último critério do ORDER BY, em qualquer ordenação.
    foreach (['occurred_at', 'product', 'quantity', 'unit_cost', 'balance_after'] as $sort) {
        foreach (['asc', 'desc'] as $direction) {
            expect(movementsListingPageSql(['sort' => $sort, 'direction' => $direction]))
                ->toMatch('/order by .+, "stock_movements"\."id" (asc|desc) limit \d+ offset \d+$/');
        }
    }
});
