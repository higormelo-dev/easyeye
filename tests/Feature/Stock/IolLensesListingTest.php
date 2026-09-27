<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Models\{Entity, EntityIolLens, User};
use App\Services\IolLensStockBridgeService;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Listagem de lentes IOL igualada à de pacientes
 * (App\Http\Controllers\Stock\IolLensesController::index): ordenação por
 * whitelist (colunas da lente e do produto vinculado) com desempate por id,
 * filtros normalizados, textos via `t` e isolamento por clínica. Tabela e
 * cards consomem o MESMO paginator `items`.
 */
beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    giveInventoryModuleAccess($this->entity);

    $this->admin           = User::factory()->create();
    $this->adminEntityUser = createEntityUser($this->entity, $this->admin, ClientRule::Admin->value);
});

function listingIolLens(Entity $entity, array $data = []): EntityIolLens
{
    return app(IolLensStockBridgeService::class)->create($entity->id, array_merge([
        'manufacturer' => 'Alcon',
        'model_name'   => 'Modelo',
        'category'     => 'Monofocal',
        'diopter_min'  => 10,
        'diopter_max'  => 30,
        'price'        => 1000,
        'active'       => true,
    ], $data));
}

function iolLensesListingAs(array $query = [], ?User $user = null, $entityUser = null)
{
    return test()->actingAs($user ?? test()->admin)
        ->withSession(panelSession($entityUser ?? test()->adminEntityUser))
        ->get(route('panel.stock.iollenses.index', $query));
}

/** @return array<int, array<string, mixed>> linhas de `items.data` da resposta Inertia */
function iolLensRows($response): array
{
    $rows = [];
    $response->assertInertia(function (Assert $page) use (&$rows) {
        $rows = $page->toArray()['props']['items']['data'];
    });

    return $rows;
}

function iolLensLabels(array $rows): array
{
    return array_map(fn (array $row) => "{$row['manufacturer']} {$row['model_name']}", $rows);
}

describe('Lentes IOL — ordenação e filtros normalizados', function () {
    it('sem parâmetros mantém a ordem atual (fabricante, depois modelo) e devolve textos e filtros normalizados', function () {
        listingIolLens($this->entity, ['manufacturer' => 'Zeiss', 'model_name' => 'A1']);
        listingIolLens($this->entity, ['manufacturer' => 'Alcon', 'model_name' => 'Z9']);
        listingIolLens($this->entity, ['manufacturer' => 'Alcon', 'model_name' => 'B2']);

        $response = iolLensesListingAs()
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Panel/Stock/IolLenses/Index')
                ->where('filters.search', '')
                ->where('filters.status', 'all')
                ->where('filters.sort', 'manufacturer')
                ->where('filters.direction', 'asc')
                ->where('t.page_title', 'Lentes de catarata')
                ->has('t.col_diopters')
                ->has('t.columns_label')
                ->has('t.empty_list')
                ->has('t.search_clear')
                ->has('t.toggle_error')
                ->has('routes.movements_index')
                ->has('routes.show')
                ->has('routes.update')
                ->has('routes.destroy'));

        expect(iolLensLabels(iolLensRows($response)))->toBe(['Alcon B2', 'Alcon Z9', 'Zeiss A1']);
    });

    it('ordena por colunas do produto vinculado (valor) e da própria lente (dioptria mínima)', function () {
        listingIolLens($this->entity, ['model_name' => 'Cara', 'price' => 5000, 'diopter_min' => 6]);
        listingIolLens($this->entity, ['model_name' => 'Barata', 'price' => 800, 'diopter_min' => 20]);
        listingIolLens($this->entity, ['model_name' => 'Media', 'price' => 2000, 'diopter_min' => 12]);

        expect(array_column(iolLensRows(iolLensesListingAs(['sort' => 'price', 'direction' => 'desc'])), 'model_name'))
            ->toBe(['Cara', 'Media', 'Barata'])
            ->and(array_column(iolLensRows(iolLensesListingAs(['sort' => 'diopter_min', 'direction' => 'asc'])), 'model_name'))
            ->toBe(['Cara', 'Media', 'Barata']);
    });

    it('ordena por modelo descendente mantendo o filtro de status', function () {
        listingIolLens($this->entity, ['model_name' => 'Ativa A']);
        listingIolLens($this->entity, ['model_name' => 'Ativa B']);
        listingIolLens($this->entity, ['model_name' => 'Inativa C', 'active' => false]);

        $response = iolLensesListingAs(['status' => 'active', 'sort' => 'model_name', 'direction' => 'desc'])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.status', 'active')
                ->where('filters.sort', 'model_name')
                ->where('filters.direction', 'desc'));

        expect(array_column(iolLensRows($response), 'model_name'))->toBe(['Ativa B', 'Ativa A']);
    });

    it('ordenação/direção/status fora da whitelist caem no padrão e são normalizados', function () {
        listingIolLens($this->entity, ['manufacturer' => 'Zeiss', 'model_name' => 'X']);
        listingIolLens($this->entity, ['manufacturer' => 'Alcon', 'model_name' => 'Y']);

        $response = iolLensesListingAs([
            'sort'      => 'entity_products.name;drop table entity_iol_lenses',
            'direction' => 'up',
            'status'    => ['active'],
            'search'    => ['x'],
        ])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.sort', 'manufacturer')
                ->where('filters.direction', 'asc')
                ->where('filters.status', 'all')
                ->where('filters.search', ''));

        expect(iolLensLabels(iolLensRows($response)))->toBe(['Alcon Y', 'Zeiss X']);

        // Ordenação/direção/página em formato de array (?x[]=1) também não derrubam a página.
        iolLensesListingAs(['sort' => ['price'], 'direction' => ['desc'], 'page' => ['2']])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.sort', 'manufacturer')
                ->where('filters.direction', 'asc')
                ->where('items.current_page', 1));
    });

    it('pagina sem repetir nem pular lentes com o mesmo valor ordenado (desempate por id)', function () {
        foreach (range(1, 15) as $i) {
            listingIolLens($this->entity, ['manufacturer' => 'Igual', 'model_name' => 'Igual', 'price' => 1500]);
        }

        DB::enableQueryLog();
        $ids = collect([1, 2])->flatMap(fn (int $page) => array_column(
            iolLensRows(iolLensesListingAs(['sort' => 'price', 'direction' => 'asc', 'page' => $page])),
            'id',
        ));
        $listingSql = collect(DB::getQueryLog())->pluck('query')
            ->filter(fn (string $sql) => str_contains($sql, 'from "entity_iol_lenses"') && str_contains($sql, 'offset'));
        DB::disableQueryLog();

        expect($ids)->toHaveCount(15)
            ->and($ids->unique())->toHaveCount(15)
            ->and($ids->all())->toBe(
                EntityIolLens::query()->where('entity_id', $this->entity->id)->orderBy('id')->pluck('id')->all(),
            );

        // Em dataset pequeno o PostgreSQL já devolve na ordem de inserção
        // (UUIDv7), então só o resultado não prova o desempate — a query da
        // listagem precisa terminar com o id da lente como critério final.
        expect($listingSql)->toHaveCount(2)
            ->each->toMatch('/order by .+, "entity_iol_lenses"\."id" asc limit/');
    });

    it('ordenação descendente por valor ou dioptria deixa lentes sem o dado no fim (NULLS LAST)', function () {
        listingIolLens($this->entity, ['model_name' => 'Sem Dado', 'price' => null, 'diopter_min' => null, 'diopter_max' => null]);
        listingIolLens($this->entity, ['model_name' => 'Barata', 'price' => 500, 'diopter_min' => 6]);
        listingIolLens($this->entity, ['model_name' => 'Cara', 'price' => 4000, 'diopter_min' => 20]);

        $byPrice   = iolLensesListingAs(['sort' => 'price', 'direction' => 'desc'])->assertOk();
        $byDiopter = iolLensesListingAs(['sort' => 'diopter_min', 'direction' => 'desc'])->assertOk();

        expect(array_column(iolLensRows($byPrice), 'model_name'))->toBe(['Cara', 'Barata', 'Sem Dado'])
            ->and(array_column(iolLensRows($byDiopter), 'model_name'))->toBe(['Cara', 'Barata', 'Sem Dado']);
    });
});

describe('Lentes IOL — isolamento e ações da listagem', function () {
    it('não lista lentes de outra clínica, mesmo ordenando por colunas do produto', function () {
        listingIolLens($this->entity, ['model_name' => 'Daqui', 'price' => 10]);

        $other = Entity::factory()->create(['is_client' => true, 'active' => true]);
        listingIolLens($other, ['model_name' => 'De Outra', 'price' => 99999]);

        $response = iolLensesListingAs(['sort' => 'price', 'direction' => 'desc'])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('items.total', 1));

        expect(array_column(iolLensRows($response), 'model_name'))->toBe(['Daqui']);
    });

    it('ativar/desativar pela listagem (payload completo da linha) preserva tipo, dioptrias e valor', function () {
        $lens = listingIolLens($this->entity, [
            'manufacturer' => 'Hoya',
            'model_name'   => 'Vivinex',
            'category'     => 'Tórica',
            'diopter_min'  => 8.5,
            'diopter_max'  => 28,
            'price'        => 3100.9,
        ]);
        $row = iolLensRows(iolLensesListingAs())[0];

        $this->actingAs($this->admin)
            ->withSession(panelSession($this->adminEntityUser))
            ->put(route('panel.stock.iollenses.update', $lens->id), [
                'manufacturer' => $row['manufacturer'],
                'model_name'   => $row['model_name'],
                'category'     => $row['category'],
                'diopter_min'  => $row['diopter_min'],
                'diopter_max'  => $row['diopter_max'],
                'price'        => $row['price'],
                'active'       => ! $row['active'],
            ])
            ->assertRedirect(route('panel.stock.iollenses.index'));

        $lens->refresh()->load('entityProduct');
        expect($lens->entityProduct->active)->toBeFalse()
            ->and($lens->entityProduct->manufacturer)->toBe('Hoya')
            ->and($lens->entityProduct->name)->toBe('Vivinex')
            ->and($lens->category)->toBe('Tórica')
            ->and((float) $lens->diopter_min)->toBe(8.5)
            ->and((float) $lens->diopter_max)->toBe(28.0)
            ->and((float) $lens->entityProduct->sale_price)->toBe(3100.9);
    });
});

describe('Lentes IOL — volta para a listagem após criar/editar/excluir', function () {
    it('editar volta para a listagem com busca, status, ordenação e página — só os parâmetros permitidos', function () {
        $lens  = listingIolLens($this->entity, ['model_name' => 'Volta']);
        $index = route('panel.stock.iollenses.index');
        $kept  = ['search' => 'Volta', 'status' => 'active', 'sort' => 'price', 'direction' => 'desc', 'page' => '2'];

        $this->actingAs($this->admin)
            ->withSession(panelSession($this->adminEntityUser))
            ->from($index . '?' . http_build_query($kept + ['category_id' => 'x', 'next' => 'https://evil.example.com']))
            ->put(route('panel.stock.iollenses.update', $lens->id), [
                'manufacturer' => 'Alcon',
                'model_name'   => 'Volta',
                'active'       => false,
            ])
            ->assertRedirect($index . '?' . http_build_query($kept))
            ->assertSessionHas('message', __('catalog_setting.updated'));
    });

    it('criar e excluir também voltam para a listagem filtrada', function () {
        $index   = route('panel.stock.iollenses.index');
        $listing = $index . '?' . http_build_query(['search' => 'Nova', 'sort' => 'model_name', 'page' => '3']);

        $this->actingAs($this->admin)
            ->withSession(panelSession($this->adminEntityUser))
            ->from($listing)
            ->post(route('panel.stock.iollenses.store'), ['manufacturer' => 'Zeiss', 'model_name' => 'Nova Volta'])
            ->assertRedirect($listing)
            ->assertSessionHas('message', __('catalog_setting.created'));

        $lens = EntityIolLens::query()->where('entity_id', $this->entity->id)->latest('id')->firstOrFail();

        $this->actingAs($this->admin)
            ->withSession(panelSession($this->adminEntityUser))
            ->from($listing)
            ->delete(route('panel.stock.iollenses.destroy', $lens->id))
            ->assertRedirect($listing)
            ->assertSessionHas('message', __('catalog_setting.deleted'));
    });

    it('descarta parâmetros em array e não segue Referer de outra página ou de outro host', function () {
        $lens  = listingIolLens($this->entity, ['model_name' => 'Seguro']);
        $index = route('panel.stock.iollenses.index');

        $update = fn (string $referer) => $this->actingAs($this->admin)
            ->withSession(panelSession($this->adminEntityUser))
            ->from($referer)
            ->put(route('panel.stock.iollenses.update', $lens->id), ['manufacturer' => 'Alcon', 'model_name' => 'Seguro']);

        $update($index . '?search[]=x&sort[]=price&page=4')->assertRedirect($index . '?page=4');
        $update(route('panel.stock.products.index', ['search' => 'x']))->assertRedirect($index);
        $update('https://evil.example.com/phishing?search=x')->assertRedirect($index);
        $update('https://evil.example.com' . parse_url($index, PHP_URL_PATH) . '?search=x')
            ->assertRedirect($index . '?search=x');
    });
});

describe('Lentes IOL — página além da última e busca com parâmetro em array', function () {
    it('desativar a única lente da última página com filtro "ativas" volta para a última página válida', function () {
        foreach (range(1, 13) as $i) {
            listingIolLens($this->entity, ['manufacturer' => 'Alcon', 'model_name' => sprintf('Lente %02d', $i)]);
        }
        $last  = EntityIolLens::query()->where('entity_id', $this->entity->id)->latest('id')->firstOrFail();
        $index = route('panel.stock.iollenses.index');
        $query = ['status' => 'active', 'page' => '2'];

        // Página 2 válida não é ajustada.
        expect(array_column(iolLensRows(iolLensesListingAs($query)), 'model_name'))->toBe(['Lente 13']);

        $this->actingAs($this->admin)
            ->withSession(panelSession($this->adminEntityUser))
            ->from($index . '?' . http_build_query($query))
            ->put(route('panel.stock.iollenses.update', $last->id), [
                'manufacturer' => 'Alcon',
                'model_name'   => 'Lente 13',
                'active'       => false,
            ])
            ->assertRedirect($index . '?' . http_build_query($query));

        $response = iolLensesListingAs($query)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('items.current_page', 1)
                ->where('items.last_page', 1)
                ->where('items.total', 12));

        expect(iolLensRows($response))->toHaveCount(12);
    });

    it('autocomplete do catálogo não estoura (500) com ?q[]=', function () {
        $this->actingAs($this->admin)
            ->withSession(panelSession($this->adminEntityUser))
            ->getJson(route('panel.stock.iollenses.search', ['q' => ['acry']]))
            ->assertOk()
            ->assertExactJson(['data' => []]);
    });
});
