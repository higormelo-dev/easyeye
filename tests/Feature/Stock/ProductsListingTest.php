<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Models\{Entity, EntityProduct, ProductCategory, StockLot, User};
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Listagem de produtos de estoque igualada à de pacientes
 * (App\Http\Controllers\Stock\ProductsController::index): ordenação por
 * whitelist com desempate por id, filtros normalizados, textos via `t` e
 * isolamento por clínica. Tabela e cards consomem o MESMO paginator `items`.
 */
beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    giveInventoryModuleAccess($this->entity);

    $this->admin           = User::factory()->create();
    $this->adminEntityUser = createEntityUser($this->entity, $this->admin, ClientRule::Admin->value);
});

/** Produto da clínica; saldo/custo (fora do $fillable) gravados direto só para montar o cenário. */
function listingProduct(Entity $entity, array $attributes = [], array $balances = []): EntityProduct
{
    $product = EntityProduct::create(array_merge([
        'entity_id' => $entity->id,
        'name'      => 'Produto',
        'unit'      => 'un',
        'active'    => true,
    ], $attributes));

    if ($balances !== []) {
        $product->forceFill($balances)->saveQuietly();
    }

    return $product;
}

function productsListingAs(array $query = [], ?User $user = null, $entityUser = null)
{
    return test()->actingAs($user ?? test()->admin)
        ->withSession(panelSession($entityUser ?? test()->adminEntityUser))
        ->get(route('panel.stock.products.index', $query));
}

/** @return array<int, array<string, mixed>> linhas de `items.data` da resposta Inertia */
function productRows($response): array
{
    $rows = [];
    $response->assertInertia(function (Assert $page) use (&$rows) {
        $rows = $page->toArray()['props']['items']['data'];
    });

    return $rows;
}

describe('Produtos — ordenação e filtros normalizados', function () {
    it('sem parâmetros mantém a ordem atual (nome ascendente) e devolve textos e filtros normalizados', function () {
        listingProduct($this->entity, ['name' => 'Colírio B']);
        listingProduct($this->entity, ['name' => 'Colírio A']);

        $response = productsListingAs(['search' => 'Colírio'])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Panel/Stock/Products/Index')
                ->where('filters.search', 'Colírio')
                ->where('filters.sort', 'name')
                ->where('filters.direction', 'asc')
                ->where('filters.status', 'all')
                ->where('filters.category_id', '')
                ->where('filters.low_stock', false)
                ->where('filters.expiring_lots', false)
                ->where('t.page_title', 'Produtos')
                ->has('t.col_qty_on_hand')
                ->has('t.columns_label')
                ->has('t.empty_list')
                ->has('t.search_clear')
                ->has('t.toggle_error')
                ->has('routes.movements_index')
                ->has('routes.show')
                ->has('routes.import_index'));

        expect(array_column(productRows($response), 'name'))->toBe(['Colírio A', 'Colírio B']);
    });

    it('ordena pelas colunas da whitelist (saldo descendente)', function () {
        listingProduct($this->entity, ['name' => 'Saldo Baixo'], ['qty_on_hand' => 2]);
        listingProduct($this->entity, ['name' => 'Saldo Alto'], ['qty_on_hand' => 50]);
        listingProduct($this->entity, ['name' => 'Saldo Medio'], ['qty_on_hand' => 10]);

        $response = productsListingAs(['search' => 'Saldo', 'sort' => 'qty_on_hand', 'direction' => 'desc'])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.sort', 'qty_on_hand')
                ->where('filters.direction', 'desc'));

        expect(array_column(productRows($response), 'name'))->toBe(['Saldo Alto', 'Saldo Medio', 'Saldo Baixo']);
    });

    it('ordena por preço de venda e por custo médio', function () {
        listingProduct($this->entity, ['name' => 'Preco Caro', 'sale_price' => 90], ['cost_avg' => 10]);
        listingProduct($this->entity, ['name' => 'Preco Barato', 'sale_price' => 5], ['cost_avg' => 40]);

        expect(array_column(productRows(productsListingAs(['search' => 'Preco', 'sort' => 'sale_price', 'direction' => 'asc'])), 'name'))
            ->toBe(['Preco Barato', 'Preco Caro'])
            ->and(array_column(productRows(productsListingAs(['search' => 'Preco', 'sort' => 'cost_avg', 'direction' => 'asc'])), 'name'))
            ->toBe(['Preco Caro', 'Preco Barato']);
    });

    it('ordenação/direção/status fora da whitelist caem no padrão (sem SQL injection) e são normalizados', function () {
        listingProduct($this->entity, ['name' => 'Seguro B']);
        listingProduct($this->entity, ['name' => 'Seguro A']);

        $response = productsListingAs([
            'search'    => 'Seguro',
            'sort'      => 'name;drop table entity_products',
            'direction' => 'sideways',
            'status'    => 'hacked',
        ])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.sort', 'name')
                ->where('filters.direction', 'asc')
                ->where('filters.status', 'all'));

        expect(array_column(productRows($response), 'name'))->toBe(['Seguro A', 'Seguro B']);
    });

    it('parâmetros em formato de array ou categoria inválida não derrubam a página (normalizados para vazio)', function () {
        listingProduct($this->entity, ['name' => 'Robusto']);

        productsListingAs([
            'search'      => ['x'],
            'sort'        => ['name'],
            'direction'   => ['asc'],
            'status'      => ['active'],
            'category_id' => 'nao-e-uuid',
        ])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.search', '')
                ->where('filters.sort', 'name')
                ->where('filters.direction', 'asc')
                ->where('filters.status', 'all')
                ->where('filters.category_id', '')
                ->has('items.data', 1));

        // Demais parâmetros da query também em formato de array (?x[]=1).
        productsListingAs([
            'category_id'   => ['x'],
            'low_stock'     => ['1'],
            'expiring_lots' => ['1'],
            'page'          => ['2'],
        ])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.category_id', '')
                ->where('filters.low_stock', false)
                ->where('filters.expiring_lots', false)
                ->where('items.current_page', 1)
                ->has('items.data', 1));
    });

    it('preserva os filtros existentes (status, categoria, abaixo do mínimo) junto com a ordenação', function () {
        $category = ProductCategory::create(['entity_id' => $this->entity->id, 'name' => 'Colírios', 'active' => true]);

        listingProduct($this->entity, ['name' => 'Filtro Alvo B', 'product_category_id' => $category->id, 'min_qty' => 5], ['qty_on_hand' => 1]);
        listingProduct($this->entity, ['name' => 'Filtro Alvo A', 'product_category_id' => $category->id, 'min_qty' => 5], ['qty_on_hand' => 3]);
        listingProduct($this->entity, ['name' => 'Filtro Sem Categoria', 'min_qty' => 5], ['qty_on_hand' => 1]);
        listingProduct($this->entity, ['name' => 'Filtro Inativo', 'product_category_id' => $category->id, 'min_qty' => 5, 'active' => false], ['qty_on_hand' => 1]);
        listingProduct($this->entity, ['name' => 'Filtro Com Saldo', 'product_category_id' => $category->id, 'min_qty' => 5], ['qty_on_hand' => 99]);

        $response = productsListingAs([
            'search'      => 'Filtro',
            'status'      => 'active',
            'category_id' => $category->id,
            'low_stock'   => 1,
            'sort'        => 'name',
            'direction'   => 'desc',
        ])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.status', 'active')
                ->where('filters.category_id', $category->id)
                ->where('filters.low_stock', true));

        expect(array_column(productRows($response), 'name'))->toBe(['Filtro Alvo B', 'Filtro Alvo A']);
    });

    it('pagina sem repetir nem pular produtos com o mesmo valor ordenado (desempate por id)', function () {
        foreach (range(1, 20) as $i) {
            listingProduct($this->entity, ['name' => 'Mesmo Nome'], ['qty_on_hand' => 7]);
        }

        DB::enableQueryLog();
        $ids = collect([1, 2])->flatMap(fn (int $page) => array_column(
            productRows(productsListingAs(['search' => 'Mesmo Nome', 'sort' => 'qty_on_hand', 'direction' => 'asc', 'page' => $page])),
            'id',
        ));
        $listingSql = collect(DB::getQueryLog())->pluck('query')
            ->filter(fn (string $sql) => str_contains($sql, 'from "entity_products"') && str_contains($sql, 'offset'));
        DB::disableQueryLog();

        expect($ids)->toHaveCount(20)
            ->and($ids->unique())->toHaveCount(20)
            ->and($ids->all())->toBe(
                EntityProduct::query()->where('entity_id', $this->entity->id)->orderBy('id')->pluck('id')->all(),
            );

        // Em dataset pequeno o PostgreSQL já devolve na ordem de inserção
        // (UUIDv7), então só o resultado não prova o desempate — a query da
        // listagem precisa terminar com o id como critério final.
        expect($listingSql)->toHaveCount(2)
            ->each->toMatch('/order by .+, "entity_products"\."id" asc limit/');
    });

    it('ordenação descendente por preço deixa produtos sem preço no fim (NULLS LAST)', function () {
        listingProduct($this->entity, ['name' => 'Preco Nulo']);
        listingProduct($this->entity, ['name' => 'Preco Baixo', 'sale_price' => 5]);
        listingProduct($this->entity, ['name' => 'Preco Alto', 'sale_price' => 90]);

        $desc = productsListingAs(['search' => 'Preco', 'sort' => 'sale_price', 'direction' => 'desc'])->assertOk();
        $asc  = productsListingAs(['search' => 'Preco', 'sort' => 'sale_price', 'direction' => 'asc'])->assertOk();

        expect(array_column(productRows($desc), 'name'))->toBe(['Preco Alto', 'Preco Baixo', 'Preco Nulo'])
            ->and(array_column(productRows($asc), 'name'))->toBe(['Preco Baixo', 'Preco Alto', 'Preco Nulo']);
    });
});

describe('Produtos — isolamento e ações da listagem', function () {
    it('não lista produtos de outra clínica, mesmo ordenando', function () {
        listingProduct($this->entity, ['name' => 'Isolado Daqui'], ['qty_on_hand' => 1]);

        $other = Entity::factory()->create(['is_client' => true, 'active' => true]);
        listingProduct($other, ['name' => 'Isolado Outra'], ['qty_on_hand' => 999]);

        $response = productsListingAs(['search' => 'Isolado', 'sort' => 'qty_on_hand', 'direction' => 'desc'])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('items.total', 1));

        expect(array_column(productRows($response), 'name'))->toBe(['Isolado Daqui']);
    });

    it('ativar/desativar pela listagem (nome + unidade + active) não apaga os demais campos do produto', function () {
        $category = ProductCategory::create(['entity_id' => $this->entity->id, 'name' => 'Insumos', 'active' => true]);
        $product  = listingProduct($this->entity, [
            'name'                => 'Toggle',
            'product_category_id' => $category->id,
            'sku'                 => 'SKU-1',
            'barcode'             => '789',
            'sale_price'          => 12.5,
            'min_qty'             => 3,
            'max_qty'             => 9,
        ]);

        $this->actingAs($this->admin)
            ->withSession(panelSession($this->adminEntityUser))
            ->put(route('panel.stock.products.update', $product->id), ['name' => 'Toggle', 'unit' => 'un', 'active' => false])
            ->assertRedirect(route('panel.stock.products.index'));

        $product->refresh();
        expect($product->active)->toBeFalse()
            ->and($product->product_category_id)->toBe($category->id)
            ->and($product->sku)->toBe('SKU-1')
            ->and($product->barcode)->toBe('789')
            ->and((float) $product->sale_price)->toBe(12.5)
            ->and((float) $product->min_qty)->toBe(3.0)
            ->and((float) $product->max_qty)->toBe(9.0);
    });

    it('o GET que o ativar/desativar usa devolve nome e unidade atuais e bloqueia produto de outra clínica', function () {
        $product = listingProduct($this->entity, ['name' => 'Antes', 'unit' => 'un']);
        $product->update(['name' => 'Depois', 'unit' => 'fr']);

        $this->actingAs($this->admin)
            ->withSession(panelSession($this->adminEntityUser))
            ->getJson(route('panel.stock.products.show', $product->id))
            ->assertOk()
            ->assertJsonPath('data.name', 'Depois')
            ->assertJsonPath('data.unit', 'fr')
            ->assertJsonPath('data.active', true);

        $other   = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $foreign = listingProduct($other, ['name' => 'De Outra']);

        $this->actingAs($this->admin)
            ->withSession(panelSession($this->adminEntityUser))
            ->getJson(route('panel.stock.products.show', $foreign->id))
            ->assertNotFound();
    });
});

describe('Produtos — lote vencendo', function () {
    it('expõe nearest_expiry em ISO (a tela formata) ignorando lotes sem validade', function () {
        $product = listingProduct($this->entity, ['name' => 'Com Lote']);

        foreach ([['L-SEM', null], ['L-VENCE', now()->addDays(10)->toDateString()], ['L-DEPOIS', now()->addDays(90)->toDateString()]] as [$number, $expiry]) {
            StockLot::create([
                'entity_id'         => $this->entity->id,
                'entity_product_id' => $product->id,
                'lot_number'        => $number,
                'expiry_date'       => $expiry,
                'active'            => true,
            ])->forceFill(['qty_on_hand' => 5])->saveQuietly();
        }

        $row = productRows(productsListingAs(['search' => 'Com Lote']))[0];

        expect($row['nearest_expiry'])->toBe(now()->addDays(10)->toDateString())
            ->and($row['has_expiring_lot'])->toBeTrue();
    });
});

describe('Produtos — volta para a listagem após criar/editar/excluir', function () {
    it('editar volta para a listagem com busca, filtros, ordenação e página — só os parâmetros permitidos', function () {
        $category = ProductCategory::create(['entity_id' => $this->entity->id, 'name' => 'Colírios', 'active' => true]);
        $product  = listingProduct($this->entity, ['name' => 'Volta']);
        $index    = route('panel.stock.products.index');
        $kept     = [
            'search'        => 'Volta',
            'status'        => 'active',
            'category_id'   => $category->id,
            'low_stock'     => '1',
            'expiring_lots' => '1',
            'sort'          => 'qty_on_hand',
            'direction'     => 'desc',
            'page'          => '2',
        ];
        $listing = $index . '?' . http_build_query($kept + ['entity_id' => 'outra', 'next' => 'https://evil.example.com']);

        $this->actingAs($this->admin)
            ->withSession(panelSession($this->adminEntityUser))
            ->from($listing)
            ->put(route('panel.stock.products.update', $product->id), ['name' => 'Volta', 'unit' => 'un', 'active' => false])
            ->assertRedirect($index . '?' . http_build_query($kept))
            ->assertSessionHas('message', __('stock.product_updated'));
    });

    it('criar e excluir também voltam para a listagem filtrada', function () {
        $index   = route('panel.stock.products.index');
        $listing = $index . '?' . http_build_query(['search' => 'Novo', 'page' => '3']);

        $this->actingAs($this->admin)
            ->withSession(panelSession($this->adminEntityUser))
            ->from($listing)
            ->post(route('panel.stock.products.store'), ['name' => 'Novo Produto', 'unit' => 'un'])
            ->assertRedirect($listing)
            ->assertSessionHas('message', __('stock.product_created'));

        $product = EntityProduct::query()->where('entity_id', $this->entity->id)->where('name', 'Novo Produto')->firstOrFail();

        $this->actingAs($this->admin)
            ->withSession(panelSession($this->adminEntityUser))
            ->from($listing)
            ->delete(route('panel.stock.products.destroy', $product->id))
            ->assertRedirect($listing)
            ->assertSessionHas('message', __('stock.product_deleted'));
    });

    it('excluir o único produto da última página volta para a última página válida, não para uma página vazia', function () {
        foreach (range(1, 16) as $i) {
            listingProduct($this->entity, ['name' => sprintf('Pagina %02d', $i)]);
        }
        $last  = EntityProduct::query()->where('entity_id', $this->entity->id)->where('name', 'Pagina 16')->firstOrFail();
        $index = route('panel.stock.products.index');

        // Página 2 válida não é ajustada.
        expect(array_column(productRows(productsListingAs(['page' => 2])), 'name'))->toBe(['Pagina 16']);

        $this->actingAs($this->admin)
            ->withSession(panelSession($this->adminEntityUser))
            ->from($index . '?page=2')
            ->delete(route('panel.stock.products.destroy', $last->id))
            ->assertRedirect($index . '?page=2');

        $response = productsListingAs(['page' => 2])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('items.current_page', 1)
                ->where('items.last_page', 1)
                ->where('items.total', 15));

        expect(productRows($response))->toHaveCount(15);

        // Página muito além da última (ou filtro sem resultado) também não fica "vazia com total".
        productsListingAs(['page' => 99])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('items.current_page', 1));
        productsListingAs(['search' => 'Inexistente', 'page' => 3])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('items.current_page', 1)
                ->where('items.total', 0));
    });

    it('descarta parâmetros em formato de array vindos do Referer', function () {
        $product = listingProduct($this->entity, ['name' => 'Array']);
        $index   = route('panel.stock.products.index');

        $this->actingAs($this->admin)
            ->withSession(panelSession($this->adminEntityUser))
            ->from($index . '?search[]=x&status[]=active&page=4')
            ->put(route('panel.stock.products.update', $product->id), ['name' => 'Array', 'unit' => 'un'])
            ->assertRedirect($index . '?page=4');
    });

    it('Referer de outra página ou de outro host não vira redirect para fora da listagem', function () {
        $product = listingProduct($this->entity, ['name' => 'Seguro']);
        $index   = route('panel.stock.products.index');

        $update = fn (string $referer) => $this->actingAs($this->admin)
            ->withSession(panelSession($this->adminEntityUser))
            ->from($referer)
            ->put(route('panel.stock.products.update', $product->id), ['name' => 'Seguro', 'unit' => 'un']);

        // Outra tela do painel (com query) → listagem limpa.
        $update(route('panel.stock.movements.index', ['search' => 'x']))->assertRedirect($index);
        // Host externo com outro caminho → listagem limpa.
        $update('https://evil.example.com/phishing?search=x')->assertRedirect($index);
        // Host externo com o MESMO caminho → URL reconstruída na nossa rota (nunca o host do Referer).
        $update('https://evil.example.com' . parse_url($index, PHP_URL_PATH) . '?search=x')
            ->assertRedirect($index . '?search=x');
    });
});

describe('Produtos — endpoints JSON com parâmetro em array', function () {
    it('busca e leitor de código de barras não estouram (500) com ?q[]= / ?barcode[]=', function () {
        listingProduct($this->entity, ['name' => 'Colírio Json', 'barcode' => '7890000000001']);

        $json = fn (string $url) => $this->actingAs($this->admin)
            ->withSession(panelSession($this->adminEntityUser))
            ->getJson($url);

        $json(route('panel.stock.products.search', ['q' => ['Colírio']]))
            ->assertOk()
            ->assertExactJson(['data' => []]);
        $json(route('panel.stock.products.scan-barcode', ['barcode' => ['7890000000001']]))
            ->assertStatus(422)
            ->assertJsonPath('message', __('stock.barcode_required'));

        // Texto continua funcionando como antes.
        $json(route('panel.stock.products.search', ['q' => ' Colírio ']))
            ->assertOk()
            ->assertJsonPath('data.0.product_name', 'Colírio Json');
        $json(route('panel.stock.products.scan-barcode', ['barcode' => '7890000000001']))
            ->assertOk()
            ->assertJsonPath('data.name', 'Colírio Json');
    });
});
