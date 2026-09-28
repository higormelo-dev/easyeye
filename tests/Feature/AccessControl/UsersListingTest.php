<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Models\{Entity, EntityUser, Role, User};
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Listagem de Usuários no padrão de Panel/Patients
 * (App\Http\Controllers\UsersController::index): paginação, busca e
 * ordenação server-side (tabela e cards no MESMO paginator), textos por
 * idioma, volta para a listagem com os filtros e restaurar via PATCH.
 *
 * Antes: cards num endpoint JSON à parte, `?sort[]=x` estourava 500,
 * mensagens fixas em português, redirect para a listagem "limpa" e
 * restaurar por GET (sem CSRF).
 */

beforeEach(function (): void {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->admin  = User::factory()->create(['name' => 'Ana Admin', 'email' => 'ana.admin@clinica.test']);
    // Admin logado é também o proprietário da clínica.
    $this->adminEntityUser = createEntityUser($this->entity, $this->admin, ClientRule::Admin->value, isOwner: true);
    $this->session         = panelSession($this->adminEntityUser);
});

function staffMember(Entity $entity, string $name, string $email, string $rule = 'secretary'): EntityUser
{
    return createEntityUser($entity, User::factory()->create(['name' => $name, 'email' => $email]), $rule);
}

function usersIndex(array $query = []): string
{
    return route('panel.accesscontrol.users.index', $query);
}

/** @return list<string> nomes na página, na ordem exibida (User grava o nome em maiúsculas) */
function listedUserNames($response): array
{
    return collect($response->viewData('page')['props']['users']['data'])->pluck('name')->all();
}

describe('listagem', function (): void {
    it('pagina 12 por página, sem médicos, com filtros normalizados e textos da tela', function (): void {
        foreach (range(1, 12) as $i) {
            staffMember($this->entity, sprintf('Staff %02d', $i), "staff{$i}@clinica.test");
        }
        staffMember($this->entity, 'Dra. Fora da Lista', 'medica@clinica.test', ClientRule::Doctor->value);

        $this->actingAs($this->admin)->withSession($this->session)
            ->get(usersIndex())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Panel/Users/Index')
                ->has('users.data', 12)
                ->where('users.total', 13) // 12 + o admin; a médica não entra
                ->where('users.last_page', 2)
                ->where('filters', ['search' => '', 'sort' => 'created_at', 'direction' => 'desc'])
                ->missing('total')
                ->where('t.page_title', 'Usuários')
                ->where('breadcrumbs.2.label', 'Usuários'));
    });

    it('busca por nome ou e-mail, sem acento, só na clínica atual', function (): void {
        staffMember($this->entity, 'José Recepção', 'jose@clinica.test');
        staffMember($this->entity, 'Maria Caixa', 'recepcao.maria@clinica.test');
        staffMember($this->entity, 'Paulo Estoque', 'paulo@clinica.test');

        $other = Entity::factory()->create(['is_client' => true, 'active' => true]);
        staffMember($other, 'Recepção Outra Clínica', 'outra@clinica.test');

        $response = $this->actingAs($this->admin)->withSession($this->session)
            ->get(usersIndex(['search' => 'recepcao', 'sort' => 'name', 'direction' => 'asc']))
            ->assertOk();

        expect(listedUserNames($response))->toBe(['JOSÉ RECEPÇÃO', 'MARIA CAIXA']);
    });

    it('ordena pela coluna pedida', function (): void {
        staffMember($this->entity, 'Zeca', 'zeca@clinica.test');
        staffMember($this->entity, 'Bruna', 'bruna@clinica.test');

        $response = $this->actingAs($this->admin)->withSession($this->session)
            ->get(usersIndex(['sort' => 'name', 'direction' => 'asc']));

        expect(listedUserNames($response))->toBe(['ANA ADMIN', 'BRUNA', 'ZECA']);
    });

    it('ordenação fora da whitelist ou em formato de array cai no padrão, sem 500', function (array $query): void {
        $response = $this->actingAs($this->admin)->withSession($this->session)
            ->get(usersIndex($query))
            ->assertOk();

        expect($response->viewData('page')['props']['filters'])
            ->toBe(['search' => '', 'sort' => 'created_at', 'direction' => 'desc']);
    })->with([
        'coluna inexistente' => [['sort' => 'users.password', 'direction' => 'desc; drop table users']],
        'sort como array'    => [['sort' => ['name'], 'direction' => ['asc']]],
        'search como array'  => [['search' => ['x']]],
    ]);

    it('página além da última mostra a última página válida', function (): void {
        foreach (range(1, 12) as $i) {
            staffMember($this->entity, sprintf('Staff %02d', $i), "staff{$i}@clinica.test");
        }

        $this->actingAs($this->admin)->withSession($this->session)
            ->get(usersIndex(['page' => 9]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('users.current_page', 2)
                ->has('users.data', 1));
    });

    it('linha traz perfis adicionais ativos, selos de proprietário/própria conta, removidos e data ISO', function (): void {
        $member = staffMember($this->entity, 'Bruno Caixa', 'bruno@clinica.test');
        $active = Role::query()->create(['entity_id' => $this->entity->id, 'name' => 'Caixa']);
        $gone   = Role::query()->create(['entity_id' => $this->entity->id, 'name' => 'Antigo']);
        $member->roles()->sync([$active->id, $gone->id]);
        $gone->delete(); // perfil excluído não conta

        $removed = staffMember($this->entity, 'Carla Removida', 'carla@clinica.test');
        $removed->delete();

        $rows = collect($this->actingAs($this->admin)->withSession($this->session)
            ->get(usersIndex(['sort' => 'name', 'direction' => 'asc']))
            ->viewData('page')['props']['users']['data'])->keyBy('name');

        expect($rows['BRUNO CAIXA'])->toMatchArray(['roles_count' => 1, 'is_owner' => false, 'is_self' => false, 'mode' => 'full'])
            ->and($rows['ANA ADMIN'])->toMatchArray(['is_owner' => true, 'is_self' => true])
            ->and($rows['CARLA REMOVIDA'])->toMatchArray(['deleted' => true, 'mode' => 'restore'])
            ->and($rows['BRUNO CAIXA']['created_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/');
    });

    it('não-admin continua sem acesso, mesmo com busca e ordenação', function (): void {
        $secretary = staffMember($this->entity, 'Sônia', 'sonia@clinica.test');

        $this->actingAs($secretary->user)->withSession(panelSession($secretary))
            ->get(usersIndex(['search' => 'a', 'sort' => 'name']))
            ->assertForbidden();
    });

    it('o endpoint JSON dos cards saiu: tabela e cards usam o paginator do index', function (): void {
        expect(fn () => route('panel.accesscontrol.users.cards'))->toThrow(InvalidArgumentException::class);

        $this->actingAs($this->admin)->withSession($this->session)
            ->getJson('/panel/accesscontrol/users/cards')
            ->assertNotFound();
    });
});

describe('ações voltam para a listagem com os filtros', function (): void {
    beforeEach(function (): void {
        $this->from    = usersIndex(['search' => 'staff', 'sort' => 'name', 'direction' => 'asc', 'page' => '2', 'evil' => 'x']);
        $this->expects = usersIndex(['search' => 'staff', 'sort' => 'name', 'direction' => 'asc', 'page' => '2']);
    });

    it('criar mantém os filtros e avisa', function (): void {
        // EntityUserRequest usa Password::uncompromised() (API externa).
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);

        $this->actingAs($this->admin)->withSession($this->session)->from($this->from)
            ->post(route('panel.accesscontrol.users.store'), [
                'name'                  => 'Staff Novo',
                'email'                 => 'staff.novo@clinica.test',
                'rule'                  => ClientRule::Secretary->value,
                'password'              => 'SenhaValida#2026x',
                'password_confirmation' => 'SenhaValida#2026x',
            ])
            ->assertRedirect($this->expects)
            ->assertSessionHas('message', 'Usuário cadastrado com sucesso.');
    });

    it('ativar/desativar, editar perfis, excluir e restaurar mantêm os filtros, com a mensagem certa', function (): void {
        $member = staffMember($this->entity, 'Staff Alvo', 'alvo@clinica.test');

        $this->actingAs($this->admin)->withSession($this->session)->from($this->from)
            ->put(route('panel.accesscontrol.users.update', $member->id), ['active' => false, 'type_method' => 'toggle'])
            ->assertRedirect($this->expects)
            ->assertSessionHas('message', 'Usuário desativado com sucesso.');
        expect($member->fresh()->active)->toBeFalse();

        $this->actingAs($this->admin)->withSession($this->session)->from($this->from)
            ->put(route('panel.accesscontrol.users.update', $member->id), ['active' => true, 'type_method' => 'toggle'])
            ->assertSessionHas('message', 'Usuário ativado com sucesso.');

        $this->actingAs($this->admin)->withSession($this->session)->from($this->from)
            ->patch(route('panel.accesscontrol.users.roles.update', $member->id), ['role_ids' => []])
            ->assertRedirect($this->expects)
            ->assertSessionHas('message', 'Usuário alterado com sucesso.');

        $this->actingAs($this->admin)->withSession($this->session)->from($this->from)
            ->delete(route('panel.accesscontrol.users.destroy', $member->id))
            ->assertRedirect($this->expects)
            ->assertSessionHas('message', 'Acesso do usuário removido com sucesso.');
        expect(EntityUser::withTrashed()->find($member->id)->trashed())->toBeTrue();

        $this->actingAs($this->admin)->withSession($this->session)->from($this->from)
            ->patch(route('panel.accesscontrol.users.restore', $member->id))
            ->assertRedirect($this->expects)
            ->assertSessionHas('message', 'Usuário restaurado com sucesso.');
        expect($member->fresh()->trashed())->toBeFalse();
    });

    it('Referer de outro site nunca vira destino (sem open redirect)', function (): void {
        $member = staffMember($this->entity, 'Staff Alvo', 'alvo@clinica.test');

        $response = $this->actingAs($this->admin)->withSession($this->session)
            ->from('https://evil.example/panel/accesscontrol/users?search=x')
            ->put(route('panel.accesscontrol.users.update', $member->id), ['active' => false, 'type_method' => 'toggle']);

        $response->assertRedirect(usersIndex(['search' => 'x']));
        expect($response->headers->get('Location'))->not->toContain('evil.example');
    });
});

describe('restaurar exige PATCH (CSRF)', function (): void {
    it('GET não restaura mais: 405 e o acesso continua removido', function (): void {
        $member = staffMember($this->entity, 'Ex Funcionário', 'ex@clinica.test');
        $member->delete();

        $this->actingAs($this->admin)->withSession($this->session)
            ->get("/panel/accesscontrol/users/{$member->id}/restore")
            ->assertStatus(405);

        expect(EntityUser::withTrashed()->find($member->id)->trashed())->toBeTrue();
    });

    it('PATCH de outra clínica não restaura: 404', function (): void {
        $other  = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $member = staffMember($other, 'De Outra Clínica', 'outra2@clinica.test');
        $member->delete();

        $this->actingAs($this->admin)->withSession($this->session)
            ->patchJson(route('panel.accesscontrol.users.restore', $member->id))
            ->assertNotFound();

        expect(EntityUser::withTrashed()->find($member->id)->trashed())->toBeTrue();
    });
});

describe('proteções continuam no backend', function (): void {
    it('proprietário não pode ser desativado e ninguém exclui a própria conta', function (): void {
        $otherAdmin = staffMember($this->entity, 'Beto Admin', 'beto@clinica.test', ClientRule::Admin->value);

        $this->actingAs($otherAdmin->user)->withSession(panelSession($otherAdmin))
            ->putJson(route('panel.accesscontrol.users.update', $this->adminEntityUser->id), ['active' => false, 'type_method' => 'toggle'])
            ->assertForbidden();

        $this->actingAs($otherAdmin->user)->withSession(panelSession($otherAdmin))
            ->deleteJson(route('panel.accesscontrol.users.destroy', $otherAdmin->id))
            ->assertForbidden();

        expect($this->adminEntityUser->fresh()->active)->toBeTrue()
            ->and($otherAdmin->fresh()->trashed())->toBeFalse();
    });
});

describe('idioma', function (): void {
    it('em inglês: textos da tela e mensagens de retorno traduzidos', function (): void {
        $member  = staffMember($this->entity, 'Staff Alvo', 'alvo@clinica.test');
        $session = [...$this->session, 'locale' => 'en'];

        $this->actingAs($this->admin)->withSession($session)
            ->get(usersIndex())
            ->assertInertia(fn (Assert $page) => $page
                ->where('t.page_title', 'Users')
                ->where('t.roles_link', 'Roles & Permissions')
                ->where('breadcrumbs.2.label', 'Users'));

        $this->actingAs($this->admin)->withSession($session)
            ->putJson(route('panel.accesscontrol.users.update', $member->id), ['active' => false, 'type_method' => 'toggle'])
            ->assertOk()
            ->assertJsonPath('message', 'User deactivated successfully.');
    });

    it('lang/pt_BR e lang/en de access_control têm as mesmas chaves', function (): void {
        $pt = array_keys(Arr::dot(require lang_path('pt_BR/access_control.php')));
        $en = array_keys(Arr::dot(require lang_path('en/access_control.php')));

        sort($pt);
        sort($en);

        expect($en)->toBe($pt);
    });
});
