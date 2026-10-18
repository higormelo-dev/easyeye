<?php

declare(strict_types=1);

use App\Enums\{ClientRule, SaasRule};
use App\Models\{Entity, User};
use App\Support\Changelog;

/**
 * Página Novidades (/panel/novidades): conteúdo de resources/changelog no idioma
 * da sessão, item no menu de todos os perfis da clínica com badge "novo" até o
 * usuário abrir a página (users.changelog_seen_version), POST que marca como visto.
 */
beforeEach(function () {
    $this->entity     = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->user       = User::factory()->create();
    $this->entityUser = createEntityUser($this->entity, $this->user, ClientRule::Admin->value);
});

function newsAs(User $user, $entityUser, array $session = [])
{
    return test()->actingAs(User::find($user->id))->withSession(panelSession($entityUser) + $session);
}

/** Item `news` do menu como o front recebe. */
function newsNavItem($response): ?array
{
    $nav = $response->viewData('page')['props']['nav'] ?? [];

    return collect($nav)->firstWhere('key', 'news');
}

describe('GET /panel/novidades', function () {
    it('abre a página com versão atual e HTML renderizado (títulos e listas reais)', function () {
        newsAs($this->user, $this->entityUser, ['locale' => 'pt_BR'])
            ->get(route('panel.news.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Panel/News')
                ->where('version', Changelog::latestVersion())
                ->where('seenUrl', route('panel.news.seen'))
                ->where('html', fn ($html) => str_contains((string) $html, '<h2') && str_contains((string) $html, '<ul>') && str_contains((string) $html, 'Contrato com vigência'))
                ->where('t.title', 'Novidades')
                ->has('breadcrumbs', 2));
    });

    it('entrega o conteúdo em inglês quando o idioma da sessão é en', function () {
        newsAs($this->user, $this->entityUser, ['locale' => 'en'])
            ->get(route('panel.news.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('version', Changelog::latestVersion())
                ->where('html', fn ($html) => str_contains((string) $html, 'Contract validity') && ! str_contains((string) $html, 'Contrato com vigência'))
                ->where('t.title', "What's new"));
    });

    it('abre para todos os perfis da clínica', function () {
        foreach ([ClientRule::Doctor, ClientRule::Secretary, ClientRule::Financial, ClientRule::User] as $rule) {
            $user   = User::factory()->create();
            $member = createEntityUser($this->entity, $user, $rule->value);

            newsAs($user, $member)->get(route('panel.news.index'))->assertOk();
        }
    });

    it('não escreve nada: o GET não marca como visto', function () {
        newsAs($this->user, $this->entityUser)->get(route('panel.news.index'))->assertOk();

        expect($this->user->fresh()->changelog_seen_version)->toBeNull();
    });

    it('visitante é mandado para o login', function () {
        $this->get(route('panel.news.index'))->assertRedirect(route('login'));
    });
});

describe('menu e badge', function () {
    it('o item Novidades existe para todos os perfis, com badge enquanto não foi visto', function () {
        foreach ([ClientRule::Admin, ClientRule::Doctor, ClientRule::Secretary, ClientRule::Financial, ClientRule::User] as $rule) {
            $user   = User::factory()->create();
            $member = createEntityUser($this->entity, $user, $rule->value);

            $item = newsNavItem(newsAs($user, $member, ['locale' => 'pt_BR'])->get(route('panel.dashboard'))->assertOk());

            expect($item)->not->toBeNull("perfil {$rule->value}")
                ->and($item['route'])->toBe('panel.news.index')
                ->and($item['label'])->toBe('Novidades')
                ->and($item['badge'])->toBe('novo');
        }
    });

    it('o badge e o rótulo seguem o idioma', function () {
        $item = newsNavItem(newsAs($this->user, $this->entityUser, ['locale' => 'en'])->get(route('panel.dashboard'))->assertOk());

        expect($item['label'])->toBe("What's new")->and($item['badge'])->toBe('new');
    });

    it('sem badge quando o usuário já viu a versão atual', function () {
        $this->user->forceFill(['changelog_seen_version' => Changelog::latestVersion()])->save();

        $item = newsAs($this->user, $this->entityUser)->get(route('panel.dashboard'))->assertOk();

        expect(newsNavItem($item)['badge'])->toBeNull();
    });

    it('a versão vista é a anterior: o badge volta', function () {
        $this->user->forceFill(['changelog_seen_version' => '0.0.1'])->save();

        expect(newsNavItem(newsAs($this->user, $this->entityUser)->get(route('panel.dashboard'))->assertOk())['badge'])->toBe('novo');
    });

    it('o Manager não tem o item Novidades', function () {
        $saas  = Entity::factory()->create(['is_client' => false, 'active' => true]);
        $admin = User::factory()->create();
        createEntityUser($saas, $admin, SaasRule::Admin->value);

        $response = $this->actingAs($admin)
            ->withSession(['selected_entity_id' => $saas->id, 'selected_entity_is_client' => false, 'selected_entity_user_rule' => 'admin'])
            ->get(route('manager.dashboard'))
            ->assertOk();

        expect(newsNavItem($response))->toBeNull();
    });
});

describe('POST /panel/novidades/visto', function () {
    it('grava a versão atual no usuário e o menu seguinte sai sem badge', function () {
        newsAs($this->user, $this->entityUser)
            ->from(route('panel.news.index'))
            ->post(route('panel.news.seen'))
            ->assertRedirect(route('panel.news.index'));

        expect($this->user->fresh()->changelog_seen_version)->toBe(Changelog::latestVersion());

        expect(newsNavItem(newsAs($this->user, $this->entityUser)->get(route('panel.dashboard'))->assertOk())['badge'])->toBeNull();
    });

    it('é por usuário: marcar para um não apaga o badge do outro', function () {
        $other       = User::factory()->create();
        $otherMember = createEntityUser($this->entity, $other, ClientRule::Secretary->value);

        newsAs($this->user, $this->entityUser)->post(route('panel.news.seen'))->assertRedirect();

        expect($other->fresh()->changelog_seen_version)->toBeNull()
            ->and(newsNavItem(newsAs($other, $otherMember)->get(route('panel.dashboard'))->assertOk())['badge'])->toBe('novo');
    });

    it('repetir o POST é inofensivo', function () {
        newsAs($this->user, $this->entityUser)->post(route('panel.news.seen'))->assertRedirect();
        newsAs($this->user, $this->entityUser)->post(route('panel.news.seen'))->assertRedirect();

        expect($this->user->fresh()->changelog_seen_version)->toBe(Changelog::latestVersion());
    });

    it('visitante não consegue marcar', function () {
        $this->post(route('panel.news.seen'))->assertRedirect(route('login'));
    });

    it('o cliente não escolhe a versão: o corpo da requisição é ignorado', function () {
        newsAs($this->user, $this->entityUser)
            ->post(route('panel.news.seen'), ['changelog_seen_version' => '9.9.9'])
            ->assertRedirect();

        expect($this->user->fresh()->changelog_seen_version)->toBe(Changelog::latestVersion());
    });
});

describe('Changelog::hasUnseen', function () {
    it('visitante/sem usuário conta como não visto; versão igual, como visto', function () {
        $user = User::factory()->create();

        expect(Changelog::hasUnseen(null))->toBeTrue()
            ->and(Changelog::hasUnseen($user))->toBeTrue();

        $user->forceFill(['changelog_seen_version' => Changelog::latestVersion()]);

        expect(Changelog::hasUnseen($user))->toBeFalse();
    });
});
