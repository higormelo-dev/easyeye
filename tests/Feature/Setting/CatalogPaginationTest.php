<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Models\{Covenant, Entity, User};
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Listagem dos catálogos (BaseSettingController::index) era um ->get() sem
 * limite: convênios (~800 globais seedados) renderizavam todos numa página só.
 * Coberto via convênios; a paginação vive na classe base, então vale para os
 * demais catálogos (tipos de pele, íris, lentes...).
 */
beforeEach(function () {
    $this->entity      = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->otherEntity = Entity::factory()->create(['is_client' => true, 'active' => true]);

    $this->staff           = User::factory()->create();
    $this->staffEntityUser = createEntityUser($this->entity, $this->staff, ClientRule::Admin->value);
});

function covenantIndex(array $query = [])
{
    return test()->actingAs(test()->staff)
        ->withSession(panelSession(test()->staffEntityUser))
        ->get(route('panel.setting.covenants.index', $query));
}

describe('Catálogos — paginação da listagem', function () {
    it('pagina a listagem em vez de devolver todos os registros', function () {
        Covenant::factory()->count(20)->create(['entity_id' => $this->entity->id, 'name' => 'Paginado']);

        covenantIndex(['search' => 'Paginado'])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Panel/Settings/Catalog/Index')
                ->has('items.data', 15)
                ->where('items.total', 20)
                ->where('items.current_page', 1)
                ->where('items.last_page', 2));
    });

    it('entrega a segunda página sem repetir nem pular registros, mesmo com nomes iguais', function () {
        Covenant::factory()->count(20)->create(['entity_id' => $this->entity->id, 'name' => 'Mesmo nome']);

        $ids = collect([1, 2])->flatMap(function (int $page) {
            $data = null;
            covenantIndex(['search' => 'Mesmo nome', 'page' => $page])
                ->assertInertia(function (Assert $inertia) use (&$data) {
                    $data = $inertia->toArray()['props']['items']['data'];
                });

            return collect($data)->pluck('id');
        });

        expect($ids)->toHaveCount(20)
            ->and($ids->unique())->toHaveCount(20);
    });

    it('mantém a busca nos links de paginação', function () {
        Covenant::factory()->count(16)->create(['entity_id' => $this->entity->id, 'name' => 'Busca Link']);

        covenantIndex(['search' => 'Busca Link'])
            ->assertInertia(fn (Assert $page) => $page
                ->where('items.next_page_url', fn (string $url) => str_contains($url, 'search=Busca') && str_contains($url, 'page=2')));
    });

    it('não conta convênios de outra clínica no total', function () {
        Covenant::factory()->count(3)->create(['entity_id' => $this->entity->id, 'name' => 'Isolado']);
        Covenant::factory()->count(5)->create(['entity_id' => $this->otherEntity->id, 'name' => 'Isolado']);

        covenantIndex(['search' => 'Isolado'])
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 3)
                ->where('items.total', 3));
    });

    it('página além da última (ex.: após excluir o último item) volta para a última página válida', function () {
        Covenant::factory()->count(16)->create(['entity_id' => $this->entity->id, 'name' => 'Limite']);

        covenantIndex(['search' => 'Limite', 'page' => 9])
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 1)
                ->where('items.current_page', 2));
    });

    it('lista vazia não quebra (página 1, sem registros)', function () {
        covenantIndex(['search' => 'inexistente-xyz'])
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 0)
                ->where('items.total', 0));
    });
});

describe('Catálogos — ordenação pelos cabeçalhos (igual à tela de pacientes)', function () {
    it('expõe à UI só as colunas que o backend aceita ordenar', function () {
        covenantIndex()
            ->assertInertia(fn (Assert $page) => $page
                ->where('sortable', fn ($keys) => collect($keys)->sort()->values()->all() === ['code', 'created_at', 'name']));
    });

    it('ordena pela coluna e direção pedidas, mantendo a ordenação nos links de página', function () {
        foreach (['Alfa', 'Bravo', 'Charlie'] as $name) {
            Covenant::factory()->create(['entity_id' => $this->entity->id, 'name' => "Ord {$name}"]);
        }

        covenantIndex(['search' => 'Ord ', 'sort' => 'name', 'dir' => 'desc'])
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.sort', 'name')
                ->where('filters.dir', 'desc')
                ->where('items.data.0.name', 'ORD CHARLIE')
                ->where('items.data.2.name', 'ORD ALFA'));
    });

    it('ignora coluna fora da whitelist e normaliza o filtro devolvido à UI', function () {
        covenantIndex(['sort' => 'entity_id;drop table', 'dir' => 'sideways'])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.sort', 'name')
                ->where('filters.dir', 'asc'));
    });

    it('modo cards busca por código, igual à tabela', function () {
        $covenant = Covenant::factory()->create(['entity_id' => $this->entity->id, 'name' => 'Cards Codigo']);

        test()->actingAs($this->staff)
            ->withSession(panelSession($this->staffEntityUser))
            ->getJson(route('panel.setting.covenants.cards', ['search' => $covenant->code]))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $covenant->id)
            ->assertJsonPath('data.0.mode', 'full');
    });
});

describe('Catálogos — cards com o mesmo recorte da tabela', function () {
    it('cards traz o convênio excluído da clínica com Restaurar e o mesmo total da tabela', function () {
        Covenant::factory()->create(['entity_id' => $this->entity->id, 'name' => 'Recorte Ativo']);
        Covenant::factory()->create(['entity_id' => $this->entity->id, 'name' => 'Recorte Excluido'])->delete();

        $tableTotal = null;
        covenantIndex(['search' => 'Recorte'])
            ->assertInertia(function (Assert $page) use (&$tableTotal) {
                $tableTotal = $page->toArray()['props']['items']['total'];
            });

        $response = test()->actingAs($this->staff)
            ->withSession(panelSession($this->staffEntityUser))
            ->getJson(route('panel.setting.covenants.cards', ['search' => 'Recorte']))
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        expect($tableTotal)->toBe(2)
            ->and(collect($response->json('data'))->firstWhere('name', 'RECORTE EXCLUIDO')['mode'])->toBe('restore');
    });

    it('cards volta para a última página válida quando a pedida não existe mais', function () {
        Covenant::factory()->count(13)->create(['entity_id' => $this->entity->id, 'name' => 'Cards Limite']);

        test()->actingAs($this->staff)
            ->withSession(panelSession($this->staffEntityUser))
            ->getJson(route('panel.setting.covenants.cards', ['search' => 'Cards Limite', 'page' => 7]))
            ->assertOk()
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonCount(1, 'data');
    });
});
