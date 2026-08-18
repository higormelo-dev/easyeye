<?php

use App\Enums\{ClientRule, FeatureKey, SubscriptionStatus};
use App\Models\{Entity, Plan, PlanFeature, Subscription, User};
use App\Support\PanelNavigation;
use Illuminate\Support\Facades\Route;

/**
 * Teste de regressão do PanelNavigation (app/Support/PanelNavigation.php).
 *
 * Bug original ("página preta"): PanelNavigation::build() referenciava uma
 * rota com nome errado (label/nome digitado incorretamente) e isso derrubava
 * a renderização INTEIRA do menu — a página inertia inteira ficava preta,
 * sem nenhum fallback. Ver App\Support\PanelNavigation e
 * resources/js/Layouts/AppLayout.vue::safeRoute() (hardening no front).
 *
 * Este arquivo cobre:
 *  1. build() nunca lança exceção pra sessão de admin cliente.
 *  2. As 5 categorias reorganizadas de Configurações (Clínica/Atendimento/
 *     Usuários e permissões/Documentos/Oftalmologia) existem e têm children.
 *  3. TODA rota referenciada no array retornado (recursivo, incluindo
 *     children) existe de fato no route list — o teste mais importante,
 *     pega qualquer nome de rota digitado errado.
 *  4. Labels de "covertesttypes" e "colorvisiontypes" não ficam duplicados.
 *  5. Os 8 sub-catálogos oftalmológicos não viram itens soltos no menu.
 *  6. "visittypes" usa o label "Tipos de atendimento" (não mais "Tipos de visita").
 *  7. Request HTTP real: página do catálogo responde 200 e o prop Inertia
 *     `tabsGroup` vem populado com as 8 entradas.
 */

/**
 * Percorre recursivamente um array de navegação (item + children) e coleta
 * todo valor de 'route' encontrado.
 *
 * @param array<int, array<string, mixed>> $nav
 *
 * @return list<string>
 */
function collectNavRoutes(array $nav): array
{
    $routes = [];

    foreach ($nav as $item) {
        if (isset($item['route']) && is_string($item['route'])) {
            $routes[] = $item['route'];
        }

        if (isset($item['children']) && is_array($item['children'])) {
            $routes = [...$routes, ...collectNavRoutes($item['children'])];
        }
    }

    return array_values(array_unique($routes));
}

/**
 * Localiza o item de navegação de nível superior cuja 'key' bate.
 *
 * @param array<int, array<string, mixed>> $nav
 *
 * @return array<string, mixed>|null
 */
function findNavItemByKey(array $nav, string $key): ?array
{
    foreach ($nav as $item) {
        if (($item['key'] ?? null) === $key) {
            return $item;
        }
    }

    return null;
}

/**
 * Assert que cada nome de rota da lista existe no route list real da
 * aplicação — nem `Route::has()` mente, nem `route()` deixa passar nome
 * inválido sem estourar RouteNotFoundException.
 *
 * @param list<string> $routeNames
 */
function assertAllRoutesResolve(array $routeNames): void
{
    expect($routeNames)->not->toBeEmpty();

    foreach ($routeNames as $routeName) {
        expect(Route::has($routeName))
            ->toBeTrue("Rota '{$routeName}' referenciada no menu não existe no route list.");

        expect(fn () => route($routeName))
            ->not->toThrow(Throwable::class, null, "route('{$routeName}') lançou exceção — nome de rota quebrado.");
    }
}

/**
 * Cria uma Entity cliente ativa com assinatura habilitando as features
 * informadas — usado pra exercitar os ramos condicionais do menu (IA,
 * gateways próprios) que dependem de FeatureGateService.
 *
 * @param list<FeatureKey> $enabledFeatures
 */
function entityWithFeatures(array $enabledFeatures): Entity
{
    $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $plan   = Plan::factory()->create(['active' => true]);

    foreach ($enabledFeatures as $feature) {
        PlanFeature::factory()->enabled($feature)->for($plan)->create();
    }

    Subscription::factory()->create([
        'entity_id' => $entity->id,
        'plan_id'   => $plan->id,
        'status'    => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(),
        'ends_at'   => now()->addMonth(),
    ]);

    return $entity;
}

beforeEach(function () {
    $this->entity          = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->adminUser       = User::factory()->create();
    $this->adminEntityUser = createEntityUser($this->entity, $this->adminUser, ClientRule::Admin->value);
});

// ── 1. Regressão "página preta": build() nunca lança exceção ───────────────

it('PanelNavigation::build() não lança nenhuma exceção pra sessão de admin cliente', function () {
    session(panelSession($this->adminEntityUser));

    $nav = null;

    expect(function () use (&$nav) {
        $nav = PanelNavigation::build();
    })->not->toThrow(Throwable::class);

    expect($nav)->toBeArray()->not->toBeEmpty();
});

// ── 2. As 5 categorias de Configurações, cada uma com children ─────────────

it('expõe as 5 categorias de Configurações com children não vazios', function () {
    session(panelSession($this->adminEntityUser));

    $nav = PanelNavigation::build();

    $expectedCategories = [
        'settings-clinical'      => 'Clínica',
        'settings-attendance'    => 'Atendimento',
        'settings-users'         => 'Usuários e permissões',
        'settings-documents'     => 'Documentos',
        'settings-ophthalmology' => 'Oftalmologia',
    ];

    foreach ($expectedCategories as $key => $expectedLabel) {
        $item = findNavItemByKey($nav, $key);

        expect($item)->not->toBeNull("Categoria '{$key}' não encontrada no menu.");
        expect($item['label'])->toBe($expectedLabel);
        expect($item['children'] ?? [])->not->toBeEmpty("Categoria '{$key}' está sem children.");
    }
});

// ── 3. Toda rota referenciada no menu existe — o teste mais importante ─────

it('nenhuma rota referenciada no menu de admin cliente está quebrada', function () {
    session(panelSession($this->adminEntityUser));

    assertAllRoutesResolve(collectNavRoutes(PanelNavigation::build()));
});

it('nenhuma rota do menu de Assistente de IA (médico) está quebrada', function () {
    $entity           = entityWithFeatures([FeatureKey::HasAiExamAssistant]);
    $doctor           = User::factory()->create();
    $doctorEntityUser = createEntityUser($entity, $doctor, ClientRule::Doctor->value);

    session(panelSession($doctorEntityUser));

    $routes = collectNavRoutes(PanelNavigation::build());

    // Confirma que o ramo condicional de IA (médico vê submenu com 2 rotas)
    // realmente foi exercitado — senão o teste passaria vazio, sem valor.
    expect(array_filter($routes, fn ($r) => str_starts_with($r, 'panel.ai-runs.') || str_starts_with($r, 'panel.setting.ai-prompts.')))
        ->not->toBeEmpty();

    assertAllRoutesResolve($routes);
});

it('nenhuma rota do menu de gateways de pagamento próprios está quebrada', function () {
    $entity          = entityWithFeatures([FeatureKey::HasOwnPaymentGateways]);
    $admin           = User::factory()->create();
    $adminEntityUser = createEntityUser($entity, $admin, ClientRule::Admin->value);

    session(panelSession($adminEntityUser));

    $routes = collectNavRoutes(PanelNavigation::build());

    expect($routes)->toContain('panel.setting.gateways.index');

    assertAllRoutesResolve($routes);
});

it('nenhuma rota do menu de gestão SaaS (manager, entity não-cliente) está quebrada', function () {
    $saasEntity     = Entity::factory()->create(['is_client' => false, 'active' => true]);
    $saasUser       = User::factory()->create();
    $saasEntityUser = createEntityUser($saasEntity, $saasUser, ClientRule::Admin->value);

    session([
        'selected_entity_id'        => $saasEntityUser->entity_id,
        'selected_entity_user_id'   => $saasEntityUser->id,
        'selected_entity_user_rule' => $saasEntityUser->rule,
        'selected_entity_is_client' => false,
    ]);

    $routes = collectNavRoutes(PanelNavigation::build());

    // Garante que caímos mesmo no ramo managerNav() (rotas manager.*).
    expect(array_filter($routes, fn ($r) => str_starts_with($r, 'manager.')))->not->toBeEmpty();

    assertAllRoutesResolve($routes);
});

// ── 4. Labels de covertesttypes/colorvisiontypes não duplicados/trocados ───

it('labels de "Tipos de teste de cobertura" e "Tipos de visão cromática" não ficam duplicados ou trocados', function () {
    $response = $this->actingAs($this->adminUser)
        ->withSession(panelSession($this->adminEntityUser))
        ->get(route('panel.setting.skintypes.index'));

    $response->assertOk();

    $tabsGroup = collect($response->inertiaProps('tabsGroup'))->keyBy('url');

    $coverTestEntry   = $tabsGroup->get(route('panel.setting.covertesttypes.index'));
    $colorVisionEntry = $tabsGroup->get(route('panel.setting.colorvisiontypes.index'));

    expect($coverTestEntry)->not->toBeNull()
        ->and($colorVisionEntry)->not->toBeNull();

    expect($coverTestEntry['label'])->toBe('Tipos de teste de cobertura');
    expect($colorVisionEntry['label'])->toBe('Tipos de visão cromática');
    expect($coverTestEntry['label'])->not->toBe($colorVisionEntry['label']);
});

// ── 5. Os 8 sub-catálogos não aparecem soltos em Oftalmologia ──────────────

it('os 8 sub-catálogos oftalmológicos não têm entrada própria nos children de Oftalmologia', function () {
    session(panelSession($this->adminEntityUser));

    $nav           = PanelNavigation::build();
    $ophthalmology = findNavItemByKey($nav, 'settings-ophthalmology');

    expect($ophthalmology)->not->toBeNull();

    // Só existe 1 item ("Parâmetros oftalmológicos") nos children — os 8
    // sub-catálogos viraram abas dentro dele (BaseSettingController::$tabsGroup),
    // não itens soltos no nível de children de Oftalmologia.
    expect($ophthalmology['children'])->toHaveCount(1);
    expect($ophthalmology['children'][0]['label'])->toBe('Parâmetros oftalmológicos');

    // As rotas diretas (campo 'route') dentro de children de Oftalmologia
    // não incluem os 8 sub-catálogos como entradas independentes — só a
    // única rota-âncora do item "Parâmetros oftalmológicos".
    $directChildRoutes = collectNavRoutes($ophthalmology['children']);
    expect($directChildRoutes)->toHaveCount(1);

    $subCatalogRoutes = [
        'panel.setting.skintypes.index',
        'panel.setting.iristypes.index',
        'panel.setting.additiontypes.index',
        'panel.setting.visualacuitytypes.index',
        'panel.setting.colorvisiontypes.index',
        'panel.setting.nearpointconvergences.index',
        'panel.setting.covertesttypes.index',
        'panel.setting.lenses.index',
    ];

    // No máximo 1 dos 8 pode ser a rota-âncora do item único; os outros 7
    // definitivamente não podem aparecer como 'route' de um child separado.
    $matchedSubCatalogRoutes = array_intersect($directChildRoutes, $subCatalogRoutes);
    expect(count($matchedSubCatalogRoutes))->toBeLessThanOrEqual(1);
});

// ── 6. "visittypes" usa o label correto ─────────────────────────────────────

it('rota visittypes usa o label "Tipos de atendimento" (não mais "Tipos de visita")', function () {
    session(panelSession($this->adminEntityUser));

    $nav        = PanelNavigation::build();
    $attendance = findNavItemByKey($nav, 'settings-attendance');

    expect($attendance)->not->toBeNull();

    $visitTypesItem = collect($attendance['children'])->firstWhere('route', 'panel.setting.visittypes.index');

    expect($visitTypesItem)->not->toBeNull();
    expect($visitTypesItem['label'])->toBe('Tipos de atendimento');
    expect($visitTypesItem['label'])->not->toBe('Tipos de visita');
});

// ── 7. Requisição HTTP real: 200 + tabsGroup com as 8 entradas ─────────────

it('GET panel.setting.skintypes.index retorna 200 e o prop tabsGroup vem populado com as 8 entradas', function () {
    $response = $this->actingAs($this->adminUser)
        ->withSession(panelSession($this->adminEntityUser))
        ->get(route('panel.setting.skintypes.index'));

    $response->assertOk();
    // NOTA: não usamos `->component(...)` aqui — config/inertia.php aponta
    // `pages.paths` para `resource_path('js/pages')` (minúsculo) enquanto o
    // diretório real é `resources/js/Pages` (maiúsculo); em filesystem
    // case-sensitive isso faz QUALQUER assertInertia()->component(...) falhar
    // com "component file does not exist", mesmo pra páginas que existem e
    // renderizam normalmente em produção (lá `ensure_pages_exist` é false).
    // Ver relato no report final do agente de testes — não é bug do
    // PanelNavigation, está fora do escopo desta tarefa.
    $response->assertInertia(fn ($page) => $page->has('tabsGroup', 8));

    $tabsGroup = $response->inertiaProps('tabsGroup');

    expect($tabsGroup)->toHaveCount(8);

    foreach ($tabsGroup as $tab) {
        expect($tab)->toHaveKeys(['url', 'label', 'active']);
        expect($tab['label'])->not->toBeEmpty();
        expect($tab['url'])->not->toBeEmpty();
    }

    // Exatamente uma aba ativa (a do próprio catálogo — skintypes).
    $activeTabs = collect($tabsGroup)->where('active', true);
    expect($activeTabs)->toHaveCount(1);
    expect($activeTabs->first()['url'])->toBe(route('panel.setting.skintypes.index'));
});
