<?php

declare(strict_types=1);

use App\Enums\{ClientRule, Permission as PermissionEnum};
use App\Models\{Entity, PermissionRecord, Role, User};
use Database\Seeders\PermissionsSeeder;
use Illuminate\Support\Arr;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Listagem de Perfis de acesso no padrão de Panel/Patients
 * (App\Http\Controllers\AccessControl\RolesController::index): paginação,
 * busca e ordenação server-side, textos por idioma e volta para a listagem
 * com os filtros depois de criar/editar/excluir.
 *
 * Antes: todos os perfis de uma vez, busca no navegador, textos fixos em
 * português e redirect para a listagem "limpa".
 */

beforeEach(function (): void {
    $this->seed(PermissionsSeeder::class);

    $this->entity          = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->admin           = User::factory()->create();
    $this->adminEntityUser = createEntityUser($this->entity, $this->admin, ClientRule::Admin->value);
});

function listingRole(Entity $entity, string $name, array $attrs = []): Role
{
    return Role::query()->create(['entity_id' => $entity->id, 'name' => $name, ...$attrs]);
}

function rolesIndex(array $query = []): string
{
    return route('panel.accesscontrol.roles.index', $query);
}

/** @return list<string> nomes dos perfis na página, na ordem exibida */
function listedRoleNames($response): array
{
    return collect($response->viewData('page')['props']['roles']['data'])->pluck('name')->all();
}

describe('listagem', function (): void {
    it('pagina 12 por página com o paginator completo e filtros normalizados', function (): void {
        foreach (range(1, 13) as $i) {
            listingRole($this->entity, sprintf('Perfil %02d', $i));
        }

        $this->actingAs($this->admin)->withSession(panelSession($this->adminEntityUser))
            ->get(rolesIndex())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Panel/AccessControl/Roles/Index')
                ->has('roles.data', 12)
                ->where('roles.total', 13)
                ->where('roles.last_page', 2)
                ->where('roles.data.0.name', 'Perfil 01')
                ->where('filters', ['search' => '', 'sort' => 'name', 'direction' => 'asc'])
                ->has('systemProfiles')
                ->has('availablePermissions')
                ->where('t.page_title', 'Perfis de acesso'));
    });

    it('busca por nome ou descrição, sem acento, só na clínica atual', function (): void {
        listingRole($this->entity, 'Recepção Avançada');
        listingRole($this->entity, 'Faturista', ['description' => 'Cuida da recepção de guias']);
        listingRole($this->entity, 'Estoque');

        $other = Entity::factory()->create(['is_client' => true, 'active' => true]);
        listingRole($other, 'Recepção de outra clínica');

        $response = $this->actingAs($this->admin)->withSession(panelSession($this->adminEntityUser))
            ->get(rolesIndex(['search' => 'recepcao']))
            ->assertOk();

        expect(listedRoleNames($response))->toBe(['Faturista', 'Recepção Avançada'])
            ->and($response->viewData('page')['props']['filters']['search'])->toBe('recepcao');
    });

    it('ordena por usuários e por permissões (contagens), desempatando pelo nome', function (): void {
        $keys = PermissionRecord::query()->pluck('id', 'key');

        $few  = listingRole($this->entity, 'B com poucos');
        $many = listingRole($this->entity, 'C com muitos');
        listingRole($this->entity, 'A sem nada');

        $many->permissions()->sync([$keys[PermissionEnum::SettingsManage->value], $keys[PermissionEnum::StockManage->value]]);
        $few->permissions()->sync([$keys[PermissionEnum::SettingsManage->value]]);

        foreach ([ClientRule::Secretary, ClientRule::Financial] as $rule) {
            createEntityUser($this->entity, User::factory()->create(), $rule->value)->roles()->attach($many->id);
        }
        createEntityUser($this->entity, User::factory()->create(), ClientRule::Secretary->value)->roles()->attach($few->id);

        $session = panelSession($this->adminEntityUser);

        $byUsers = $this->actingAs($this->admin)->withSession($session)
            ->get(rolesIndex(['sort' => 'users_count', 'direction' => 'desc']));
        expect(listedRoleNames($byUsers))->toBe(['C com muitos', 'B com poucos', 'A sem nada']);

        $byPermissions = $this->actingAs($this->admin)->withSession($session)
            ->get(rolesIndex(['sort' => 'permissions_count', 'direction' => 'asc']));
        expect(listedRoleNames($byPermissions))->toBe(['A sem nada', 'B com poucos', 'C com muitos'])
            ->and($byPermissions->viewData('page')['props']['roles']['data'][2]['users_count'])->toBe(2);
    });

    it('ordenação fora da whitelist ou em formato de array cai no padrão, sem 500', function (array $query): void {
        listingRole($this->entity, 'Zeta');
        listingRole($this->entity, 'Alfa');

        $response = $this->actingAs($this->admin)->withSession(panelSession($this->adminEntityUser))
            ->get(rolesIndex($query))
            ->assertOk();

        expect(listedRoleNames($response))->toBe(['Alfa', 'Zeta'])
            ->and($response->viewData('page')['props']['filters'])
            ->toMatchArray(['sort' => 'name', 'direction' => 'asc']);
    })->with([
        'coluna inexistente' => [['sort' => 'entity_id', 'direction' => 'desc; drop table roles']],
        'sort como array'    => [['sort' => ['name'], 'direction' => ['desc']]],
        'search como array'  => [['search' => ['x']]],
    ]);

    it('página além da última mostra a última página válida', function (): void {
        foreach (range(1, 13) as $i) {
            listingRole($this->entity, sprintf('Perfil %02d', $i));
        }

        $this->actingAs($this->admin)->withSession(panelSession($this->adminEntityUser))
            ->get(rolesIndex(['page' => 9]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('roles.current_page', 2)
                ->has('roles.data', 1)
                ->where('roles.data.0.name', 'Perfil 13'));
    });

    it('não-admin continua sem acesso à listagem, mesmo com busca e ordenação', function (): void {
        $secretary = User::factory()->create();
        $member    = createEntityUser($this->entity, $secretary, ClientRule::Secretary->value);

        $this->actingAs($secretary)->withSession(panelSession($member))
            ->get(rolesIndex(['search' => 'a', 'sort' => 'users_count']))
            ->assertForbidden();
    });
});

describe('volta para a listagem com os filtros', function (): void {
    it('criar, editar e excluir mantêm busca, ordenação e página (e só esses parâmetros)', function (): void {
        $role    = listingRole($this->entity, 'Perfil editável');
        $trash   = listingRole($this->entity, 'Perfil descartável');
        $from    = rolesIndex(['search' => 'perfil', 'sort' => 'users_count', 'direction' => 'desc', 'page' => '2', 'evil' => 'x']);
        $expects = rolesIndex(['search' => 'perfil', 'sort' => 'users_count', 'direction' => 'desc', 'page' => '2']);
        $session = panelSession($this->adminEntityUser);

        $this->actingAs($this->admin)->withSession($session)->from($from)
            ->post(route('panel.accesscontrol.roles.store'), ['name' => 'Perfil novo'])
            ->assertRedirect($expects)
            ->assertSessionHas('message', 'Perfil de acesso cadastrado com sucesso.');

        $this->actingAs($this->admin)->withSession($session)->from($from)
            ->put(route('panel.accesscontrol.roles.update', $role), ['name' => 'Perfil editado'])
            ->assertRedirect($expects)
            ->assertSessionHas('message', 'Perfil de acesso alterado com sucesso.');

        $this->actingAs($this->admin)->withSession($session)->from($from)
            ->delete(route('panel.accesscontrol.roles.destroy', $trash))
            ->assertRedirect($expects)
            ->assertSessionHas('message', 'Perfil de acesso excluído com sucesso.');
    });

    it('Referer de outro site nunca vira destino: volta para a NOSSA listagem (sem open redirect)', function (): void {
        $response = $this->actingAs($this->admin)->withSession(panelSession($this->adminEntityUser))
            ->from('https://evil.example/panel/accesscontrol/roles?search=x&next=https://evil.example')
            ->post(route('panel.accesscontrol.roles.store'), ['name' => 'Perfil novo']);

        // Só o path é comparado; a URL é remontada a partir da rota nossa,
        // levando apenas parâmetros permitidos.
        $response->assertRedirect(rolesIndex(['search' => 'x']));
        expect($response->headers->get('Location'))->not->toContain('evil.example');
    });

    it('vindo de outra tela, volta para a listagem limpa', function (): void {
        $this->actingAs($this->admin)->withSession(panelSession($this->adminEntityUser))
            ->from(route('panel.accesscontrol.users.index', ['search' => 'x']))
            ->post(route('panel.accesscontrol.roles.store'), ['name' => 'Perfil novo'])
            ->assertRedirect(rolesIndex());
    });

    it('pedido JSON continua recebendo JSON (sem redirect)', function (): void {
        $this->actingAs($this->admin)->withSession(panelSession($this->adminEntityUser))
            ->from(rolesIndex(['search' => 'x']))
            ->postJson(route('panel.accesscontrol.roles.store'), ['name' => 'Via API'])
            ->assertOk()
            ->assertJsonPath('message', 'Perfil de acesso cadastrado com sucesso.')
            ->assertJsonPath('data.name', 'Via API');
    });
});

describe('idioma', function (): void {
    it('em inglês: textos da tela, breadcrumb e rótulos das permissões traduzidos', function (): void {
        $keys = PermissionRecord::query()->pluck('id', 'key');
        listingRole($this->entity, 'Billing')->permissions()->sync([$keys[PermissionEnum::FinancialView->value]]);

        $response = $this->actingAs($this->admin)
            ->withSession([...panelSession($this->adminEntityUser), 'locale' => 'en'])
            ->get(rolesIndex())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('t.page_title', 'Access profiles')
                ->where('breadcrumbs.2.label', 'Access profiles')
                ->where('roles.data.0.permissions.0.label', 'View financials')
                ->where('roles.data.0.permissions.0.group', 'Financial'));

        $groups = collect($response->viewData('page')['props']['availablePermissions']);
        $labels = $groups->pluck('items')->flatten(1)->pluck('label', 'key');

        expect($groups->pluck('group')->all())->toContain('Settings', 'Users', 'Financial', 'Patients', 'Stock')
            ->and($labels[PermissionEnum::StockManage->value])->toBe('Manage stock')
            ->and($labels)->toHaveCount(count(PermissionEnum::cases()));
    });

    it('em inglês: mensagens de retorno e de validação traduzidas', function (): void {
        listingRole($this->entity, 'Reception');
        $session = [...panelSession($this->adminEntityUser), 'locale' => 'en'];

        $this->actingAs($this->admin)->withSession($session)
            ->postJson(route('panel.accesscontrol.roles.store'), ['name' => 'Reception'])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'A profile with this name already exists in this clinic.');

        $this->actingAs($this->admin)->withSession($session)
            ->postJson(route('panel.accesscontrol.roles.store'), ['name' => 'Front desk'])
            ->assertOk()
            ->assertJsonPath('message', 'Access profile created successfully.');
    });

    it('data de cadastro sai em ISO 8601 (a tela formata no idioma do usuário)', function (): void {
        $this->travelTo(now()->setDate(2026, 9, 27)->setTime(14, 30));
        listingRole($this->entity, 'Datado');

        $response = $this->actingAs($this->admin)->withSession(panelSession($this->adminEntityUser))
            ->get(rolesIndex());

        expect($response->viewData('page')['props']['roles']['data'][0]['created_at'])
            ->toStartWith('2026-09-27T14:30:00');
    });

    it('lang/pt_BR e lang/en de access_control_roles têm as mesmas chaves', function (): void {
        $pt = array_keys(Arr::dot(require lang_path('pt_BR/access_control_roles.php')));
        $en = array_keys(Arr::dot(require lang_path('en/access_control_roles.php')));

        sort($pt);
        sort($en);

        expect($en)->toBe($pt);
    });

    it('todo case de Permission tem rótulo e grupo traduzidos nos dois idiomas', function (string $locale): void {
        $texts = require lang_path("{$locale}/access_control_roles.php");

        // array_key_exists, não toHaveKey: o valor tem ponto ('settings.manage')
        // e o toHaveKey do Pest o leria como caminho aninhado.
        foreach (PermissionEnum::cases() as $permission) {
            expect(array_key_exists($permission->value, $texts['permission_labels']))->toBeTrue()
                ->and(array_key_exists($permission->value, $texts['permission_groups']))->toBeTrue();
        }
    })->with(['pt_BR', 'en']);
});
