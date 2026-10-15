<?php

declare(strict_types=1);

/**
 * Manager → o aceite pela porta HTTP: quem pode, o que a tela recebe (o declarado, o cadastro, as
 * divergências, as decisões com o texto do Revisor) e como cada resposta ao formulário chega
 * (erro por campo × aviso). A regra em si está em ReviewAcceptanceServiceTest.
 */

use App\Domains\ClinicalReview\Enums\ReviewInvitationStatus as Status;
use App\Domains\ClinicalReview\Models\{ClinicalGovernanceEvent, ClinicalReviewAcceptance};
use App\Domains\Recall\Enums\ProtocolVersionState as State;
use App\Models\{Entity, User};
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Notification::fake();
    $this->saas  = Entity::factory()->create(['is_client' => false, 'active' => true]);
    $this->admin = User::factory()->create(['name' => 'Conferente Admin']);
    createEntityUser($this->saas, $this->admin, 'admin');
});

function raaAsAdmin(?User $user = null): mixed
{
    return test()->actingAs($user ?? test()->admin)->withSession([
        'selected_entity_id'        => test()->saas->id,
        'selected_entity_is_client' => false,
        'selected_entity_user_rule' => 'admin',
    ]);
}

/** @return array<string, mixed> */
function raaProps(TestResponse $response): array
{
    $props = [];
    $response->assertOk()->assertInertia(function ($page) use (&$props) {
        $props = $page->toArray()['props'];
    });

    return $props;
}

/** Convite respondido, pronto para a conferência. */
function raaSubmitted(array $keys = ['glaucoma'], array $attestation = [], string $decision = 'approved', ?string $comment = null): array
{
    $created = crfInvitation(null, array_map(static fn (string $key) => crfFrozenVersion($key), $keys));
    crfSubmit($created, $decision, $comment, $attestation);

    return $created + ['invitation' => $created['invitation']->fresh()];
}

function raaPost(array $created, array $data): TestResponse
{
    return raaAsAdmin()->post(route('manager.clinical-reviews.acceptance', $created['invitation']->id), $data);
}

const RAA_OK = ['decision' => 'accepted', 'crm_checked' => true, 'rqe_checked' => true];

// ── autorização ─────────────────────────────────────────────────────────────

it('visitante vai ao login e quem não é admin do SaaS recebe 403 — nada é aceito', function (string $rule) {
    $created = raaSubmitted();
    $url     = route('manager.clinical-reviews.acceptance', $created['invitation']->id);

    $this->post($url, RAA_OK)->assertRedirect(route('login'));

    $user = User::factory()->create();
    createEntityUser($this->saas, $user, $rule);

    $this->actingAs($user)
        ->withSession(['selected_entity_id' => $this->saas->id, 'selected_entity_is_client' => false, 'selected_entity_user_rule' => $rule])
        ->postJson($url, RAA_OK)
        ->assertForbidden();

    expect(ClinicalReviewAcceptance::query()->count())->toBe(0)
        ->and($created['invitation']->fresh()->status)->toBe(Status::Submitted);
})->with(['financial', 'support', 'user']);

it('a rota tem o teto das ações de impacto e só aceita uuid', function () {
    $route = app('router')->getRoutes()->getByName('manager.clinical-reviews.acceptance');

    expect($route->gatherMiddleware())->toContain('throttle:manager-destructive');

    raaAsAdmin()->post('/panel/manager/clinical-reviews/nao-e-uuid/acceptance', RAA_OK)->assertNotFound();
    raaAsAdmin()->post(route('manager.clinical-reviews.acceptance', (string) Str::uuid()), RAA_OK)->assertNotFound();
});

// ── o que a tela recebe ─────────────────────────────────────────────────────

it('a linha do convite respondido traz o declarado, o cadastro, as decisões com o texto do Revisor e can_accept', function () {
    $created = raaSubmitted(attestation: ['has_conflict' => true, 'conflict_description' => 'Palestrante de um fabricante.', 'allow_identity_display' => false], decision: 'changes_requested', comment: 'Reduzir a campimetria <b>para 9 meses</b>.');

    $row = raaProps(raaAsAdmin()->get(route('manager.clinical-reviews.index')))['invitations'][0];

    expect($row['can_accept'])->toBeTrue()
        ->and($row['acceptance'])->toBeNull()
        ->and($row['attestation'])->toMatchArray([
            'name'                   => $created['reviewer']->name,
            'crm_number'             => $created['reviewer']->crm_number,
            'crm_uf'                 => 'SP',
            'rqe'                    => $created['reviewer']->rqe,
            'conflict_of_interest'   => true,
            'conflict_description'   => 'Palestrante de um fabricante.',
            'allow_identity_display' => false,
            'identity_matches'       => true,
            'identity_mismatch'      => [],
            'ip'                     => '203.0.113.7',
            'user_agent'             => 'Mozilla/5.0 (teste)',
        ])
        ->and($row['attestation']['submission_hash'])->toStartWith('1:')
        ->and($row['attestation']['registered'])->toMatchArray(['name' => $created['reviewer']->name, 'crm_number' => $created['reviewer']->crm_number])
        ->and($row['items'][0])->toMatchArray(['decision' => 'changes_requested', 'decision_label' => 'Aprovo com ajustes', 'key' => 'glaucoma'])
        // O texto vem cru (a tela o mostra como TEXTO): nada de HTML interpretado no servidor.
        ->and($row['items'][0]['comment'])->toBe('Reduzir a campimetria <b>para 9 meses</b>.')
        ->and($row['items'][0]['protocol_id'])->toBe((string) $created['versions'][0]->protocol_id);
});

it('divergência entre o declarado e o cadastro vem com os campos', function () {
    raaSubmitted(attestation: ['crm_number' => '777', 'email' => 'outro@exemplo.test']);

    $attestation = raaProps(raaAsAdmin()->get(route('manager.clinical-reviews.index')))['invitations'][0]['attestation'];

    expect($attestation['identity_matches'])->toBeFalse()
        ->and($attestation['identity_mismatch'])->toEqualCanonicalizing(['crm_number', 'email']);
});

it('convite pendente não tem atestado nem can_accept', function () {
    crfInvitation();

    $row = raaProps(raaAsAdmin()->get(route('manager.clinical-reviews.index')))['invitations'][0];

    expect($row['attestation'])->toBeNull()->and($row['acceptance'])->toBeNull()->and($row['can_accept'])->toBeFalse();
});

// ── aceitar / recusar ───────────────────────────────────────────────────────

it('aceitar valida as versões aprovadas, grava o aceite com quem conferiu e avisa', function () {
    $created = raaSubmitted(['glaucoma', 'dmri']);

    raaPost($created, RAA_OK + ['note' => '  Conferido no portal.  '])
        ->assertRedirect()
        ->assertSessionHas('success', __('manager_reviews.flash.accepted'));

    $acceptance = ClinicalReviewAcceptance::query()->sole();

    expect($created['invitation']->fresh()->status)->toBe(Status::Accepted)
        ->and($created['versions'][0]->fresh()->state)->toBe(State::Validated)
        ->and($created['versions'][1]->fresh()->state)->toBe(State::Validated)
        ->and($acceptance->accepted_by)->toBe($this->admin->id)
        ->and($acceptance->note)->toBe('Conferido no portal.')
        ->and(ClinicalGovernanceEvent::query()->where('event', 'validated')->where('actor_user_id', $this->admin->id)->count())->toBe(2);

    // A tela passa a mostrar o resultado e fecha a ação.
    $row = raaProps(raaAsAdmin()->get(route('manager.clinical-reviews.index')))['invitations'][0];

    expect($row['can_accept'])->toBeFalse()
        ->and($row['acceptance'])->toMatchArray(['decision' => 'accepted', 'accepted_by' => 'CONFERENTE ADMIN', 'crm_checked' => true, 'rqe_checked' => true, 'method' => 'cfm_portal']);
});

it('recusar mantém as versões em revisão e exige o motivo', function () {
    $created = raaSubmitted();

    raaPost($created, ['decision' => 'refused', 'crm_checked' => false, 'rqe_checked' => false])
        ->assertSessionHasErrors(['note' => __('manager_reviews.acceptance.errors.note_required')]);

    raaPost($created, ['decision' => 'refused', 'crm_checked' => false, 'rqe_checked' => false, 'note' => 'O CRM informado não consta no portal.'])
        ->assertSessionHas('success', __('manager_reviews.flash.refused'));

    expect($created['invitation']->fresh()->status)->toBe(Status::Refused)
        ->and($created['versions'][0]->fresh()->state)->toBe(State::InReview);
});

it('aceitar sem as duas conferências volta com erro NO CAMPO e nada muda', function (array $checks) {
    $created = raaSubmitted();

    raaPost($created, ['decision' => 'accepted'] + $checks)
        ->assertSessionHasErrors(['crm_checked' => __('manager_reviews.acceptance.errors.checks_required')]);

    expect(ClinicalReviewAcceptance::query()->count())->toBe(0)
        ->and($created['invitation']->fresh()->status)->toBe(Status::Submitted);
})->with([
    'sem CRM' => [['crm_checked' => false, 'rqe_checked' => true]],
    'sem RQE' => [['crm_checked' => true, 'rqe_checked' => false]],
    'nenhuma' => [['crm_checked' => false, 'rqe_checked' => false]],
]);

it('divergência declarado × cadastro: aceitar pede a nota, e com ela vale', function () {
    $created = raaSubmitted(attestation: ['rqe' => '12']);

    raaPost($created, RAA_OK)->assertSessionHasErrors('note');

    raaPost($created, RAA_OK + ['note' => 'Cadastro estava errado: RQE conferido como declarado.'])->assertSessionHas('success');

    expect($created['versions'][0]->fresh()->state)->toBe(State::Validated);
});

it('validação da forma: decisão desconhecida, conferência que não é booleana e nota gigante', function () {
    $created = raaSubmitted();
    $url     = route('manager.clinical-reviews.acceptance', $created['invitation']->id);

    raaAsAdmin()->postJson($url, [])->assertJsonValidationErrors(['decision', 'crm_checked', 'rqe_checked']);
    raaAsAdmin()->postJson($url, ['decision' => 'talvez', 'crm_checked' => 'sim', 'rqe_checked' => true])->assertJsonValidationErrors(['decision', 'crm_checked']);
    raaAsAdmin()->postJson($url, RAA_OK + ['note' => str_repeat('x', 1001)])->assertJsonValidationErrors('note');

    expect(ClinicalReviewAcceptance::query()->count())->toBe(0);
});

it('convite que não está aguardando conferência volta com AVISO, não erro de campo', function () {
    $pending = crfInvitation();

    raaPost($pending, RAA_OK)->assertSessionHas('error', __('manager_reviews.acceptance.errors.not_submitted'));

    $done = raaSubmitted(['dmri']);
    raaPost($done, RAA_OK)->assertSessionHas('success');
    raaPost($done + ['invitation' => $done['invitation']->fresh()], RAA_OK)->assertSessionHas('error', __('manager_reviews.acceptance.errors.not_submitted'));

    expect(ClinicalReviewAcceptance::query()->count())->toBe(1);
});

it('o aceite não passa pelo link público: a página do Revisor não tem como aceitar nada', function () {
    $created = raaSubmitted();

    $this->post(route('clinical-review.submit'), RAA_OK)->assertRedirect(route('clinical-review.unavailable'));
    $this->post('/revisao-clinica/acceptance', RAA_OK)->assertNotFound();

    expect(ClinicalReviewAcceptance::query()->count())->toBe(0);
});
