<?php

declare(strict_types=1);

/**
 * Manager → Revisões clínicas: a porta HTTP do cadastro de Revisores e dos convites de
 * revisão. A regra (estados, travas, hash) está em ReviewInvitationService e é provada em
 * tests/Feature/ClinicalReview; aqui se prova autorização, o que a tela recebe (e o que
 * NUNCA recebe: link, token, PIN em claro, rascunho) e como cada ação responde.
 */

use App\Domains\ClinicalReview\Enums\ReviewInvitationStatus as Status;
use App\Domains\ClinicalReview\Models\{ClinicalGovernanceEvent, ClinicalReviewInvitation, ClinicalReviewer};
use App\Domains\ClinicalReview\Notifications\ClinicalReviewInvitationNotification;
use App\Domains\Recall\Enums\ProtocolVersionState;
use App\Domains\Recall\Models\{RecallProtocol, RecallProtocolVersion};
use App\Models\{Entity, User};
use App\Support\PanelNavigation;
use Database\Seeders\RecallProtocolDraftsSeeder;
use Illuminate\Support\Facades\{DB, Notification};
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->saas  = Entity::factory()->create(['is_client' => false, 'active' => true]);
    $this->admin = User::factory()->create(['name' => 'Admin Manager']);
    createEntityUser($this->saas, $this->admin, 'admin');

    $this->seed(RecallProtocolDraftsSeeder::class);
});

function crcAsAdmin(?User $user = null): mixed
{
    return test()->actingAs($user ?? test()->admin)->withSession([
        'selected_entity_id'        => test()->saas->id,
        'selected_entity_is_client' => false,
        'selected_entity_user_rule' => 'admin',
    ]);
}

/** @return array<string, mixed> */
function crcProps(TestResponse $response): array
{
    $props = [];
    $response->assertOk()->assertInertia(function ($page) use (&$props) {
        $props = $page->toArray()['props'];
    });

    return $props;
}

/** @return array<string, string> */
function crcReviewerData(array $overrides = []): array
{
    return array_merge([
        'name'       => 'Dra. Helena Prado',
        'email'      => 'helena.prado@exemplo.test',
        'crm_number' => '12.345',
        'crm_uf'     => 'SP',
        'rqe'        => '6789',
        'specialty'  => 'Oftalmologia',
        'phone'      => '(11) 91234-5678',
        'notes'      => 'Indicada pelo conselho consultivo.',
    ], $overrides);
}

// ── autorização ─────────────────────────────────────────────────────────────

it('visitante é mandado ao login em todas as rotas', function () {
    $reviewer = crfReviewer();
    $created  = crfInvitation($reviewer);
    $id       = $created['invitation']->id;

    foreach ([
        ['get', route('manager.clinical-reviews.index')],
        ['post', route('manager.clinical-reviewers.store')],
        ['put', route('manager.clinical-reviewers.update', $reviewer->id)],
        ['post', route('manager.clinical-reviewers.active', $reviewer->id)],
        ['post', route('manager.clinical-reviews.store')],
        ['post', route('manager.clinical-reviews.resend', $id)],
        ['post', route('manager.clinical-reviews.revoke', $id)],
    ] as [$method, $url]) {
        test()->{$method}($url)->assertRedirect(route('login'));
    }
});

it('só o admin do SaaS entra: financeiro, suporte, usuário comum e membro de clínica recebem 403 — e nada muda', function (string $rule) {
    Notification::fake();
    $user = User::factory()->create();
    createEntityUser($this->saas, $user, $rule);

    $reviewer = crfReviewer();
    $created  = crfInvitation($reviewer);
    $id       = $created['invitation']->id;

    foreach ([
        ['get', route('manager.clinical-reviews.index'), []],
        ['post', route('manager.clinical-reviewers.store'), crcReviewerData()],
        ['put', route('manager.clinical-reviewers.update', $reviewer->id), crcReviewerData()],
        ['post', route('manager.clinical-reviewers.active', $reviewer->id), ['active' => false]],
        ['post', route('manager.clinical-reviews.store'), ['reviewer_id' => $reviewer->id, 'version_ids' => [(string) $created['versions'][0]->id]]],
        ['post', route('manager.clinical-reviews.resend', $id), []],
        ['post', route('manager.clinical-reviews.revoke', $id), ['reason' => 'Motivo de teste suficiente.']],
    ] as [$method, $url, $data]) {
        $this->actingAs($user)
            ->withSession(['selected_entity_id' => $this->saas->id, 'selected_entity_is_client' => false, 'selected_entity_user_rule' => $rule])
            ->{$method . 'Json'}($url, $data)
            ->assertForbidden();
    }

    expect(ClinicalReviewer::query()->count())->toBe(1)
        ->and($reviewer->fresh()->active)->toBeTrue()
        ->and($created['invitation']->fresh()->status)->toBe(Status::Pending)
        ->and(ClinicalReviewInvitation::query()->count())->toBe(1);

    Notification::assertNothingSent();
})->with(['financial', 'support', 'user']);

it('usuário de clínica não acessa o Manager', function () {
    $clinic = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $doctor = User::factory()->create();
    createEntityUser($clinic, $doctor, 'doctor');

    $this->actingAs($doctor)
        ->withSession(['selected_entity_id' => $clinic->id, 'selected_entity_is_client' => true, 'selected_entity_user_rule' => 'doctor'])
        ->get(route('manager.clinical-reviews.index'))
        ->assertRedirect();
});

it('o menu do Manager tem a entrada Revisões clínicas (só para o admin do SaaS)', function () {
    $this->actingAs($this->admin)->withSession(['selected_entity_id' => $this->saas->id, 'selected_entity_is_client' => false, 'selected_entity_user_rule' => 'admin']);

    $flat = collect(PanelNavigation::build())->flatMap(fn ($section) => $section['items'] ?? [$section])->flatMap(fn ($item) => [$item, ...($item['children'] ?? [])]);

    expect($flat->pluck('route')->filter()->all())->toContain('manager.clinical-reviews.index');
});

// ── a tela ──────────────────────────────────────────────────────────────────

it('a tela traz Revisores, convites e só as versões congeladas, sem segredo algum', function () {
    $reviewer = crfReviewer(['name' => 'DRA. ANA PAULA SOUZA']);
    $created  = crfInvitation($reviewer, pin: 'ABCD2345');
    crfFrozenVersion('dmri');
    // Um rascunho de outro protocolo NÃO pode ser oferecido (não está congelado).

    $response = crcAsAdmin()->get(route('manager.clinical-reviews.index'));
    $props    = crcProps($response);
    $json     = (string) $response->getContent();

    expect(array_column($props['reviewers'], 'id'))->toBe([$reviewer->id])
        ->and($props['reviewers'][0])->toMatchArray(['name' => 'DRA. ANA PAULA SOUZA', 'crm_uf' => 'SP', 'active' => true, 'invitations_count' => 1])
        ->and($props['invitations'])->toHaveCount(1)
        ->and($props['invitations'][0])->toMatchArray([
            'id'           => $created['invitation']->id,
            'status'       => 'pending',
            'status_label' => 'Aguardando parecer',
            'pin_required' => true,
            'can_resend'   => true,
            'can_revoke'   => true,
            'is_expired'   => false,
            'open_count'   => 0,
        ])
        ->and($props['invitations'][0]['items'][0])->toMatchArray(['key' => 'glaucoma', 'title' => 'Glaucoma', 'version' => 1, 'decision' => null])
        ->and(array_column($props['reviewableVersions'], 'protocol_key'))->toBe(['glaucoma', 'dmri'])
        ->and($props['options']['default_expiry'])->toBe(14)
        ->and($props['options']['expiry_days'])->toBe([7, 14, 21, 30])
        ->and($props['issuedAccessCode'])->toBeNull()
        ->and($props['t']['title'])->toBe('Revisões clínicas')
        ->and($props['t']['status']['pending'])->toBe('Aguardando parecer')
        ->and($props['t']['decision']['approved'])->toBe('Aprovo');

    // Nada que dê acesso ao convite: token, link, hash do código, rascunho.
    foreach ([$created['token'], 'token_hash', 'access_code_hash', 'draft_payload', 'revisao-clinica/abrir', 'signature='] as $secret) {
        expect($json)->not->toContain($secret);
    }
});

it('versão que saiu de revisão não é mais oferecida para convite', function () {
    $version = crfFrozenVersion('glaucoma');

    expect(array_column(crcProps(crcAsAdmin()->get(route('manager.clinical-reviews.index')))['reviewableVersions'], 'id'))->toBe([(string) $version->id]);

    DB::table('recall_protocol_versions')->where('id', $version->id)->update(['state' => ProtocolVersionState::Withdrawn->value]);

    expect(crcProps(crcAsAdmin()->get(route('manager.clinical-reviews.index')))['reviewableVersions'])->toBe([]);
});

it('convite com prazo vencido aparece como vencido e sem reenvio', function () {
    $created = crfInvitation();
    $created['invitation']->forceFill(['expires_at' => now()->subHour()])->save();

    $row = crcProps(crcAsAdmin()->get(route('manager.clinical-reviews.index')))['invitations'][0];

    expect($row['status'])->toBe('pending')
        ->and($row['is_expired'])->toBeTrue()
        ->and($row['can_resend'])->toBeFalse()
        ->and($row['can_revoke'])->toBeTrue();
});

it('convite respondido mostra a decisão de cada versão e fecha as ações', function () {
    Notification::fake();
    $created = crfInvitation();
    crfSubmit($created, 'changes_requested', 'Reduzir a campimetria para 9 meses.');

    $row = crcProps(crcAsAdmin()->get(route('manager.clinical-reviews.index')))['invitations'][0];

    expect($row['status'])->toBe('submitted')
        ->and($row['submitted_at'])->not->toBeNull()
        ->and($row['items'][0]['decision'])->toBe('changes_requested')
        ->and($row['items'][0]['decision_label'])->toBe('Aprovo com ajustes')
        ->and($row['can_resend'])->toBeFalse()
        ->and($row['can_revoke'])->toBeFalse();
});

// ── Revisores ───────────────────────────────────────────────────────────────

it('cadastra o Revisor com CRM/RQE normalizados e o autor registrado', function () {
    crcAsAdmin()->post(route('manager.clinical-reviewers.store'), crcReviewerData())
        ->assertRedirect()
        ->assertSessionHas('success', __('manager_reviews.flash.reviewer_created', ['name' => 'Dra. Helena Prado']));

    $reviewer = ClinicalReviewer::query()->sole();

    expect($reviewer->crm_number)->toBe('12345')
        ->and($reviewer->rqe)->toBe('6789')
        ->and($reviewer->email)->toBe('helena.prado@exemplo.test')
        ->and($reviewer->active)->toBeTrue()
        ->and($reviewer->created_by)->toBe($this->admin->id);
});

it('recusa Revisor duplicado pelo CRM normalizado e pelo e-mail em qualquer caixa', function () {
    crcAsAdmin()->post(route('manager.clinical-reviewers.store'), crcReviewerData())->assertRedirect();

    crcAsAdmin()->post(route('manager.clinical-reviewers.store'), crcReviewerData(['email' => 'outra@exemplo.test', 'crm_number' => '012345']))
        ->assertSessionHasErrors(['crm_number' => __('manager_reviews.validation.crm_taken')]);

    crcAsAdmin()->post(route('manager.clinical-reviewers.store'), crcReviewerData(['email' => 'HELENA.Prado@Exemplo.test', 'crm_number' => '99999']))
        ->assertSessionHasErrors(['email' => __('manager_reviews.validation.email_taken')]);

    // O mesmo número em OUTRA UF é outro registro.
    crcAsAdmin()->post(route('manager.clinical-reviewers.store'), crcReviewerData(['email' => 'rj@exemplo.test', 'crm_uf' => 'RJ']))->assertSessionHasNoErrors();

    expect(ClinicalReviewer::query()->count())->toBe(2);
});

it('valida a forma: UF fora da lista, número sem dígito, e-mail inválido e campos faltando', function () {
    crcAsAdmin()->post(route('manager.clinical-reviewers.store'), crcReviewerData(['crm_uf' => 'XX', 'email' => 'nao-e-email', 'rqe' => 'abc', 'name' => 'A']))
        ->assertSessionHasErrors(['crm_uf', 'email', 'rqe', 'name']);

    crcAsAdmin()->post(route('manager.clinical-reviewers.store'), crcReviewerData(['crm_number' => '000']))
        ->assertSessionHasErrors(['crm_number' => __('manager_reviews.validation.number_required')]);

    crcAsAdmin()->post(route('manager.clinical-reviewers.store'), [])->assertSessionHasErrors(['name', 'email', 'crm_number', 'crm_uf', 'rqe']);

    expect(ClinicalReviewer::query()->count())->toBe(0);
});

it('edita o Revisor sem brigar com o próprio CRM/e-mail, mas não pode tomar os de outro', function () {
    $own   = crfReviewer(['email' => 'um@exemplo.test', 'crm_number' => '111', 'crm_uf' => 'SP']);
    $other = crfReviewer(['email' => 'dois@exemplo.test', 'crm_number' => '222', 'crm_uf' => 'SP']);

    crcAsAdmin()->put(route('manager.clinical-reviewers.update', $own->id), crcReviewerData(['email' => 'um@exemplo.test', 'crm_number' => '111', 'name' => 'Novo Nome Completo']))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', __('manager_reviews.flash.reviewer_updated'));

    expect($own->fresh()->name)->toBe('Novo Nome Completo');

    crcAsAdmin()->put(route('manager.clinical-reviewers.update', $own->id), crcReviewerData(['email' => 'DOIS@exemplo.test', 'crm_number' => '111']))
        ->assertSessionHasErrors('email');

    crcAsAdmin()->put(route('manager.clinical-reviewers.update', $own->id), crcReviewerData(['email' => 'um@exemplo.test', 'crm_number' => '0222']))
        ->assertSessionHasErrors('crm_number');

    expect($own->fresh()->crm_number)->toBe('111')->and($other->fresh()->crm_number)->toBe('222');
});

it('Revisor inexistente dá 404 (e id que não é uuid também)', function () {
    crcAsAdmin()->put(route('manager.clinical-reviewers.update', (string) Str::uuid()), crcReviewerData())->assertNotFound();
    crcAsAdmin()->post(route('manager.clinical-reviewers.active', (string) Str::uuid()), ['active' => false])->assertNotFound();
    crcAsAdmin()->put('/panel/manager/clinical-reviewers/nao-e-uuid', crcReviewerData())->assertNotFound();
});

it('desativar o Revisor revoga os convites pendentes dele; reativar não os ressuscita', function () {
    $reviewer = crfReviewer();
    $created  = crfInvitation($reviewer);

    crcAsAdmin()->post(route('manager.clinical-reviewers.active', $reviewer->id), ['active' => false])
        ->assertSessionHas('success', __('manager_reviews.flash.reviewer_deactivated'));

    expect($reviewer->fresh()->active)->toBeFalse()
        ->and($created['invitation']->fresh()->status)->toBe(Status::Revoked);

    crcAsAdmin()->post(route('manager.clinical-reviewers.active', $reviewer->id), ['active' => true])
        ->assertSessionHas('success', __('manager_reviews.flash.reviewer_activated'));

    expect($reviewer->fresh()->active)->toBeTrue()->and($created['invitation']->fresh()->status)->toBe(Status::Revoked);

    crcAsAdmin()->postJson(route('manager.clinical-reviewers.active', $reviewer->id), [])->assertJsonValidationErrors('active');
});

// ── convites ────────────────────────────────────────────────────────────────

it('cria e envia o convite: e-mail ao Revisor com o link, sent_at gravado, PIN gerado aparece UMA vez', function () {
    Notification::fake();
    $reviewer = crfReviewer();
    $version  = crfFrozenVersion('glaucoma');

    crcAsAdmin()->post(route('manager.clinical-reviews.store'), [
        'reviewer_id'          => $reviewer->id,
        'version_ids'          => [(string) $version->id],
        'generate_access_code' => true,
        'expires_in_days'      => 7,
    ])->assertRedirect()
        ->assertSessionHas('success', __('manager_reviews.flash.invitation_sent', ['email' => $reviewer->email]));

    $invitation = ClinicalReviewInvitation::query()->sole();
    $pin        = session('review_access_code');

    expect($pin)->toMatch('/^[A-Z0-9]{6,12}$/')
        ->and($invitation->status)->toBe(Status::Pending)
        ->and($invitation->sent_at)->not->toBeNull()
        ->and($invitation->last_send_error)->toBeNull()
        ->and($invitation->requiresAccessCode())->toBeTrue()
        ->and($invitation->access_code_hash)->not->toContain($pin)
        ->and($invitation->expires_at->isSameDay(now()->addDays(7)))->toBeTrue()
        ->and($invitation->created_by)->toBe($this->admin->id);

    Notification::assertSentOnDemand(ClinicalReviewInvitationNotification::class, function ($notification, $channels, $notifiable) use ($reviewer) {
        return $notifiable->routes['mail'] === $reviewer->email && $notification->pinRequired === true && str_contains($notification->url, '/revisao-clinica/abrir/');
    });

    // O PIN aparece na tela UMA vez (o redirecionamento do envio): a visita seguinte não o traz.
    $first = crcProps(crcAsAdmin()->get(route('manager.clinical-reviews.index')));
    $again = crcProps(crcAsAdmin()->get(route('manager.clinical-reviews.index')));

    expect($first['issuedAccessCode'])->toBe($pin)->and($again['issuedAccessCode'])->toBeNull();
});

it('o PIN digitado pelo Manager vale (em qualquer caixa) e NÃO volta na tela', function () {
    Notification::fake();
    $reviewer = crfReviewer();

    crcAsAdmin()->post(route('manager.clinical-reviews.store'), [
        'reviewer_id' => $reviewer->id,
        'version_ids' => [(string) crfFrozenVersion('glaucoma')->id],
        'access_code' => 'abc 234 xyz',
    ])->assertSessionHas('success');

    expect(session('review_access_code'))->toBeNull()
        ->and(ClinicalReviewInvitation::query()->sole()->requiresAccessCode())->toBeTrue();
});

it('PIN fora do padrão é recusado e nada é criado nem enviado', function () {
    Notification::fake();
    $reviewer = crfReviewer();

    crcAsAdmin()->post(route('manager.clinical-reviews.store'), [
        'reviewer_id' => $reviewer->id,
        'version_ids' => [(string) crfFrozenVersion('glaucoma')->id],
        'access_code' => '123',
    ])->assertSessionHas('error', __('manager_reviews.errors.invalid_access_code'));

    expect(ClinicalReviewInvitation::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

it('recusa convite com versão que não está em revisão, Revisor inativo e duplicado pendente', function () {
    Notification::fake();
    $reviewer = crfReviewer();
    $frozen   = crfFrozenVersion('glaucoma');
    $draft    = crcDraftVersion();

    // versão que não está em revisão
    crcAsAdmin()->post(route('manager.clinical-reviews.store'), ['reviewer_id' => $reviewer->id, 'version_ids' => [(string) $draft->id]])
        ->assertSessionHas('error', __('manager_reviews.errors.version_not_in_review'));

    // versão que não existe
    crcAsAdmin()->post(route('manager.clinical-reviews.store'), ['reviewer_id' => $reviewer->id, 'version_ids' => [(string) Str::uuid()]])
        ->assertSessionHas('error', __('manager_reviews.errors.version_not_found'));

    // duplicado pendente (mesmo Revisor, mesmo conjunto)
    crcAsAdmin()->post(route('manager.clinical-reviews.store'), ['reviewer_id' => $reviewer->id, 'version_ids' => [(string) $frozen->id]])->assertSessionHas('success');
    crcAsAdmin()->post(route('manager.clinical-reviews.store'), ['reviewer_id' => $reviewer->id, 'version_ids' => [(string) $frozen->id]])
        ->assertSessionHas('error', __('manager_reviews.errors.duplicate_pending'));

    // Revisor inativo
    $reviewer->forceFill(['active' => false])->save();
    crcAsAdmin()->post(route('manager.clinical-reviews.store'), ['reviewer_id' => $reviewer->id, 'version_ids' => [(string) crfFrozenVersion('dmri')->id]])
        ->assertSessionHas('error', __('manager_reviews.errors.reviewer_inactive'));

    expect(ClinicalReviewInvitation::query()->count())->toBe(1);
});

it('valida a forma do convite: Revisor inexistente, nenhuma versão, versões repetidas, prazo fora do limite', function () {
    $reviewer = crfReviewer();
    $version  = (string) crfFrozenVersion('glaucoma')->id;

    crcAsAdmin()->postJson(route('manager.clinical-reviews.store'), [])->assertJsonValidationErrors(['reviewer_id', 'version_ids']);
    crcAsAdmin()->postJson(route('manager.clinical-reviews.store'), ['reviewer_id' => $reviewer->id, 'version_ids' => [$version, $version]])->assertJsonValidationErrors(['version_ids.0', 'version_ids.1']);
    crcAsAdmin()->postJson(route('manager.clinical-reviews.store'), ['reviewer_id' => $reviewer->id, 'version_ids' => [$version], 'expires_in_days' => 31])->assertJsonValidationErrors('expires_in_days');
    crcAsAdmin()->postJson(route('manager.clinical-reviews.store'), ['reviewer_id' => $reviewer->id, 'version_ids' => [$version], 'expires_in_days' => 0])->assertJsonValidationErrors('expires_in_days');
    crcAsAdmin()->post(route('manager.clinical-reviews.store'), ['reviewer_id' => (string) Str::uuid(), 'version_ids' => [$version]])->assertNotFound();

    expect(ClinicalReviewInvitation::query()->count())->toBe(0);
});

it('e-mail que não sai: o convite fica criado com o erro registrado, a tela avisa e o reenvio resolve', function () {
    $reviewer = crfReviewer();
    $version  = crfFrozenVersion('glaucoma');

    rivBreakMail();

    crcAsAdmin()->post(route('manager.clinical-reviews.store'), ['reviewer_id' => $reviewer->id, 'version_ids' => [(string) $version->id]])
        ->assertSessionHas('error', __('manager_reviews.flash.invitation_not_sent'));

    $invitation = ClinicalReviewInvitation::query()->sole();

    expect($invitation->sent_at)->toBeNull()
        ->and($invitation->last_send_error)->toContain('SMTP indisponível');

    $row = crcProps(crcAsAdmin()->get(route('manager.clinical-reviews.index')))['invitations'][0];

    expect($row['sent_at'])->toBeNull()->and($row['last_send_error'])->toContain('SMTP indisponível');

    // SMTP volta: o reenvio manda o link novo.
    config(['mail.default' => 'array']);
    Notification::fake();

    crcAsAdmin()->post(route('manager.clinical-reviews.resend', $invitation->id))
        ->assertSessionHas('success', __('manager_reviews.flash.invitation_resent', ['email' => $reviewer->email]));

    expect($invitation->fresh()->sent_at)->not->toBeNull()->and($invitation->fresh()->last_send_error)->toBeNull();
    Notification::assertSentOnDemandTimes(ClinicalReviewInvitationNotification::class, 1);
});

it('reenviar troca o token: o link antigo morre, o novo abre, e o histórico registra quem fez', function () {
    Notification::fake();
    $created = crfInvitation();
    $old     = crfOpenUrl($created);

    crcAsAdmin()->post(route('manager.clinical-reviews.resend', $created['invitation']->id))->assertSessionHas('success');

    $this->get($old)->assertRedirect(route('clinical-review.unavailable'));

    $new = null;
    Notification::assertSentOnDemand(ClinicalReviewInvitationNotification::class, function ($notification) use (&$new) {
        $new = $notification->url;

        return true;
    });

    $this->get($new)->assertRedirect(route('clinical-review.show'));

    expect(ClinicalGovernanceEvent::query()->where('subject_id', $created['invitation']->id)->where('event', 'resent')->value('actor_user_id'))->toBe($this->admin->id);
});

it('só convite pendente (e dentro do prazo) pode ser reenviado ou revogado', function () {
    Notification::fake();
    $submitted = crfInvitation(versions: [crfFrozenVersion('glaucoma')]);
    crfSubmit($submitted);

    $expired = crfInvitation(versions: [crfFrozenVersion('dmri')]);
    $expired['invitation']->forceFill(['expires_at' => now()->subMinute()])->save();

    crcAsAdmin()->post(route('manager.clinical-reviews.resend', $submitted['invitation']->id))->assertSessionHas('error', __('manager_reviews.errors.not_pending'));
    crcAsAdmin()->post(route('manager.clinical-reviews.revoke', $submitted['invitation']->id), ['reason' => 'Motivo qualquer do teste.'])->assertSessionHas('error', __('manager_reviews.errors.not_pending'));
    crcAsAdmin()->post(route('manager.clinical-reviews.resend', $expired['invitation']->id))->assertSessionHas('error', __('manager_reviews.errors.expired'));

    expect($submitted['invitation']->fresh()->status)->toBe(Status::Submitted);
});

it('revoga o convite com o motivo registrado e derruba o link na hora', function () {
    $created = crfInvitation();
    $url     = crfOpenUrl($created);

    crcAsAdmin()->post(route('manager.clinical-reviews.revoke', $created['invitation']->id), ['reason' => 'Revisor trocado pelo conselho.'])
        ->assertSessionHas('success', __('manager_reviews.flash.invitation_revoked'));

    $invitation = $created['invitation']->fresh();

    expect($invitation->status)->toBe(Status::Revoked)
        ->and($invitation->revoked_reason)->toBe('Revisor trocado pelo conselho.')
        ->and($invitation->revoked_at)->not->toBeNull()
        ->and(ClinicalGovernanceEvent::query()->where('subject_id', $invitation->id)->where('event', 'revoked')->value('actor_user_id'))->toBe($this->admin->id);

    $this->get($url)->assertRedirect(route('clinical-review.unavailable'));
});

it('revogar exige motivo de verdade (10+ caracteres, sem só espaços)', function () {
    $created = crfInvitation();

    crcAsAdmin()->postJson(route('manager.clinical-reviews.revoke', $created['invitation']->id), [])->assertJsonValidationErrors('reason');
    crcAsAdmin()->postJson(route('manager.clinical-reviews.revoke', $created['invitation']->id), ['reason' => 'curto'])->assertJsonValidationErrors('reason');
    crcAsAdmin()->post(route('manager.clinical-reviews.revoke', $created['invitation']->id), ['reason' => '            '])->assertSessionHasErrors('reason');

    expect($created['invitation']->fresh()->status)->toBe(Status::Pending);
});

it('convite inexistente dá 404', function () {
    crcAsAdmin()->post(route('manager.clinical-reviews.resend', (string) Str::uuid()))->assertNotFound();
    crcAsAdmin()->post(route('manager.clinical-reviews.revoke', (string) Str::uuid()), ['reason' => 'Motivo qualquer do teste.'])->assertNotFound();
});

it('as ações de impacto respeitam o teto do Manager', function () {
    Notification::fake();
    $created = crfInvitation();

    // manager-destructive: o teto está configurado (a rota o carrega).
    $route = app('router')->getRoutes()->getByName('manager.clinical-reviews.revoke');

    expect($route->gatherMiddleware())->toContain('throttle:manager-destructive');
    expect(app('router')->getRoutes()->getByName('manager.clinical-reviews.store')->gatherMiddleware())->toContain('throttle:manager-destructive')
        ->and(app('router')->getRoutes()->getByName('manager.clinical-reviews.resend')->gatherMiddleware())->toContain('throttle:manager-destructive');
});

/** Rascunho do 2º protocolo (existe do seeder, ainda não congelado). */
function crcDraftVersion(): RecallProtocolVersion
{
    return RecallProtocol::query()->where('key', 'ceratocone')->firstOrFail()->versions()->firstOrFail();
}
