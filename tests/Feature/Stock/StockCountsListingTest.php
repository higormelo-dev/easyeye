<?php

declare(strict_types=1);

use App\Enums\{ClientRule, FeatureKey, SubscriptionStatus};
use App\Models\{Entity, EntityProduct, Plan, PlanFeature, ProductCategory, Subscription, User};
use App\Services\Stock\StockService;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Contagem de estoque igualada à listagem de pacientes — StockCountsController::index:
 * paginator (tabela/cards usam os mesmos dados), ordenação por whitelist,
 * busca, filtros normalizados, textos via `t`, desempate por id e isolamento.
 */
function countsListingEntity(): Entity
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

function countsListingProduct(Entity $entity, string $name, array $extra = []): EntityProduct
{
    return EntityProduct::create(array_merge(['entity_id' => $entity->id, 'name' => $name, 'unit' => 'un', 'active' => true], $extra));
}

function countsListingAs(array $query = [])
{
    return test()->actingAs(test()->admin)
        ->withSession(panelSession(test()->adminEntityUser))
        ->get(route('panel.stock.counts.index', $query));
}

/**
 * SQL da consulta paginada da contagem (a que tem LIMIT/OFFSET): o desempate
 * por id é verificado no ORDER BY emitido — a ordem de linhas empatadas no
 * PostgreSQL pode sair "estável por acaso" numa tabela pequena.
 */
function countsListingPageSql(array $query = []): string
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    countsListingAs($query)->assertOk();
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    return (string) collect($log)->pluck('query')->first(fn (string $sql) => str_contains($sql, 'from "entity_products"')
        && ! str_contains($sql, 'count(*)')
        && preg_match('/ limit \d+ offset \d+$/', $sql) === 1);
}

beforeEach(function () {
    $this->entity          = countsListingEntity();
    $this->admin           = User::factory()->create();
    $this->adminEntityUser = createEntityUser($this->entity, $this->admin, ClientRule::Admin->value);
});

it('devolve paginator ordenado por nome (padrão), filtros normalizados, `t` e atalho de movimentações', function () {
    $b = countsListingProduct($this->entity, 'B Soro', ['unit' => 'fr']);
    countsListingProduct($this->entity, 'A Álcool');
    app(StockService::class)->manualIn($b, 4, 1.0);

    countsListingAs(['sort' => 'drop;table', 'direction' => 'sideways', 'category_id' => 'abc'])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Panel/Stock/Counts/Index')
            ->has('products.data', 2)
            ->where('products.total', 2)
            ->where('products.data.0.name', 'A Álcool')
            ->where('products.data.1.name', 'B Soro')
            ->where('products.data.1.qty_on_hand', 4)
            ->where('products.data.1.unit', 'fr')
            // Rótulo da unidade traduzido no backend (lang/{locale}/stock_enums.php).
            ->where('products.data.1.unit_label', __('stock_enums.units.fr'))
            ->where('products.data.1.movements_url', route('panel.stock.movements.index', ['entity_product_id' => $b->id]))
            ->where('filters', [
                'search'      => '',
                'category_id' => '',
                'sort'        => 'name',
                'direction'   => 'asc',
            ])
            ->where('routes.movements_index', route('panel.stock.movements.index'))
            ->has('t.col_counted')
            ->has('t.columns_label')
            ->has('t.opens_new_tab')
            ->missing('t.unit_un')
            ->has('categories'));
});

it('parâmetros em formato de array não quebram a tela (voltam ao padrão)', function () {
    countsListingAs(['sort' => ['x'], 'direction' => ['y'], 'search' => ['z'], 'category_id' => ['w'], 'page' => ['2']])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.sort', 'name')
            ->where('filters.direction', 'asc')
            ->where('filters.search', '')
            ->where('filters.category_id', ''));
});

it('ordena por saldo e por categoria (whitelist)', function () {
    $catB = ProductCategory::create(['entity_id' => $this->entity->id, 'name' => 'Z Colírios', 'active' => true]);
    $catA = ProductCategory::create(['entity_id' => $this->entity->id, 'name' => 'A Lentes', 'active' => true]);
    $p1   = countsListingProduct($this->entity, 'Produto 1', ['product_category_id' => $catB->id]);
    $p2   = countsListingProduct($this->entity, 'Produto 2', ['product_category_id' => $catA->id]);
    app(StockService::class)->manualIn($p1, 10, 1.0);
    app(StockService::class)->manualIn($p2, 3, 1.0);

    countsListingAs(['sort' => 'qty_on_hand', 'direction' => 'desc'])
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.sort', 'qty_on_hand')
            ->where('filters.direction', 'desc')
            ->where('products.data.0.name', 'Produto 1')
            ->where('products.data.1.name', 'Produto 2'));

    countsListingAs(['sort' => 'category', 'direction' => 'asc'])
        ->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.category_name', 'A Lentes')
            ->where('products.data.1.category_name', 'Z Colírios'));
});

it('busca por nome (sem acento), código e código de barras, mantendo o filtro de categoria', function () {
    $cat = ProductCategory::create(['entity_id' => $this->entity->id, 'name' => 'Colírios', 'active' => true]);
    countsListingProduct($this->entity, 'Colírio Diclofenaco', ['product_category_id' => $cat->id]);
    $barcoded = countsListingProduct($this->entity, 'Lente Tórica', ['barcode' => '7891234567890']);
    countsListingProduct($this->entity, 'Colírio Sem Categoria');

    countsListingAs(['search' => 'colirio', 'category_id' => $cat->id])
        ->assertInertia(fn (Assert $page) => $page
            ->has('products.data', 1)
            ->where('products.data.0.name', 'Colírio Diclofenaco')
            ->where('filters.category_id', $cat->id));

    countsListingAs(['search' => '7891234567890'])
        ->assertInertia(fn (Assert $page) => $page->has('products.data', 1)->where('products.data.0.id', $barcoded->id));

    countsListingAs(['search' => $barcoded->code])
        ->assertInertia(fn (Assert $page) => $page->has('products.data', 1)->where('products.data.0.id', $barcoded->id));
});

it('[ISOLAMENTO] não lista produtos de outra clínica', function () {
    countsListingProduct($this->entity, 'Isolado Meu');
    countsListingProduct(countsListingEntity(), 'Isolado Alheio');

    countsListingAs(['search' => 'Isolado'])
        ->assertInertia(fn (Assert $page) => $page
            ->has('products.data', 1)
            ->where('products.total', 1)
            ->where('products.data.0.name', 'Isolado Meu'));
});

it('pagina sem repetir nem pular produtos com o mesmo nome (desempate por id)', function () {
    foreach (range(1, 55) as $i) {
        countsListingProduct($this->entity, 'Mesmo Nome');
    }

    $ids = collect([1, 2])->flatMap(function (int $page) {
        $data = [];
        countsListingAs(['page' => $page])->assertInertia(function (Assert $inertia) use (&$data) {
            $data = $inertia->toArray()['props']['products']['data'];
        });

        return collect($data)->pluck('id');
    });

    expect($ids)->toHaveCount(55)
        ->and($ids->unique())->toHaveCount(55);

    // O id é SEMPRE o último critério do ORDER BY, em qualquer ordenação.
    foreach (['name', 'code', 'category', 'qty_on_hand'] as $sort) {
        foreach (['asc', 'desc'] as $direction) {
            expect(countsListingPageSql(['sort' => $sort, 'direction' => $direction]))
                ->toMatch('/order by .+, "entity_products"\."id" asc limit \d+ offset \d+$/');
        }
    }
});
