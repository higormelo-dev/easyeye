<?php

declare(strict_types=1);

use App\Enums\{ClientRule, ReportSettingStatus};
use App\Models\{Entity, ReportCategory, ReportSetting, User};
use Illuminate\Support\{Arr, Str};
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Modelos de documentação da clínica no padrão de Panel/Patients
 * (Setting\ReportSettingsController::index): paginação, busca, filtros de
 * categoria/status e ordenação server-side (tabela e cards no MESMO
 * paginator), textos por idioma e ações que voltam para a listagem com os
 * filtros e dão retorno — inclusive nas recusas que antes eram erro 500.
 */

beforeEach(function (): void {
    $this->entity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->admin   = User::factory()->create();
    $member        = createEntityUser($this->entity, $this->admin, ClientRule::Admin->value);
    $this->session = panelSession($member);
});

function rsTemplate(?string $entityId, string $title, array $attrs = []): ReportSetting
{
    return ReportSetting::query()->create(['entity_id' => $entityId, 'title' => $title, ...$attrs]);
}

function rsCategory(string $name, array $attrs = []): ReportCategory
{
    return ReportCategory::query()->create(['name' => $name, 'slug' => Str::slug($name) . '-' . Str::random(5), ...$attrs]);
}

function rsIndex(array $query = []): string
{
    return route('panel.setting.report-settings.index', $query);
}

/** @return list<string> títulos na página, na ordem exibida */
function rsTitles($response): array
{
    return collect($response->viewData('page')['props']['items']['data'])->pluck('title')->all();
}

describe('listagem', function (): void {
    it('pagina 12 por página, só modelos da clínica, com filtros normalizados e textos da tela', function (): void {
        foreach (range(1, 13) as $i) {
            rsTemplate($this->entity->id, sprintf('Modelo %02d', $i));
        }
        $other = Entity::factory()->create(['is_client' => true, 'active' => true]);
        rsTemplate($other->id, 'Modelo de outra clínica');
        // Global em rascunho: não é adotado automaticamente nem listado.
        rsTemplate(null, 'Global em rascunho', ['status' => ReportSettingStatus::Draft]);

        $this->actingAs($this->admin)->withSession($this->session)
            ->get(rsIndex())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Panel/Settings/ReportSettings/Index')
                ->has('items.data', 12)
                ->where('items.total', 13)
                ->where('items.last_page', 2)
                ->where('items.data.0.title', 'Modelo 01')
                ->where('filters', ['search' => '', 'category' => '', 'status' => 'all', 'sort' => 'title', 'direction' => 'asc'])
                ->where('t.page_title', 'Modelos de documentação')
                ->where('breadcrumbs.2.label', 'Modelos de documentação')
                ->where('urls.index', rsIndex()));
    });

    it('busca por título ou descrição, sem acento', function (): void {
        rsTemplate($this->entity->id, 'Receituário de óculos');
        rsTemplate($this->entity->id, 'Atestado', ['description' => 'Uso junto ao receituario']);
        rsTemplate($this->entity->id, 'Laudo de topografia');

        $response = $this->actingAs($this->admin)->withSession($this->session)
            ->get(rsIndex(['search' => 'receituario']));

        expect(rsTitles($response))->toBe(['Atestado', 'Receituário de óculos']);
    });

    it('filtra por categoria ativa e por status; categoria inválida ou inativa é ignorada', function (): void {
        $receitas = rsCategory('Receitas');
        $inativa  = rsCategory('Antiga', ['active' => false]);
        rsTemplate($this->entity->id, 'Receita A', ['report_category_id' => $receitas->id]);
        rsTemplate($this->entity->id, 'Receita B inativa', ['report_category_id' => $receitas->id, 'active' => false]);
        rsTemplate($this->entity->id, 'Sem categoria');

        $session = $this->session;

        expect(rsTitles($this->actingAs($this->admin)->withSession($session)
            ->get(rsIndex(['category' => $receitas->id]))))->toBe(['Receita A', 'Receita B inativa'])
            ->and(rsTitles($this->actingAs($this->admin)->withSession($session)
                ->get(rsIndex(['category' => $receitas->id, 'status' => 'inactive']))))->toBe(['Receita B inativa'])
            ->and(rsTitles($this->actingAs($this->admin)->withSession($session)
                ->get(rsIndex(['status' => 'active']))))->toBe(['Receita A', 'Sem categoria']);

        foreach ([$inativa->id, (string) Str::uuid(), 'nao-e-uuid'] as $invalid) {
            $response = $this->actingAs($this->admin)->withSession($session)->get(rsIndex(['category' => $invalid]))->assertOk();
            expect($response->viewData('page')['props']['filters']['category'])->toBe('')
                ->and(rsTitles($response))->toHaveCount(3);
        }
    });

    it('ordena por categoria com "sem categoria" no fim nos dois sentidos', function (): void {
        rsTemplate($this->entity->id, 'Sem categoria');
        rsTemplate($this->entity->id, 'Da B', ['report_category_id' => rsCategory('Bbb')->id]);
        rsTemplate($this->entity->id, 'Da A', ['report_category_id' => rsCategory('Aaa')->id]);

        $asc  = $this->actingAs($this->admin)->withSession($this->session)->get(rsIndex(['sort' => 'category', 'direction' => 'asc']));
        $desc = $this->actingAs($this->admin)->withSession($this->session)->get(rsIndex(['sort' => 'category', 'direction' => 'desc']));

        expect(rsTitles($asc))->toBe(['Da A', 'Da B', 'Sem categoria'])
            ->and(rsTitles($desc))->toBe(['Da B', 'Da A', 'Sem categoria']);
    });

    it('ordenação/filtros inválidos ou em formato de array caem no padrão, sem 500', function (array $query): void {
        rsTemplate($this->entity->id, 'Único');

        $response = $this->actingAs($this->admin)->withSession($this->session)
            ->get(rsIndex($query))
            ->assertOk();

        expect($response->viewData('page')['props']['filters'])
            ->toBe(['search' => '', 'category' => '', 'status' => 'all', 'sort' => 'title', 'direction' => 'asc']);
    })->with([
        'coluna inexistente' => [['sort' => 'entity_id', 'direction' => 'desc; drop table report_settings', 'status' => 'x']],
        'arrays'             => [['sort' => ['title'], 'direction' => ['desc'], 'search' => ['x'], 'category' => ['y'], 'status' => ['z']]],
    ]);

    it('página além da última mostra a última página válida', function (): void {
        foreach (range(1, 13) as $i) {
            rsTemplate($this->entity->id, sprintf('Modelo %02d', $i));
        }

        $this->actingAs($this->admin)->withSession($this->session)
            ->get(rsIndex(['page' => 9]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('items.current_page', 2)
                ->where('items.data.0.title', 'Modelo 13'));
    });

    it('linha: status, origem, atualização disponível e data ISO — sem N+1 no modelo de origem', function (): void {
        $global = rsTemplate(null, 'Global receita', ['status' => ReportSettingStatus::Published, 'version' => 3]);
        $adopt  = fn (string $title) => rsTemplate($this->entity->id, $title, ['source_setting_id' => $global->id, 'source_version' => 2]);
        $adopt('Adotado 1');
        rsTemplate($this->entity->id, 'Próprio inativo', ['active' => false]);

        $countQueries = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $response = $this->actingAs($this->admin)->withSession($this->session)->get(rsIndex());
            DB::disableQueryLog();

            return [$response, count(DB::getQueryLog())];
        };

        $countQueries(); // aquece caches de primeira requisição (perfis, sessão)
        [$response, $queriesWithOne] = $countQueries();
        $rows                        = collect($response->viewData('page')['props']['items']['data'])->keyBy('title');

        expect($rows['Adotado 1'])->toMatchArray(['is_adopted' => true, 'has_update' => true, 'active' => true, 'mode' => 'full'])
            ->and($rows['Próprio inativo'])->toMatchArray(['is_adopted' => false, 'has_update' => false, 'active' => false, 'reimport_url' => null])
            ->and($rows['Adotado 1']['updated_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/');

        foreach (range(2, 6) as $i) {
            $adopt("Adotado {$i}");
        }
        [, $queriesWithSix] = $countQueries();

        expect($queriesWithSix)->toBe($queriesWithOne);
    });

    it('sem settings.manage, a secretária continua sem acesso', function (): void {
        $secretary = User::factory()->create();
        $member    = createEntityUser($this->entity, $secretary, ClientRule::Secretary->value);

        $this->actingAs($secretary)->withSession(panelSession($member))
            ->get(rsIndex(['search' => 'x']))
            ->assertForbidden();
    });

    it('o endpoint JSON de cards (não usado pela tela) saiu', function (): void {
        expect(fn () => route('panel.setting.report-settings.cards'))->toThrow(InvalidArgumentException::class);

        $this->actingAs($this->admin)->withSession($this->session)
            ->getJson('/panel/setting/report-settings/cards')
            ->assertNotFound();
    });
});

describe('ações voltam para a listagem com retorno', function (): void {
    beforeEach(function (): void {
        $this->from    = rsIndex(['search' => 'modelo', 'status' => 'active', 'sort' => 'updated_at', 'direction' => 'desc', 'page' => '2', 'evil' => 'x']);
        $this->expects = rsIndex(['search' => 'modelo', 'status' => 'active', 'sort' => 'updated_at', 'direction' => 'desc', 'page' => '2']);
    });

    it('excluir mantém os filtros e avisa', function (): void {
        $template = rsTemplate($this->entity->id, 'Modelo descartável');

        $this->actingAs($this->admin)->withSession($this->session)->from($this->from)
            ->delete(route('panel.setting.report-settings.destroy', $template))
            ->assertRedirect($this->expects)
            ->assertSessionHas('message', 'Modelo excluído com sucesso.');

        expect($template->fresh()->trashed())->toBeTrue();
    });

    it('reimportar traz a versão do global, mantém os filtros e avisa', function (): void {
        $global = rsTemplate(null, 'Global', ['status' => ReportSettingStatus::Published, 'version' => 2]);
        $local  = rsTemplate($this->entity->id, 'Local', ['source_setting_id' => $global->id, 'source_version' => 1]);

        $this->actingAs($this->admin)->withSession($this->session)->from($this->from)
            ->post(route('panel.setting.report-settings.reimport', $local))
            ->assertRedirect($this->expects)
            ->assertSessionHas('message', 'Conteúdo reimportado com sucesso.');

        expect($local->fresh()->source_version)->toBe(2);
    });

    it('reimportar sem o global de origem avisa o erro (antes: 500)', function (): void {
        $global = rsTemplate(null, 'Global', ['status' => ReportSettingStatus::Published]);
        $local  = rsTemplate($this->entity->id, 'Local', ['source_setting_id' => $global->id, 'source_version' => 1]);
        $global->delete();

        $this->actingAs($this->admin)->withSession($this->session)->from($this->from)
            ->post(route('panel.setting.report-settings.reimport', $local))
            ->assertRedirect($this->expects)
            ->assertSessionHas('error', 'Não foi possível reimportar: o modelo global de origem não está mais disponível.');
    });

    it('adotar algo que não é global publicado avisa o erro (antes: 500) e não cria cópia', function (): void {
        $own = rsTemplate($this->entity->id, 'Próprio');

        $this->actingAs($this->admin)->withSession($this->session)->from($this->from)
            ->post(route('panel.setting.report-settings.adopt', $own))
            ->assertRedirect($this->expects)
            ->assertSessionHas('error', 'Este modelo não está disponível para adoção.');

        expect(ReportSetting::query()->where('source_setting_id', $own->id)->exists())->toBeFalse();
    });

    it('salvar vindo do formulário volta para a listagem limpa, com aviso', function (): void {
        $template = rsTemplate($this->entity->id, 'Editável');

        $this->actingAs($this->admin)->withSession($this->session)
            ->from(route('panel.setting.report-settings.edit', $template))
            ->put(route('panel.setting.report-settings.update', $template), ['title' => 'Editado'])
            ->assertRedirect(rsIndex())
            ->assertSessionHas('message', 'Modelo atualizado com sucesso.');

        expect($template->fresh()->title)->toBe('Editado');
    });

    it('Referer de outro site nunca vira destino (sem open redirect)', function (): void {
        $template = rsTemplate($this->entity->id, 'Modelo');

        $response = $this->actingAs($this->admin)->withSession($this->session)
            ->from('https://evil.example/panel/setting/report-settings?search=x')
            ->delete(route('panel.setting.report-settings.destroy', $template));

        $response->assertRedirect(rsIndex(['search' => 'x']));
        expect($response->headers->get('Location'))->not->toContain('evil.example');
    });

    it('excluir/reimportar modelo de outra clínica continua 404', function (): void {
        $other    = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $template = rsTemplate($other->id, 'Alheio');

        $this->actingAs($this->admin)->withSession($this->session)
            ->delete(route('panel.setting.report-settings.destroy', $template))
            ->assertNotFound();
        $this->actingAs($this->admin)->withSession($this->session)
            ->post(route('panel.setting.report-settings.reimport', $template))
            ->assertNotFound();

        expect($template->fresh()->trashed())->toBeFalse();
    });
});

describe('idioma', function (): void {
    it('em inglês: textos da tela e mensagens de retorno traduzidos', function (): void {
        $template = rsTemplate($this->entity->id, 'Prescription');
        $session  = [...$this->session, 'locale' => 'en'];

        $this->actingAs($this->admin)->withSession($session)
            ->get(rsIndex())
            ->assertInertia(fn (Assert $page) => $page
                ->where('t.page_title', 'Document templates')
                ->where('breadcrumbs.2.label', 'Document templates'));

        $this->actingAs($this->admin)->withSession($session)->from(rsIndex())
            ->delete(route('panel.setting.report-settings.destroy', $template))
            ->assertSessionHas('message', 'Template deleted successfully.');
    });

    it('lang/pt_BR e lang/en de report_settings têm as mesmas chaves', function (): void {
        $pt = array_keys(Arr::dot(require lang_path('pt_BR/report_settings.php')));
        $en = array_keys(Arr::dot(require lang_path('en/report_settings.php')));

        sort($pt);
        sort($en);

        expect($en)->toBe($pt);
    });
});
