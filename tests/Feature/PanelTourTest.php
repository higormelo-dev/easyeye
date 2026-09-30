<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Models\{Entity, User, UserPreference};
use App\Support\PanelTour;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/**
 * Tour guiado do painel (driver.js): prop `tour` compartilhada no painel da
 * clínica (id por perfil, textos, já visto) e estado gravado nas preferências
 * do usuário (`tours`) — só ids/status conhecidos, data do servidor, sem
 * apagar as outras preferências.
 */
beforeEach(function () {
    $this->entity     = Entity::factory()->create(['is_client' => true]);
    $this->user       = User::factory()->create();
    $this->entityUser = createEntityUser($this->entity, $this->user, ClientRule::Doctor->value);
});

function panelTourPatch($test, array $payload)
{
    return $test->actingAs($test->user)
        ->withSession(panelSession($test->entityUser))
        ->patchJson(route('panel.preferences.update'), $payload);
}

function panelTourDashboard($test, array $session = [])
{
    return $test->actingAs($test->user)
        ->withSession(panelSession($test->entityUser) + $session)
        ->get(route('panel.dashboard'));
}

/** Request "de verdade" para uma rota nomeada, com usuário (sem passar pelo HTTP). */
function panelTourRequest(string $routeName, ?User $user): Request
{
    $request = Request::create('/');
    $request->setRouteResolver(fn () => app('router')->getRoutes()->getByName($routeName));
    $request->setUserResolver(fn () => $user);

    return $request;
}

describe('prop compartilhada', function () {
    it('painel da clínica recebe o tour do perfil, com os textos e ainda não visto', function () {
        panelTourDashboard($this)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('tour.id', 'panel:doctor')
                ->where('tour.version', PanelTour::VERSION)
                ->where('tour.seen', false)
                ->where('tour.auto', true)
                ->where('tour.t.ui.next', __('tour.ui.next'))
                ->where('tour.t.nav.schedules', __('tour.nav.schedules')));
    });

    it('depois de concluir a versão atual, fica marcado como visto', function () {
        panelTourPatch($this, ['tours' => ['panel:doctor' => ['version' => PanelTour::VERSION, 'status' => 'completed']]])->assertOk();

        panelTourDashboard($this)->assertInertia(fn ($page) => $page->where('tour.seen', true));
    });

    it('versão antiga vista não conta: o tour novo aparece de novo', function () {
        UserPreference::mergeFor($this->user, ['tours' => ['panel:doctor' => ['version' => 0, 'status' => 'completed', 'at' => '2026-01-01T00:00:00-03:00']]]);

        panelTourDashboard($this)->assertInertia(fn ($page) => $page->where('tour.seen', false));
    });

    it('impersonação: o tour pode ser aberto, mas não abre sozinho', function () {
        panelTourDashboard($this, ['impersonating' => [
            'entity_user_id'     => $this->entityUser->id,
            'original_user_id'   => $this->user->id,
            'original_user_name' => 'Suporte',
        ]])->assertInertia(fn ($page) => $page->where('tour.auto', false));
    });

    it('fora do painel da clínica não há tour (painel SaaS, outra área, sem usuário ou sem perfil)', function () {
        session(panelSession($this->entityUser));
        expect(PanelTour::props(panelTourRequest('panel.dashboard', $this->user)))->not->toBeNull()
            ->and(PanelTour::props(panelTourRequest('panel.dashboard', null)))->toBeNull()
            ->and(PanelTour::props(panelTourRequest('login', $this->user)))->toBeNull();

        session(['selected_entity_is_client' => false]);
        expect(PanelTour::props(panelTourRequest('panel.dashboard', $this->user)))->toBeNull();

        foreach (['', 'hacker'] as $rule) {
            session(['selected_entity_is_client' => true, 'selected_entity_user_rule' => $rule]);
            expect(PanelTour::props(panelTourRequest('panel.dashboard', $this->user)))->toBeNull();
        }
    });
});

describe('passos da tela atual (dashboard sem lacuna)', function () {
    it('o dashboard recebe só os passos dele, na ordem das traduções; outras telas não recebem passos', function () {
        $expected = array_keys((array) trans('tour.pages')['panel.dashboard']);

        panelTourDashboard($this)->assertInertia(function ($page) use ($expected) {
            $tour = $page->toArray()['props']['tour'];

            expect(array_keys($tour['page']))->toBe($expected)
                ->and($tour['t'])->not->toHaveKey('pages');
        });

        session(panelSession($this->entityUser));
        expect(PanelTour::props(panelTourRequest('panel.patients.index', $this->user))['page'])->toBeNull();
    });

    it('toda tela com passos existe como rota', function () {
        foreach (array_keys((array) trans('tour.pages')) as $routeName) {
            expect(Route::has($routeName))->toBeTrue("Rota {$routeName} do tour não existe.");
        }
    });

    it('sem lacuna: cada passo do dashboard tem sua âncora data-tour e cada âncora dashboard-* tem seu passo (pt e en)', function () {
        $files  = [resource_path('js/Pages/Panel/Dashboard.vue'), ...glob(resource_path('js/Pages/Panel/Dashboard/*.vue'))];
        $source = implode("\n", array_map('file_get_contents', $files));
        preg_match_all('/data-tour="(dashboard-[a-z0-9-]+)"/', $source, $matches);
        $anchors = array_values(array_unique($matches[1]));

        foreach (['pt_BR', 'en'] as $locale) {
            $steps = array_keys((array) trans('tour.pages', [], $locale)['panel.dashboard']);

            expect($steps)->toEqualCanonicalizing($anchors, "Passos ({$locale}) e âncoras do dashboard diferem.");

            foreach ((array) trans('tour.pages', [], $locale)['panel.dashboard'] as $key => $step) {
                expect($step['title'] ?? '')->not->toBe('', "{$locale}: passo {$key} sem título")
                    ->and($step['description'] ?? '')->not->toBe('', "{$locale}: passo {$key} sem descrição");
            }
        }
    });

    it('sem lacuna no layout: cada passo do cabeçalho/menu tem sua âncora (inclusive recolher o menu e o assistente de IA), em pt e en', function () {
        $source = file_get_contents(resource_path('js/Layouts/AppLayout.vue'))
            . file_get_contents(resource_path('js/Components/Panel/AiFloatingAssistant.vue'));

        $ptLayout = array_keys((array) trans('tour.layout', [], 'pt_BR'));
        expect(array_keys((array) trans('tour.layout', [], 'en')))->toEqualCanonicalizing($ptLayout)
            ->and($ptLayout)->toContain('sidebar-toggle', 'ai-assistant');

        foreach ([...$ptLayout, 'mobile-menu'] as $name) {
            expect(str_contains($source, "data-tour=\"{$name}\""))->toBeTrue("Âncora data-tour=\"{$name}\" não encontrada no layout.");
        }
    });
});

describe('preferência tours', function () {
    it('grava versão, status e a data do servidor, sem apagar outras preferências', function () {
        Carbon::setTestNow('2026-09-29 16:40:00');
        UserPreference::mergeFor($this->user, ['dashboard_widget_order' => ['agenda', 'kpis']]);

        panelTourPatch($this, ['tours' => ['panel:doctor' => ['version' => 1, 'status' => 'completed', 'at' => '2000-01-01']]])
            ->assertOk()
            ->assertJsonPath('data.tours.panel:doctor.version', 1)
            ->assertJsonPath('data.tours.panel:doctor.status', 'completed')
            ->assertJsonPath('data.tours.panel:doctor.at', now()->toIso8601String())
            ->assertJsonPath('data.dashboard_widget_order', ['agenda', 'kpis']);

        Carbon::setTestNow();
    });

    it('mescla por tour: gravar outro perfil mantém o anterior; repetir o mesmo estado mantém a data', function () {
        Carbon::setTestNow('2026-09-29 10:00:00');
        panelTourPatch($this, ['tours' => ['panel:doctor' => ['version' => 1, 'status' => 'dismissed']]])->assertOk();

        Carbon::setTestNow('2026-09-30 11:00:00');
        panelTourPatch($this, ['tours' => [
            'panel:doctor' => ['version' => 1, 'status' => 'dismissed'],
            'panel:admin'  => ['version' => 1, 'status' => 'completed'],
        ]])->assertOk();

        $tours = UserPreference::valueFor($this->user->fresh(), 'tours');

        expect(array_keys($tours))->toBe(['panel:doctor', 'panel:admin'])
            ->and($tours['panel:doctor']['at'])->toBe(Carbon::parse('2026-09-29 10:00:00')->toIso8601String())
            ->and($tours['panel:admin']['at'])->toBe(Carbon::parse('2026-09-30 11:00:00')->toIso8601String());

        Carbon::setTestNow();
    });

    it('ids que não são de perfis conhecidos saem do mapa na próxima gravação (mapa limitado aos perfis)', function () {
        UserPreference::mergeFor($this->user, ['tours' => [
            'panel:perfil_antigo' => ['version' => 1, 'status' => 'completed', 'at' => '2026-01-01T00:00:00-03:00'],
            'panel:doctor'        => ['version' => 1, 'status' => 'dismissed', 'at' => '2026-01-01T00:00:00-03:00'],
        ]]);

        panelTourPatch($this, ['tours' => ['panel:admin' => ['version' => 1, 'status' => 'completed']]])->assertOk();

        expect(array_keys(UserPreference::valueFor($this->user->fresh(), 'tours')))->toBe(['panel:doctor', 'panel:admin']);
    });

    it('impersonação: o servidor ignora tours (nada gravado na conta do usuário), mesmo vindo de uma aba antiga', function () {
        $this->actingAs($this->user)
            ->withSession(panelSession($this->entityUser) + ['impersonating' => [
                'entity_user_id'     => $this->entityUser->id,
                'original_user_id'   => $this->user->id,
                'original_user_name' => 'Suporte',
            ]])
            ->patchJson(route('panel.preferences.update'), ['tours' => ['panel:doctor' => ['version' => 1, 'status' => 'completed']]])
            ->assertOk();

        expect(UserPreference::valueFor($this->user->fresh(), 'tours'))->toBeNull();
    });

    it('recusa id fora do formato, status ou versão inválidos (nada é gravado)', function (array $tours) {
        panelTourPatch($this, ['tours' => $tours])->assertUnprocessable();

        expect(UserPreference::valueFor($this->user->fresh(), 'tours'))->toBeNull();
    })->with([
        'id desconhecido'    => [['qualquer' => ['version' => 1, 'status' => 'completed']]],
        'id com caminho'     => [['panel:../admin' => ['version' => 1, 'status' => 'completed']]],
        'perfil inexistente' => [['panel:hacker' => ['version' => 1, 'status' => 'completed']]],
        'status inválido'    => [['panel:doctor' => ['version' => 1, 'status' => 'hacked']]],
        'versão zero'        => [['panel:doctor' => ['version' => 0, 'status' => 'completed']]],
        'sem versão'         => [['panel:doctor' => ['status' => 'completed']]],
        'não é objeto'       => [['panel:doctor' => 'completed']],
    ]);
});
