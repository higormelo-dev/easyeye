<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Models\{Entity, Supplier, User};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Listagem de fornecedores no padrão de Panel/Patients: ordenação por
 * whitelist (SuppliersController::SORTABLE) com desempate por id, filtros
 * normalizados (a UI mostra a ordenação realmente aplicada), textos via `t`
 * (lang/{locale}/stock_suppliers.php) e isolamento por clínica.
 */
beforeEach(function () {
    $this->entity      = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->otherEntity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    giveInventoryModuleAccess($this->entity);

    $this->admin           = User::factory()->create();
    $this->adminEntityUser = createEntityUser($this->entity, $this->admin, ClientRule::Admin->value);
});

function listingSupplier(Entity $entity, array $attributes = []): Supplier
{
    return Supplier::create(array_merge([
        'entity_id' => $entity->id,
        'name'      => 'Fornecedor',
        'active'    => true,
    ], $attributes));
}

function suppliersListingAs(array $query = [])
{
    return test()->actingAs(test()->admin)
        ->withSession(panelSession(test()->adminEntityUser))
        ->get(route('panel.stock.suppliers.index', $query));
}

/** @return array<int, mixed> */
function suppliersListingColumn(array $query, string $column): array
{
    $values = [];

    suppliersListingAs($query)->assertOk()->assertInertia(function (Assert $page) use (&$values, $column) {
        $values = array_column($page->toArray()['props']['items']['data'], $column);
    });

    return $values;
}

/** Ação (store/update/destroy) disparada a partir de uma URL (Referer). */
function suppliersActionAs(string $from)
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
function suppliersListingSql(array $query): string
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    suppliersListingAs($query)->assertOk();

    $sql = collect(DB::getQueryLog())
        ->pluck('query')
        ->first(fn (string $sql) => str_starts_with($sql, 'select * from "suppliers"') && str_contains($sql, 'limit 15'));

    DB::disableQueryLog();

    expect($sql)->toBeString();

    return $sql;
}

describe('Fornecedores — ordenação e filtros como em pacientes', function () {
    it('sem parâmetros mantém a ordem de sempre (nome crescente) e devolve filtros normalizados e textos', function () {
        listingSupplier($this->entity, ['name' => 'Beta Óptica']);
        listingSupplier($this->entity, ['name' => 'Alfa Lentes']);

        suppliersListingAs()
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Panel/Stock/Suppliers/Index')
                ->where('items.data.0.name', 'Alfa Lentes')
                ->where('items.data.1.name', 'Beta Óptica')
                ->where('filters.search', '')
                ->where('filters.status', 'all')
                ->where('filters.sort', 'name')
                ->where('filters.direction', 'asc')
                ->where('t.page_title', 'Fornecedores')
                ->has('t.col_document')
                ->has('t.columns_label')
                ->has('t.pagination_suffix'));
    });

    it('ordena por coluna da whitelist na direção pedida', function () {
        listingSupplier($this->entity, ['name' => 'Ord A', 'document' => '11111111000111']);
        listingSupplier($this->entity, ['name' => 'Ord B', 'document' => '99999999000199']);
        listingSupplier($this->entity, ['name' => 'Ord C', 'document' => '55555555000155']);

        expect(suppliersListingColumn(['sort' => 'document', 'direction' => 'desc'], 'name'))
            ->toBe(['Ord B', 'Ord C', 'Ord A']);

        suppliersListingAs(['sort' => 'document', 'direction' => 'desc'])
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.sort', 'document')
                ->where('filters.direction', 'desc'));
    });

    it('ordena por contato', function () {
        listingSupplier($this->entity, ['name' => 'Z Fornecedor', 'contact_name' => 'Ana']);
        listingSupplier($this->entity, ['name' => 'A Fornecedor', 'contact_name' => 'Carlos']);

        expect(suppliersListingColumn(['sort' => 'contact_name', 'direction' => 'asc'], 'contact_name'))
            ->toBe(['Ana', 'Carlos']);
    });

    it('ordenação/direção/status fora da whitelist caem no padrão e voltam normalizados', function () {
        listingSupplier($this->entity, ['name' => 'Beta']);
        listingSupplier($this->entity, ['name' => 'Alfa']);

        suppliersListingAs(['sort' => 'name;drop table suppliers', 'direction' => 'sideways', 'status' => 'bogus'])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.sort', 'name')
                ->where('filters.direction', 'asc')
                ->where('filters.status', 'all')
                ->where('items.data.0.name', 'Alfa')
                ->where('items.total', 2));
    });

    it('busca e status continuam valendo junto com a ordenação', function () {
        listingSupplier($this->entity, ['name' => 'Lentes Ativa 1', 'active' => true, 'code' => 'FOR-0000000002']);
        listingSupplier($this->entity, ['name' => 'Lentes Ativa 2', 'active' => true, 'code' => 'FOR-0000000001']);
        listingSupplier($this->entity, ['name' => 'Lentes Inativa', 'active' => false]);
        listingSupplier($this->entity, ['name' => 'Outro Nome', 'active' => true]);

        expect(suppliersListingColumn(['search' => 'lentes', 'status' => 'active', 'sort' => 'code', 'direction' => 'asc'], 'name'))
            ->toBe(['Lentes Ativa 2', 'Lentes Ativa 1']);

        suppliersListingAs(['search' => 'lentes', 'status' => 'active', 'sort' => 'code', 'direction' => 'asc'])
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.search', 'lentes')
                ->where('filters.status', 'active')
                ->where('filters.sort', 'code'));
    });

    it('pagina sem repetir nem pular fornecedores com o mesmo nome (desempate por id)', function () {
        // Ids gravados em ordem DECRESCENTE: sem o desempate por id o
        // PostgreSQL tende a devolver a ordem física (inserção), que aqui é o
        // contrário da esperada — o teste só passa com `orderBy('suppliers.id')`.
        $ids = collect(range(1, 16))->map(fn () => (string) Str::uuid())->sort()->values();

        foreach ($ids->reverse() as $id) {
            (new Supplier())->forceFill([
                'id'        => $id,
                'entity_id' => $this->entity->id,
                'name'      => 'Mesmo Nome Fornecedor',
                'active'    => true,
            ])->save();
        }

        $listed = collect([1, 2])->flatMap(fn (int $page) => suppliersListingColumn(
            ['search' => 'Mesmo Nome Fornecedor', 'sort' => 'name', 'direction' => 'asc', 'page' => $page],
            'id',
        ));

        expect($listed->all())->toBe($ids->all());
    });

    it('parâmetros de query em formato de array não derrubam a listagem (sem erro 500) e caem no padrão', function () {
        listingSupplier($this->entity, ['name' => 'Alfa']);

        suppliersListingAs([
            'search'    => ['x'],
            'status'    => ['active'],
            'sort'      => ['code'],
            'direction' => ['desc'],
            'page'      => ['2'],
        ])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.search', '')
                ->where('filters.status', 'all')
                ->where('filters.sort', 'name')
                ->where('filters.direction', 'asc')
                ->where('items.total', 1)
                ->where('items.data.0.name', 'Alfa'));
    });

    it('toda chave da whitelist ordena pela coluna certa e desempata por id', function (string $sort, string $column, string $direction) {
        listingSupplier($this->entity, ['name' => 'Alfa', 'phone' => '6133334444', 'email' => 'alfa@example.test']);

        suppliersListingAs(['sort' => $sort, 'direction' => $direction])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.sort', $sort)
                ->where('filters.direction', $direction)
                ->where('items.total', 1));

        expect(suppliersListingSql(['sort' => $sort, 'direction' => $direction]))
            ->toContain("order by {$column} {$direction}, \"suppliers\".\"id\" asc limit 15");
    })->with([
        'nome'      => ['name', '"suppliers"."name"'],
        'código'    => ['code', '"suppliers"."code"'],
        'documento' => ['document', '"suppliers"."document"'],
        'contato'   => ['contact_name', '"suppliers"."contact_name"'],
        'telefone'  => ['phone', '"suppliers"."phone"'],
        'e-mail'    => ['email', '"suppliers"."email"'],
    ])->with(['asc', 'desc']);

    it('ordem padrão (sem parâmetros) também desempata por id', function () {
        // paginate() só executa o SELECT com LIMIT quando o COUNT é > 0.
        listingSupplier($this->entity, ['name' => 'Alfa']);

        expect(suppliersListingSql([]))
            ->toContain('order by "suppliers"."name" asc, "suppliers"."id" asc limit 15');
    });

    it('[ISOLAMENTO] ordenar/buscar nunca traz fornecedor de outra clínica', function () {
        listingSupplier($this->entity, ['name' => 'Isolado Daqui']);
        listingSupplier($this->otherEntity, ['name' => 'Isolado Outra']);

        suppliersListingAs(['search' => 'Isolado', 'sort' => 'name', 'direction' => 'desc'])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('items.total', 1)
                ->where('items.data.0.name', 'Isolado Daqui'));
    });

    it('continua enviando documento/telefone já formatados para a listagem', function () {
        listingSupplier($this->entity, ['name' => 'Com Doc', 'document' => '12345678000199', 'phone' => '6133334444']);

        suppliersListingAs()
            ->assertInertia(fn (Assert $page) => $page
                ->where('items.data.0.document_display', '12.345.678/0001-99')
                ->where('items.data.0.phone_display', '(61) 3333-4444'));
    });
});

describe('Fornecedores — ações voltam para a listagem como estava', function () {
    beforeEach(function () {
        $this->listingQuery = '?search=Alfa&status=active&sort=code&direction=desc&page=2';
        $this->listingFrom  = route('panel.stock.suppliers.index') . $this->listingQuery . '&foo=bar&next=https://evil.example';
    });

    it('cadastrar, editar e excluir preservam só busca/status/ordenação/página', function () {
        $expected = route('panel.stock.suppliers.index') . $this->listingQuery;

        suppliersActionAs($this->listingFrom)
            ->post(route('panel.stock.suppliers.store'), ['name' => 'Fornecedor Novo'])
            ->assertRedirect($expected)
            ->assertSessionHas('message', __('stock.supplier_created'));

        $supplier = Supplier::query()->where('entity_id', $this->entity->id)->where('name', 'Fornecedor Novo')->firstOrFail();

        suppliersActionAs($this->listingFrom)
            ->put(route('panel.stock.suppliers.update', $supplier->id), ['name' => 'Fornecedor Editado'])
            ->assertRedirect($expected)
            ->assertSessionHas('message', __('stock.supplier_updated'));

        suppliersActionAs($this->listingFrom)
            ->delete(route('panel.stock.suppliers.destroy', $supplier->id))
            ->assertRedirect($expected)
            ->assertSessionHas('message', __('stock.supplier_deleted'));

        expect(Supplier::query()->whereKey($supplier->id)->exists())->toBeFalse();
    });

    it('[SEGURANÇA] Referer de outro domínio não vira redirect externo e descarta parâmetros estranhos', function () {
        $response = suppliersActionAs('https://evil.example/panel/stock/suppliers?search=x&next=https://evil.example')
            ->post(route('panel.stock.suppliers.store'), ['name' => 'Fornecedor Referer']);

        expect($response->headers->get('Location'))
            ->toStartWith(route('panel.stock.suppliers.index'))
            ->not->toContain('evil.example')
            ->not->toContain('next=');
    });

    it('vindo de outra tela, volta para a listagem padrão', function () {
        suppliersActionAs(route('panel.dashboard') . '?search=Alfa')
            ->post(route('panel.stock.suppliers.store'), ['name' => 'Fornecedor Outra Tela'])
            ->assertRedirect(route('panel.stock.suppliers.index'));
    });

    it('parâmetro em formato de array no Referer é descartado sem erro', function () {
        suppliersActionAs(route('panel.stock.suppliers.index') . '?search[]=x&status=inactive')
            ->post(route('panel.stock.suppliers.store'), ['name' => 'Fornecedor Array'])
            ->assertRedirect(route('panel.stock.suppliers.index') . '?status=inactive');
    });
});
