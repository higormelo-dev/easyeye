<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Models\{Entity, TermVersion, User, UserTermAcceptance};
use App\Services\TermsService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Reaceite dos documentos legais (lacuna LGPD G21): o gate `terms.accepted` do painel da clínica manda para
 * /accept-terms enquanto houver versão vigente não aceita, e a tela grava o aceite das versões exibidas.
 */
function reacceptVersion(string $type, string $version = '1.0'): TermVersion
{
    return TermVersion::query()->create([
        'type'           => $type,
        'version'        => $version,
        'content'        => "Texto de {$type} v{$version}",
        'effective_from' => now()->subDay()->toDateString(),
        'active'         => true,
    ]);
}

beforeEach(function () {
    $this->entity     = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->user       = User::factory()->create();
    $this->entityUser = createEntityUser($this->entity, $this->user, ClientRule::Admin->value, isOwner: true);
    $this->terms      = reacceptVersion('terms_of_service');
    $this->privacy    = reacceptVersion('privacy_policy');
});

it('manda para a tela de aceite quem não aceitou a versão vigente e guarda a URL pedida', function () {
    $this->actingAs($this->user)->withSession(panelSession($this->entityUser))
        ->get(route('panel.dashboard'))
        ->assertRedirect(route('terms.accept'));

    expect(session('url.intended'))->toBe(route('panel.dashboard'));
});

it('responde 403 em JSON quando a chamada é de API', function () {
    $this->actingAs($this->user)->withSession(panelSession($this->entityUser))
        ->getJson(route('panel.dashboard'))
        ->assertForbidden()
        ->assertJsonPath('message', __('auth.terms_reaccept.required_json'));
});

it('mostra só as versões pendentes', function () {
    app(TermsService::class)->accept($this->user, $this->terms);

    $this->actingAs($this->user)->withSession(panelSession($this->entityUser))
        ->get(route('terms.accept'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Auth/AcceptTerms')
            ->has('documents', 1)
            ->where('documents.0.version_id', (string) $this->privacy->id));
});

it('grava o aceite das versões exibidas e libera o painel', function () {
    $this->actingAs($this->user)->withSession(panelSession($this->entityUser) + ['url.intended' => route('panel.dashboard')])
        ->post(route('terms.accept.store'), [
            'accepted'    => '1',
            'version_ids' => [$this->terms->id, $this->privacy->id],
        ])
        ->assertRedirect(route('panel.dashboard'));

    expect(UserTermAcceptance::query()->where('user_id', $this->user->id)->pluck('term_version_id')->sort()->values()->all())
        ->toBe(collect([$this->terms->id, $this->privacy->id])->sort()->values()->all())
        ->and(app(TermsService::class)->hasAcceptedAll($this->user))->toBeTrue();

    $this->actingAs($this->user)->withSession(panelSession($this->entityUser))
        ->get(route('panel.dashboard'))
        ->assertOk();
});

it('sem marcar a caixa não grava nada', function () {
    $this->actingAs($this->user)->withSession(panelSession($this->entityUser))
        ->from(route('terms.accept'))
        ->post(route('terms.accept.store'), ['version_ids' => [$this->terms->id, $this->privacy->id]])
        ->assertRedirect(route('terms.accept'))
        ->assertSessionHasErrors(['accepted' => __('auth.terms_reaccept.required')]);

    expect(UserTermAcceptance::query()->count())->toBe(0);
});

it('versão publicada enquanto a pessoa lia: recusa e não grava nada', function () {
    $this->travel(1)->day();
    $v2 = app(TermsService::class)->publish('terms_of_service', '2.0', 'Texto novo', now()->toDateString(), $this->user);

    $this->actingAs($this->user)->withSession(panelSession($this->entityUser))
        ->from(route('terms.accept'))
        ->post(route('terms.accept.store'), [
            'accepted'    => '1',
            'version_ids' => [$this->terms->id, $this->privacy->id], // a tela mostrava a 1.0
        ])
        ->assertSessionHasErrors(['accepted' => __('auth.terms_reaccept.changed')]);

    expect(UserTermAcceptance::query()->count())->toBe(0)
        ->and($v2->exists)->toBeTrue();
});

it('versão nova publicada depois do aceite pede reaceite', function () {
    $service = app(TermsService::class);
    $service->accept($this->user, $this->terms);
    $service->accept($this->user, $this->privacy);

    $this->travel(1)->day();
    $service->publish('privacy_policy', '1.1', 'Política nova', now()->toDateString(), $this->user);

    $this->actingAs($this->user)->withSession(panelSession($this->entityUser))
        ->get(route('panel.dashboard'))
        ->assertRedirect(route('terms.accept'));
});

it('na impersonação o gate não bloqueia e o operador não aceita em nome do usuário', function () {
    $admin   = User::factory()->create();
    $session = panelSession($this->entityUser) + [
        'impersonating' => ['entity_user_id' => $this->entityUser->id, 'original_user_id' => $admin->id],
    ];

    $this->actingAs($admin)->withSession($session)
        ->get(route('panel.dashboard'))
        ->assertOk();

    $this->actingAs($admin)->withSession($session)
        ->post(route('terms.accept.store'), [
            'accepted'    => '1',
            'version_ids' => [$this->terms->id, $this->privacy->id],
        ])
        ->assertForbidden();

    expect(UserTermAcceptance::query()->count())->toBe(0);
});

it('sem documento vigente não há o que aceitar', function () {
    TermVersion::query()->update(['active' => false]);

    $this->actingAs($this->user)->withSession(panelSession($this->entityUser))
        ->get(route('panel.dashboard'))
        ->assertOk();
});
