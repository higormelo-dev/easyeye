<?php

declare(strict_types=1);

/**
 * Manager → governança da versão pela porta HTTP: liberar, suspender, retomar, aposentar, retirar da
 * revisão. A regra está em ProtocolGovernanceServiceTest; aqui se prova quem pode, o que a tela recebe
 * (ações permitidas, quem fez o quê, a prova da validação) e como cada resposta chega.
 */

use App\Domains\ClinicalReview\Enums\AcceptanceDecision;
use App\Domains\ClinicalReview\Models\ClinicalGovernanceEvent;
use App\Domains\ClinicalReview\Services\ReviewAcceptanceService;
use App\Domains\Recall\Enums\ProtocolVersionState as State;
use App\Domains\Recall\Models\RecallProtocolVersion;
use App\Domains\Recall\Services\{ProtocolCatalogService, ProtocolGovernanceService};
use App\Domains\Recall\Support\ProposedProtocolDrafts;
use App\Models\{Entity, User};
use Illuminate\Support\Facades\{DB, Notification};
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Notification::fake();
    $this->saas  = Entity::factory()->create(['is_client' => false, 'active' => true]);
    $this->admin = User::factory()->create(['name' => 'Gestor Admin']);
    createEntityUser($this->saas, $this->admin, 'admin');
});

function rpgAsAdmin(?User $user = null): mixed
{
    return test()->actingAs($user ?? test()->admin)->withSession([
        'selected_entity_id'        => test()->saas->id,
        'selected_entity_is_client' => false,
        'selected_entity_user_rule' => 'admin',
    ]);
}

/** @return array<string, mixed> */
function rpgProps(TestResponse $response): array
{
    $props = [];
    $response->assertOk()->assertInertia(function ($page) use (&$props) {
        $props = $page->toArray()['props'];
    });

    return $props;
}

function rpgUrl(string $action, RecallProtocolVersion $version): string
{
    return route("manager.recall-protocols.versions.{$action}", [$version->protocol_id, $version->id]);
}

function rpgPage(RecallProtocolVersion $version): array
{
    return rpgProps(rpgAsAdmin()->get(route('manager.recall-protocols.versions.show', [$version->protocol_id, $version->id])));
}

function rpgSetState(RecallProtocolVersion $version, State $state): RecallProtocolVersion
{
    DB::table('recall_protocol_versions')->where('id', $version->id)->update(['state' => $state->value]);

    return $version->fresh();
}

const RPG_REASON = 'Motivo escrito com mais de vinte caracteres.';

// ── autorização ─────────────────────────────────────────────────────────────

it('visitante vai ao login e só o admin do SaaS age — a versão não muda para os outros', function (string $rule) {
    $version = crfValidatedVersion()['version'];

    foreach (['release', 'suspend', 'resume', 'retire', 'withdraw'] as $action) {
        $this->post(rpgUrl($action, $version), ['reason' => RPG_REASON])->assertRedirect(route('login'));
    }

    $user = User::factory()->create();
    createEntityUser($this->saas, $user, $rule);

    foreach (['release', 'suspend', 'resume', 'retire', 'withdraw'] as $action) {
        $this->actingAs($user)
            ->withSession(['selected_entity_id' => $this->saas->id, 'selected_entity_is_client' => false, 'selected_entity_user_rule' => $rule])
            ->postJson(rpgUrl($action, $version), ['reason' => RPG_REASON])
            ->assertForbidden();
    }

    expect($version->fresh()->state)->toBe(State::Validated);
})->with(['financial', 'support', 'user']);

it('as rotas de impacto têm teto, só aceitam uuid e a versão tem que ser DO protocolo da URL', function () {
    foreach (['release', 'suspend', 'resume', 'retire', 'withdraw'] as $action) {
        expect(app('router')->getRoutes()->getByName("manager.recall-protocols.versions.{$action}")->gatherMiddleware())->toContain('throttle:manager-destructive');
    }

    $one = crfValidatedVersion('glaucoma')['version'];
    $two = crfValidatedVersion('dmri')['version'];

    rpgAsAdmin()->post(route('manager.recall-protocols.versions.release', [$one->protocol_id, $two->id]))->assertNotFound();
    rpgAsAdmin()->post(route('manager.recall-protocols.versions.release', [(string) Str::uuid(), $one->id]))->assertNotFound();
    rpgAsAdmin()->post('/panel/manager/recall-protocols/x/versions/y/release')->assertNotFound();

    expect($one->fresh()->state)->toBe(State::Validated)->and($two->fresh()->state)->toBe(State::Validated);
});

// ── o que a tela recebe ─────────────────────────────────────────────────────

it('as ações permitidas seguem o estado da versão', function (State $state, array $allowed) {
    $version = rpgSetState(crfFrozenVersion(), $state);

    $actions = rpgPage($version)['version']['actions'];

    expect(array_keys(array_filter($actions)))->toEqualCanonicalizing($allowed);
})->with([
    'em revisão' => [State::InReview, ['retire', 'withdraw']],
    'validada'   => [State::Validated, ['release', 'retire', 'withdraw']],
    'liberada'   => [State::Released, ['suspend', 'retire']],
    'superada'   => [State::Superseded, ['suspend', 'retire']],
    'suspensa'   => [State::Suspended, ['resume', 'retire']],
    'recusada'   => [State::Declined, ['retire']],
    'retirada'   => [State::Withdrawn, ['retire']],
    'aposentada' => [State::Retired, []],
]);

it('rascunho não tem ação de governança', function () {
    $proposal = ProposedProtocolDrafts::all()['glaucoma'];
    $draft    = app(ProtocolCatalogService::class)->createProtocol('glaucoma', $proposal['translations'], $proposal['content'])->versions()->firstOrFail();

    expect(array_filter(rpgPage($draft)['version']['actions']))->toBe([]);
});

it('a aba Validação traz quem validou, quando e qual hash: decisão, declarado e aceite do CFM', function () {
    $validated = crfValidatedVersion('glaucoma', $this->admin);

    $invitation = rpgPage($validated['version'])['validation']['invitations'][0];

    expect($invitation)->toMatchArray([
        'reviewer'       => $validated['reviewer']->name,
        'status'         => 'accepted',
        'decision'       => 'approved',
        'decision_label' => 'Aprovo',
        'hash_matches'   => true,
        'is_validating'  => true,
    ])
        ->and($invitation['attestation'])->toMatchArray(['crm_uf' => 'SP', 'conflict_of_interest' => false, 'allow_identity_display' => true])
        ->and($invitation['attestation']['submission_hash'])->toStartWith('1:')
        ->and($invitation['acceptance'])->toMatchArray(['decision' => 'accepted', 'accepted_by' => 'GESTOR ADMIN', 'crm_checked' => true, 'rqe_checked' => true]);
});

it('versão sem convite: a lista da validação vem vazia', function () {
    $version = crfFrozenVersion();

    expect(rpgPage($version)['validation']['invitations'])->toBe([]);
});

it('convite sem aceite ainda: mostra o parecer e a falta da conferência', function () {
    $created = crfInvitation();
    crfSubmit($created);

    $invitation = rpgPage($created['versions'][0])['validation']['invitations'][0];

    expect($invitation['status'])->toBe('submitted')
        ->and($invitation['acceptance'])->toBeNull()
        ->and($invitation['is_validating'])->toBeFalse();
});

it('quem fez o quê aparece com o nome (congelou, liberou, suspendeu) e o prazo de revalidação com a situação', function () {
    $validated = crfValidatedVersion('glaucoma', $this->admin);
    app(ProtocolGovernanceService::class)->release($validated['version'], $this->admin);

    $marks = rpgPage($validated['version'])['version']['marks'];

    expect($marks['frozen_by'])->toBe('GESTOR ADMIN')
        ->and($marks['released_by'])->toBe('GESTOR ADMIN')
        ->and($marks['review_due_status'])->toBe('ok')
        ->and($marks['review_due_at'])->toBe(now()->addMonths(24)->toDateString());

    DB::table('recall_protocol_versions')->where('id', $validated['version']->id)->update(['review_due_at' => now()->addDays(30)->toDateString()]);
    expect(rpgPage($validated['version'])['version']['marks']['review_due_status'])->toBe('soon');

    DB::table('recall_protocol_versions')->where('id', $validated['version']->id)->update(['review_due_at' => now()->subDay()->toDateString()]);
    expect(rpgPage($validated['version'])['version']['marks']['review_due_status'])->toBe('overdue');

    // A lista de protocolos avisa da revalidação vencida.
    $glaucoma = collect(rpgProps(rpgAsAdmin()->get(route('manager.recall-protocols.index')))['protocols'])->firstWhere('key', 'glaucoma');

    expect($glaucoma['released']['review_overdue'])->toBeTrue();
});

it('suspensa: a tela recebe o motivo, quem suspendeu e de onde veio', function () {
    $released = app(ProtocolGovernanceService::class)->release(crfValidatedVersion('glaucoma', $this->admin)['version'], $this->admin);
    app(ProtocolGovernanceService::class)->suspend($released, 'Revisor apontou erro no intervalo da campimetria.', $this->admin);

    $marks = rpgPage($released)['version']['marks'];

    expect($marks['suspended_reason'])->toBe('Revisor apontou erro no intervalo da campimetria.')
        ->and($marks['suspended_by'])->toBe('GESTOR ADMIN')
        ->and($marks['suspended_from_label'])->toBe('Liberada');
});

it('o modal de liberar avisa qual versão passa a "Substituída" (só quando há outra liberada no MESMO protocolo)', function () {
    $governance = app(ProtocolGovernanceService::class);
    $first      = $governance->release(crfValidatedVersion('glaucoma', $this->admin)['version'], $this->admin);

    expect(rpgPage($first)['currentReleased'])->toBeNull()
        // Em outro protocolo não há "versão liberada" para aquele.
        ->and(rpgPage(crfFrozenVersion('dmri'))['currentReleased'])->toBeNull();

    $catalog = app(ProtocolCatalogService::class);
    $second  = $catalog->startRevision($first->protocol, $this->admin);

    $content                                    = $second->contentArray();
    $content['change_note']                     = 'Campimetria a cada 9 meses.';
    $content['tasks'][1]['interval']['default'] = 9;
    $catalog->saveDraft($second, $content, 0, $this->admin);
    $second  = $catalog->freeze($second->fresh(), $this->admin);
    $created = crfInvitation(null, [$second], null, 14, $this->admin);
    crfSubmit($created);
    app(ReviewAcceptanceService::class)->record($created['invitation']->fresh(), AcceptanceDecision::Accepted, true, true, null, $this->admin);

    expect(rpgPage($second->fresh())['currentReleased'])->toBe(['version' => 1]);

    rpgAsAdmin()->post(rpgUrl('release', $second->fresh()))->assertSessionHas('success');

    expect($first->fresh()->state)->toBe(State::Superseded)
        ->and($second->fresh()->state)->toBe(State::Released);
});

// ── liberar ─────────────────────────────────────────────────────────────────

it('liberar: a versão passa a released, avisa e grava o evento com o ator', function () {
    $validated = crfValidatedVersion('glaucoma', $this->admin)['version'];

    rpgAsAdmin()->post(rpgUrl('release', $validated))
        ->assertRedirect()
        ->assertSessionHas('success', __('manager_recall.flash.released'));

    expect($validated->fresh()->state)->toBe(State::Released)
        ->and(ClinicalGovernanceEvent::query()->where('subject_id', $validated->id)->where('event', 'released')->value('actor_user_id'))->toBe($this->admin->id);
});

it('liberar o que não está validada volta com aviso de erro e nada muda', function () {
    $version = crfFrozenVersion('glaucoma');

    rpgAsAdmin()->post(rpgUrl('release', $version))->assertSessionHas('error', __('manager_recall.errors.invalid_transition'));

    expect($version->fresh()->state)->toBe(State::InReview);
});

it('sem a prova completa não libera, e o aviso diz o porquê', function () {
    $validated = crfValidatedVersion('glaucoma', $this->admin)['version'];
    DB::table('recall_protocol_versions')->where('id', $validated->id)->update(['validated_acceptance_id' => null]);

    rpgAsAdmin()->post(rpgUrl('release', $validated))->assertSessionHas('error', __('manager_recall.errors.no_validation_evidence'));

    expect($validated->fresh()->state)->toBe(State::Validated);
});

// ── suspender / retomar / aposentar / retirar ───────────────────────────────

it('suspender exige o motivo (20+) e, com ele, tira a versão de uso', function () {
    $released = app(ProtocolGovernanceService::class)->release(crfValidatedVersion('glaucoma', $this->admin)['version'], $this->admin);

    rpgAsAdmin()->postJson(rpgUrl('suspend', $released), [])->assertJsonValidationErrors('reason');
    rpgAsAdmin()->postJson(rpgUrl('suspend', $released), ['reason' => 'curto demais'])->assertJsonValidationErrors('reason');
    expect($released->fresh()->state)->toBe(State::Released);

    rpgAsAdmin()->post(rpgUrl('suspend', $released), ['reason' => RPG_REASON])->assertSessionHas('success', __('manager_recall.flash.suspended'));

    expect($released->fresh()->state)->toBe(State::Suspended)
        ->and($released->fresh()->suspended_reason)->toBe(RPG_REASON);
});

it('retomar volta a versão suspensa; retomar uma que não está suspensa dá aviso', function () {
    $released = app(ProtocolGovernanceService::class)->release(crfValidatedVersion('glaucoma', $this->admin)['version'], $this->admin);

    rpgAsAdmin()->post(rpgUrl('resume', $released))->assertSessionHas('error', __('manager_recall.errors.invalid_transition'));

    app(ProtocolGovernanceService::class)->suspend($released, RPG_REASON, $this->admin);

    rpgAsAdmin()->post(rpgUrl('resume', $released))->assertSessionHas('success', __('manager_recall.flash.resumed'));

    expect($released->fresh()->state)->toBe(State::Released);
});

it('retomar não passa se o hash divergiu (o aviso cita a integridade)', function () {
    $released = app(ProtocolGovernanceService::class)->release(crfValidatedVersion('glaucoma', $this->admin)['version'], $this->admin);
    app(ProtocolGovernanceService::class)->suspend($released, RPG_REASON, $this->admin);
    crfTamperVersionContent($released);

    rpgAsAdmin()->post(rpgUrl('resume', $released))->assertSessionHas('error', __('manager_recall.errors.integrity_failed'));

    expect($released->fresh()->state)->toBe(State::Suspended);
});

it('aposentar exige o motivo (20+) e é definitivo', function () {
    $version = crfFrozenVersion('glaucoma');

    rpgAsAdmin()->postJson(rpgUrl('retire', $version), ['reason' => 'curto'])->assertJsonValidationErrors('reason');

    rpgAsAdmin()->post(rpgUrl('retire', $version), ['reason' => RPG_REASON])->assertSessionHas('success', __('manager_recall.flash.retired'));

    expect($version->fresh()->state)->toBe(State::Retired);

    rpgAsAdmin()->post(rpgUrl('retire', $version), ['reason' => RPG_REASON])->assertSessionHas('error', __('manager_recall.errors.invalid_transition'));
});

it('retirar da revisão exige motivo (10+) e revoga os convites pendentes', function () {
    $created = crfInvitation();

    rpgAsAdmin()->postJson(rpgUrl('withdraw', $created['versions'][0]), ['reason' => 'curto'])->assertJsonValidationErrors('reason');

    rpgAsAdmin()->post(rpgUrl('withdraw', $created['versions'][0]), ['reason' => 'Achamos um erro na tabela.'])->assertSessionHas('success', __('manager_recall.flash.withdrawn'));

    expect($created['versions'][0]->fresh()->state)->toBe(State::Withdrawn)
        ->and($created['invitation']->fresh()->status->value)->toBe('revoked');
});

it('motivo só espaços não vale (o servidor aparou antes de contar)', function () {
    $released = app(ProtocolGovernanceService::class)->release(crfValidatedVersion('glaucoma', $this->admin)['version'], $this->admin);

    rpgAsAdmin()->postJson(rpgUrl('suspend', $released), ['reason' => str_repeat(' ', 40)])->assertJsonValidationErrors('reason');

    expect($released->fresh()->state)->toBe(State::Released);
});
