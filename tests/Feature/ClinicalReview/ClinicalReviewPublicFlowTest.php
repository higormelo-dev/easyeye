<?php

declare(strict_types=1);

/**
 * A página PÚBLICA do Revisor Clínico, ponta a ponta pelo HTTP (ADR 0002): o 1º salto com o
 * token, a sessão, o PIN, o rascunho, o POST único e a página de conclusão — e tudo que NÃO
 * pode vazar (token na URL, dado de paciente, motivo da indisponibilidade, cache, Referer).
 */

use App\Domains\ClinicalReview\Enums\ReviewInvitationStatus as Status;
use App\Domains\ClinicalReview\Models\{ClinicalReviewAttestation, ClinicalReviewItem};
use App\Domains\ClinicalReview\Services\ReviewInvitationService;
use App\Domains\ClinicalReview\Support\AttestationStatement;
use App\Domains\Recall\Services\ProtocolCatalogService;
use App\Http\Middleware\{EnsureClinicalReviewSession, HandleInertiaRequests};
use App\Models\{Entity, Patient, People, User};
use Illuminate\Support\Facades\{DB, Notification, Route, URL};
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/** Cabeçalhos que a página pública tem que mandar sempre (Referer, cache, buscador, moldura). */
function crpAssertPublicHeaders(TestResponse $response): void
{
    expect($response->headers->get('Referrer-Policy'))->toBe('no-referrer')
        ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store')
        ->and((string) $response->headers->get('X-Robots-Tag'))->toContain('noindex')
        ->and($response->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
}

/** Abre o link (1º salto) e devolve a resposta. */
function crpOpen(array $created): TestResponse
{
    return test()->get(crfOpenUrl($created));
}

/** Abre o link e segue até a página da revisão. */
function crpEnter(array $created): void
{
    crpOpen($created)->assertRedirect();
}

/** Cabeçalhos de uma visita Inertia (com a versão dos assets: sem ela o servidor responde 409). */
function crpInertiaHeaders(): array
{
    return ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request())];
}

/** Props Inertia de uma página (requisição Inertia → JSON). */
function crpProps(string $url): array
{
    $response = test()->get($url, crpInertiaHeaders());

    return $response->assertOk()->json('props');
}

/** O que a página envia no POST do parecer. */
function crpPayload(array $created, array $overrides = []): array
{
    $statement = AttestationStatement::current();

    return array_replace_recursive([
        'decisions'                 => crfDecisions($created['invitation']),
        'attestation'               => crfAttestation($created['reviewer']),
        'statement_term_version_id' => (string) $statement->id,
        'statement_sha256'          => AttestationStatement::hash((string) $statement->content),
    ], $overrides);
}

// ── 1º salto ────────────────────────────────────────────────────────────────

it('o link troca o token por uma sessão e manda para a URL LIMPA, sem token', function () {
    $created = crfInvitation();

    $response = crpOpen($created);

    $response->assertRedirect(route('clinical-review.show'));

    expect($response->headers->get('Location'))->not->toContain($created['token'])->not->toContain('signature');
    crpAssertPublicHeaders($response);

    $state = session(EnsureClinicalReviewSession::SESSION_KEY);

    expect($state['invitation_id'])->toBe($created['invitation']->id)
        ->and($state['token_hash'])->toBe($created['invitation']->token_hash)
        // Nunca o token em claro na sessão.
        ->and(json_encode($state))->not->toContain($created['token']);
});

it('GET NUNCA consome o convite: abrir várias vezes só conta as aberturas', function () {
    $created = crfInvitation();

    foreach (range(1, 3) as $time) {
        crpOpen($created)->assertRedirect(route('clinical-review.show'));
    }

    $invitation = $created['invitation']->fresh();

    expect($invitation->status)->toBe(Status::Pending)
        ->and($invitation->open_count)->toBe(3)
        ->and($invitation->last_opened_at)->not->toBeNull()
        ->and($invitation->updated_by)->toBeNull();

    $this->get(route('clinical-review.show'))->assertOk();
});

it('um verificador de e-mail (outra sessão, sem cookie) abre o link e não atrapalha o Revisor', function () {
    $created = crfInvitation();

    // "Scanner": faz o GET e a sessão dele (o cookie) não chega ao navegador do Revisor.
    crpOpen($created);
    $this->flushSession();

    // O Revisor de verdade abre depois e segue normalmente.
    crpOpen($created)->assertRedirect(route('clinical-review.show'));
    $this->get(route('clinical-review.show'))->assertOk();

    expect($created['invitation']->fresh()->status)->toBe(Status::Pending);
});

it('a página sempre sai com os cabeçalhos de privacidade (Referer, cache, buscador, moldura)', function () {
    $created = crfInvitation(pin: 'ABCD2345');

    crpAssertPublicHeaders(crpOpen($created));
    crpAssertPublicHeaders($this->get(route('clinical-review.code')));
    crpAssertPublicHeaders($this->get(route('clinical-review.unavailable')));

    $this->post(route('clinical-review.code.verify'), ['code' => 'ABCD2345'])->assertRedirect(route('clinical-review.show'));

    crpAssertPublicHeaders($this->get(route('clinical-review.show')));
    crpAssertPublicHeaders($this->putJson(route('clinical-review.draft'), ['decisions' => []]));
});

it('a página pública usa o layout de visitante: noindex e nenhum bundle do painel', function () {
    $created = crfInvitation();
    crpEnter($created);

    $html = $this->get(route('clinical-review.show'))->assertOk()->getContent();

    expect($html)->toContain('noindex, nofollow')
        ->and($html)->toContain('data-bs-theme')
        ->and($html)->not->toContain('panel.js');
});

// ── tudo que não é um link bom cai na MESMA página ──────────────────────────

it('qualquer link ruim leva à mesma página genérica, sem dizer o motivo', function () {
    $created = crfInvitation();
    $good    = crfOpenUrl($created);

    $unknownId  = URL::temporarySignedRoute('clinical-review.open', now()->addDay(), ['invitation' => (string) Str::uuid(), 'token' => $created['token']]);
    $wrongToken = URL::temporarySignedRoute('clinical-review.open', now()->addDay(), ['invitation' => $created['invitation']->id, 'token' => 'token-errado']);
    $tampered   = str_replace($created['token'], 'outro-token-qualquer', $good);
    $unsigned   = route('clinical-review.open', ['invitation' => $created['invitation']->id, 'token' => $created['token']]);
    $expiredSig = URL::temporarySignedRoute('clinical-review.open', now()->subMinute(), ['invitation' => $created['invitation']->id, 'token' => $created['token']]);

    $revoked = crfInvitation(versions: [crfFrozenVersion('dmri')]);
    app(ReviewInvitationService::class)->revoke($revoked['invitation'], 'Motivo qualquer do teste.');

    $overdue = crfInvitation(versions: [crfFrozenVersion('ceratocone')]);
    $overdue['invitation']->forceFill(['expires_at' => now()->subSecond()])->save();

    $urls = [
        'id desconhecido'       => $unknownId,
        'token errado'          => $wrongToken,
        'assinatura adulterada' => $tampered,
        'sem assinatura'        => $unsigned,
        'assinatura vencida'    => $expiredSig,
        'convite revogado'      => crfOpenUrl($revoked),
        'convite vencido'       => crfOpenUrl($overdue),
    ];

    foreach ($urls as $name => $url) {
        $this->flushSession();
        $response = $this->get($url);

        $response->assertStatus(302)->assertRedirect(route('clinical-review.unavailable'));

        expect(session(EnsureClinicalReviewSession::SESSION_KEY))->toBeNull($name);
    }

    $this->get(route('clinical-review.unavailable'))->assertOk();

    $props = crpProps(route('clinical-review.unavailable'));

    // A página genérica só recebe os textos dela e da moldura — nada do formulário nem do motivo.
    expect(array_keys($props['t']))->toBe(['layout', 'unavailable'])
        ->and(json_encode($props['t']))->not->toContain('revogad')->not->toContain('expirou')
        ->and($props['urls'])->toBe(['locale' => route('clinical-review.locale')]);
});

it('sem sessão, nenhuma página do Revisor abre: tudo vai para a página genérica', function () {
    $created = crfInvitation();

    $this->get(route('clinical-review.show'))->assertRedirect(route('clinical-review.unavailable'));
    $this->get(route('clinical-review.done'))->assertRedirect(route('clinical-review.unavailable'));
    $this->get(route('clinical-review.code'))->assertRedirect(route('clinical-review.unavailable'));
    $this->post(route('clinical-review.submit'), crpPayload($created))->assertRedirect(route('clinical-review.unavailable'));

    // Chamada de fetch (rascunho): 409 com o destino, não um HTML seguido por redirect.
    $this->putJson(route('clinical-review.draft'), ['decisions' => []])
        ->assertStatus(409)
        ->assertJson(['message' => 'unavailable', 'redirect' => route('clinical-review.unavailable')]);

    expect(ClinicalReviewAttestation::query()->count())->toBe(0);
});

it('convite respondido: o mesmo link leva à conclusão, nunca de volta ao formulário', function () {
    Notification::fake();
    $created = crfInvitation();
    crfSubmit($created);

    crpOpen($created)->assertRedirect(route('clinical-review.done'));

    // "Voltar" para a revisão leva à conclusão — e NÃO derruba a sessão (a conclusão segue abrindo).
    $this->get(route('clinical-review.show'))->assertRedirect(route('clinical-review.done'));
    $this->get(route('clinical-review.done'))->assertOk();
    $this->get(route('clinical-review.show'))->assertRedirect(route('clinical-review.done'));
});

it('convite respondido com código de acesso: o link pede o código e só então mostra a conclusão', function () {
    Notification::fake();
    $created = crfInvitation(pin: 'ABCD2345');
    crfSubmit($created);

    crpOpen($created)->assertRedirect(route('clinical-review.code'));

    // Sem o código, nem o recibo (hash, decisões) aparece.
    $this->get(route('clinical-review.done'))->assertRedirect(route('clinical-review.code'));
    $this->get(route('clinical-review.show'))->assertRedirect(route('clinical-review.code'));

    $this->post(route('clinical-review.code.verify'), ['code' => 'ABCD2345'])->assertRedirect(route('clinical-review.done'));
    $this->get(route('clinical-review.done'))->assertOk();
});

it('a conclusão de um convite que ainda está pendente leva de volta à revisão, sem derrubar a sessão', function () {
    $created = crfInvitation();
    crpEnter($created);

    $this->get(route('clinical-review.done'))->assertRedirect(route('clinical-review.show'));
    $this->get(route('clinical-review.show'))->assertOk();
});

// ── a sessão acompanha o convite ────────────────────────────────────────────

it('revogar o convite derruba a sessão que já estava aberta', function () {
    $created = crfInvitation();
    crpEnter($created);
    $this->get(route('clinical-review.show'))->assertOk();

    app(ReviewInvitationService::class)->revoke($created['invitation'], 'Revogado enquanto o Revisor lia.');

    $this->get(route('clinical-review.show'))->assertRedirect(route('clinical-review.unavailable'));
    expect(session(EnsureClinicalReviewSession::SESSION_KEY))->toBeNull();
});

it('reenviar (token novo) derruba a sessão do link antigo e o link novo funciona', function () {
    $created = crfInvitation();
    crpEnter($created);
    $this->get(route('clinical-review.show'))->assertOk();

    $resent = app(ReviewInvitationService::class)->resend($created['invitation']);

    $this->get(route('clinical-review.show'))->assertRedirect(route('clinical-review.unavailable'));

    // O link ANTIGO não abre mais...
    $this->get(crfOpenUrl($created))->assertRedirect(route('clinical-review.unavailable'));
    // ...e o NOVO sim.
    $this->get(crfOpenUrl(['invitation' => $resent['invitation']->fresh()] + $resent))->assertRedirect(route('clinical-review.show'));
    $this->get(route('clinical-review.show'))->assertOk();
});

it('o convite vencer derruba a sessão que já estava aberta', function () {
    $created = crfInvitation();
    crpEnter($created);

    $this->travelTo($created['invitation']->expires_at->addMinute());

    $this->get(route('clinical-review.show'))->assertRedirect(route('clinical-review.unavailable'));
});

it('a sessão nova substitui a anterior: abrir outro convite no mesmo navegador não mistura os dois', function () {
    $first  = crfInvitation(versions: [crfFrozenVersion('glaucoma')]);
    $second = crfInvitation(versions: [crfFrozenVersion('dmri')]);

    crpEnter($first);
    $idBefore = session()->getId();
    crpEnter($second);

    expect(session()->getId())->not->toBe($idBefore)
        ->and(session(EnsureClinicalReviewSession::SESSION_KEY . '.invitation_id'))->toBe($second['invitation']->id);

    $props = crpProps(route('clinical-review.show'));

    expect(array_column($props['items'], 'key'))->toBe(['dmri']);
});

// ── a página da revisão ─────────────────────────────────────────────────────

it('a página traz SÓ as versões do convite, com a regra, o que mudou, os exemplos e a declaração', function () {
    $created = crfInvitation(versions: [crfFrozenVersion('glaucoma')]);
    crfFrozenVersion('dmri'); // outro protocolo em revisão, fora do convite
    crpEnter($created);

    $props = crpProps(route('clinical-review.show'));

    expect(array_column($props['items'], 'key'))->toBe(['glaucoma'])
        ->and($props['available'])->toBeTrue()
        // Só o nome: CRM, RQE e e-mail do cadastro não vão à página (o Revisor os DECLARA no atestado).
        ->and($props['reviewer'])->toBe(['name' => $created['reviewer']->name])
        ->and($props['urls'])->toBe(['draft' => route('clinical-review.draft'), 'keepalive' => route('clinical-review.keepalive'), 'submit' => route('clinical-review.submit'), 'locale' => route('clinical-review.locale')])
        ->and($props['invitation']['expires_at'])->toBe($created['invitation']->expires_at->toIso8601String())
        ->and($props['draft'])->toBeNull()
        ->and($props['options']['ufs'])->toContain('SP')
        ->and(array_column($props['options']['decisions'], 'value'))->toBe(['approved', 'changes_requested', 'rejected']);

    $item = $props['items'][0];

    expect($item['title'])->toBe('Glaucoma')
        ->and($item['version'])->toBe(1)
        ->and($item['available'])->toBeTrue()
        ->and($item['short_hash'])->toBe($created['versions'][0]->shortHash())
        ->and($item['content']['tasks'][0]['key'])->toBe('consulta')
        ->and($item['parent'])->toBeNull()
        ->and($item['diff'])->toBe([])
        ->and($item['examples'])->toHaveCount(3)
        ->and($props['ruleT']['labels']['exams']['visual_field'])->not->toBeEmpty();

    $statement = AttestationStatement::current();

    expect($props['statement']['term_version_id'])->toBe((string) $statement->id)
        ->and($props['statement']['sha256'])->toBe(hash('sha256', $statement->content))
        ->and($props['statement']['text'])->toBe($statement->content)
        ->and($props['statement']['is_translation'])->toBeFalse()
        // Em português o texto exibido JÁ é o oficial: nada de bloco extra.
        ->and($props['statement']['official_text'])->toBeNull();
});

it('versão derivada: mostra a versão anterior e a lista do que mudou', function () {
    $v1 = crfFrozenVersion('glaucoma');
    DB::table('recall_protocol_versions')->where('id', $v1->id)->update(['state' => 'declined']);

    $v2                                         = app(ProtocolCatalogService::class)->startRevision($v1->protocol);
    $content                                    = $v2->contentArray();
    $content['tasks'][1]['interval']['default'] = 9;
    $content['change_note']                     = 'Campimetria a cada 9 meses.';
    app(ProtocolCatalogService::class)->saveDraft($v2, $content, 0);
    $v2 = app(ProtocolCatalogService::class)->freeze($v2->fresh());

    $created = crfInvitation(versions: [$v2->fresh()]);
    crpEnter($created);

    $item = crpProps(route('clinical-review.show'))['items'][0];

    expect($item['version'])->toBe(2)
        ->and($item['parent'])->toBe(['version' => 1, 'validated' => false])
        ->and(collect($item['diff'])->where('section', 'task')->where('field', 'interval.default')->first())->toMatchArray(['key' => 'campimetria', 'from' => 12, 'to' => 9]);
});

it('a regra aparece no idioma do Revisor, e o texto oficial da declaração segue em português', function () {
    $created = crfInvitation();
    crpEnter($created);

    $this->withSession(['locale' => 'en']);
    $props = crpProps(route('clinical-review.show'));

    expect($props['items'][0]['title'])->toBe('Glaucoma')
        ->and($props['statement']['is_translation'])->toBeTrue()
        ->and($props['statement']['text'])->toContain('CLINICAL REVIEWER STATEMENT')
        // Quem lê a tradução assina o texto em português: ele também chega à página.
        ->and($props['statement']['official_text'])->toBe(AttestationStatement::current()->content)
        // O hash e o texto gravados no atestado são sempre os OFICIAIS (pt_BR).
        ->and($props['statement']['sha256'])->toBe(hash('sha256', AttestationStatement::current()->content));
});

it('NENHUM dado de paciente, de clínica ou de usuário da EasyEye chega à página pública', function () {
    $clinic  = Entity::factory()->create(['name' => 'CLINICA-ISCA-ZXQ', 'is_client' => true, 'active' => true]);
    $staff   = User::factory()->create(['name' => 'USUARIO-ISCA-WVU', 'email' => 'isca-staff@exemplo.test']);
    $person  = People::factory()->create(['full_name' => 'PACIENTE-ISCA-MNB']);
    $patient = Patient::factory()->create(['entity_id' => $clinic->id, 'person_id' => $person->id]);

    // CRM/RQE de isca distintivos: o contador do fixture (2000 + n) cai em "2021" na 21ª revisora do processo, e
    // "2021" aparece legitimamente em qualquer JSON — a isca precisa ser algo que só vazaria de verdade.
    $created = crfInvitation(crfReviewer(['crm_number' => '987654', 'rqe' => '765432']), actor: $staff);
    crpEnter($created);

    $this->actingAs($staff);
    $registry = [$created['reviewer']->crm_number, $created['reviewer']->rqe, $created['reviewer']->email];

    foreach ([route('clinical-review.show'), route('clinical-review.unavailable')] as $url) {
        $response = $this->get($url, crpInertiaHeaders())->assertOk();
        $json     = (string) $response->getContent();

        foreach (['CLINICA-ISCA-ZXQ', 'USUARIO-ISCA-WVU', 'isca-staff@exemplo.test', 'PACIENTE-ISCA-MNB', (string) $patient->id, (string) $clinic->id, ...$registry] as $decoy) {
            $this->assertStringNotContainsString($decoy, $json, "vazou {$decoy} em {$url}");
        }

        $props = $response->json('props');

        // Mesmo com um usuário do painel logado no navegador, a página pública não lhe dá contexto algum.
        expect($props['auth'] ?? [])->toBe([])->and($props['nav'] ?? [])->toBe([]);
    }
});

it('versão que saiu de revisão: a página avisa que o conjunto mudou e marca o item indisponível', function () {
    $created = crfInvitation(versions: [crfFrozenVersion('glaucoma'), crfFrozenVersion('dmri')]);
    DB::table('recall_protocol_versions')->where('id', $created['versions'][1]->id)->update(['state' => 'withdrawn']);
    crpEnter($created);

    $props = crpProps(route('clinical-review.show'));

    expect($props['available'])->toBeFalse()
        ->and(collect($props['items'])->pluck('available', 'key')->all())->toBe(['dmri' => false, 'glaucoma' => true]);
});

// ── rascunho ────────────────────────────────────────────────────────────────

it('salva o rascunho no servidor (cifrado) e a página o devolve ao reabrir', function () {
    $created = crfInvitation();
    crpEnter($created);
    $itemId = (string) $created['invitation']->items()->sole()->id;

    $this->putJson(route('clinical-review.draft'), [
        'decisions'   => [$itemId => ['decision' => 'changes_requested', 'comment' => 'Rascunho do parecer.']],
        'attestation' => ['name' => 'Dra. Ana', 'agreed' => true],
        'estranho'    => 'ignorado',
    ])->assertOk()->assertJsonStructure(['saved_at']);

    $props = crpProps(route('clinical-review.show'));

    expect($props['draft']['decisions'][$itemId]['comment'])->toBe('Rascunho do parecer.')
        ->and($props['draft']['attestation']['name'])->toBe('Dra. Ana')
        ->and($props['draft'])->not->toHaveKey('estranho')
        ->and($props['draftSavedAt'])->not->toBeNull()
        ->and(DB::table('clinical_review_invitations')->where('id', $created['invitation']->id)->value('draft_payload'))->not->toContain('Rascunho do parecer');
});

it('rascunho com forma errada é recusado; depois do envio, o rascunho não grava mais', function () {
    Notification::fake();
    $created = crfInvitation();
    crpEnter($created);

    $this->putJson(route('clinical-review.draft'), ['decisions' => 'texto'])->assertStatus(422);

    $this->post(route('clinical-review.submit'), crpPayload($created))->assertRedirect(route('clinical-review.done'));

    $this->putJson(route('clinical-review.draft'), ['decisions' => []])->assertStatus(409);
    expect($created['invitation']->fresh()->draft_payload)->toBeNull();
});

it('o rascunho tem teto de gravações por minuto', function () {
    $created = crfInvitation();
    crpEnter($created);

    foreach (range(1, 40) as $i) {
        $this->putJson(route('clinical-review.draft'), ['decisions' => []])->assertOk();
    }

    $this->putJson(route('clinical-review.draft'), ['decisions' => []])->assertStatus(429);
});

// ── o envio ─────────────────────────────────────────────────────────────────

it('o POST envia o parecer UMA vez: grava, leva à conclusão e o segundo POST não duplica', function () {
    Notification::fake();
    $created = crfInvitation();
    crpEnter($created);

    $payload = crpPayload($created, ['decisions' => [(string) $created['invitation']->items()->sole()->id => ['decision' => 'changes_requested', 'comment' => 'Reduzir a campimetria para 9 meses.']]]);

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.23', 'HTTP_USER_AGENT' => 'Navegador-Teste/1.0'])
        ->post(route('clinical-review.submit'), $payload)
        ->assertRedirect(route('clinical-review.done'));

    $attestation = ClinicalReviewAttestation::query()->sole();

    expect($created['invitation']->fresh()->status)->toBe(Status::Submitted)
        ->and($attestation->ip)->toBe('198.51.100.23')
        ->and($attestation->user_agent)->toBe('Navegador-Teste/1.0')
        ->and($attestation->declared_crm_number)->toBe($created['reviewer']->crm_number);

    // Duplo clique / reenvio do formulário: o convite já foi respondido — vai ao recibo, sem duplicar.
    $this->post(route('clinical-review.submit'), $payload)->assertRedirect(route('clinical-review.done'));

    expect(ClinicalReviewAttestation::query()->count())->toBe(1);
});

it('erros de preenchimento voltam por campo e NADA é gravado', function () {
    $created = crfInvitation();
    crpEnter($created);
    $itemId = (string) $created['invitation']->items()->sole()->id;

    $this->from(route('clinical-review.show'))
        ->post(route('clinical-review.submit'), crpPayload($created, [
            'decisions'   => [$itemId => ['decision' => 'rejected', 'comment' => 'curto']],
            'attestation' => ['crm_uf' => 'XX', 'email' => 'nao-e-email', 'agreed' => false, 'has_conflict' => true, 'conflict_description' => ''],
        ]))
        ->assertRedirect(route('clinical-review.show'))
        // Todos os problemas de uma vez (não um por tentativa), com o NOME do campo na mensagem.
        ->assertSessionHasErrors(["decisions.{$itemId}.comment", 'attestation.crm_uf', 'attestation.email', 'attestation.agreed', 'attestation.conflict_description']);

    $messages = collect(session('errors')->getBag('default')->all())->implode(' | ');

    expect($messages)->not->toContain('attestation.')->not->toContain('crm uf');

    expect(ClinicalReviewAttestation::query()->count())->toBe(0)
        ->and($created['invitation']->fresh()->status)->toBe(Status::Pending)
        ->and(ClinicalReviewItem::query()->whereNotNull('decision')->count())->toBe(0);
});

it('conflito de interesses declarado exige a descrição', function () {
    $created = crfInvitation();
    crpEnter($created);

    $this->from(route('clinical-review.show'))
        ->post(route('clinical-review.submit'), crpPayload($created, ['attestation' => ['has_conflict' => true, 'conflict_description' => '  ']]))
        ->assertSessionHasErrors('attestation.conflict_description');
});

it('declaração mudada ou conjunto mudado: volta à página com aviso, sem gravar', function () {
    $created = crfInvitation(versions: [crfFrozenVersion('glaucoma'), crfFrozenVersion('dmri')]);
    crpEnter($created);

    $this->from(route('clinical-review.show'))
        ->post(route('clinical-review.submit'), crpPayload($created, ['statement_sha256' => str_repeat('a', 64)]))
        ->assertRedirect(route('clinical-review.show'))
        ->assertSessionHas('error', __('clinical_review.public.errors.statement_changed'));

    DB::table('recall_protocol_versions')->where('id', $created['versions'][0]->id)->update(['state' => 'withdrawn']);

    $this->from(route('clinical-review.show'))
        ->post(route('clinical-review.submit'), crpPayload($created))
        ->assertRedirect(route('clinical-review.show'))
        ->assertSessionHas('error', __('clinical_review.public.errors.set_changed'));

    expect(ClinicalReviewAttestation::query()->count())->toBe(0)->and($created['invitation']->fresh()->status)->toBe(Status::Pending);
});

it('texto do parecer é gravado como TEXTO: HTML digitado não vira marcação', function () {
    Notification::fake();
    $created = crfInvitation();
    crpEnter($created);
    $itemId  = (string) $created['invitation']->items()->sole()->id;
    $payload = '<script>alert(1)</script><b>negrito</b> concordo com ressalvas';

    $this->post(route('clinical-review.submit'), crpPayload($created, ['decisions' => [$itemId => ['decision' => 'changes_requested', 'comment' => $payload]]]))
        ->assertRedirect(route('clinical-review.done'));

    expect($created['invitation']->items()->sole()->comment)->toBe($payload);
});

it('o envio tem teto de tentativas por minuto', function () {
    $created = crfInvitation();
    crpEnter($created);

    foreach (range(1, 6) as $i) {
        $this->post(route('clinical-review.submit'), ['decisions' => []])->assertStatus(302);
    }

    $this->post(route('clinical-review.submit'), ['decisions' => []])->assertStatus(429);
});

// ── a conclusão ─────────────────────────────────────────────────────────────

it('a página de conclusão mostra o código de verificação e as decisões — e só existe depois do envio', function () {
    Notification::fake();
    $created = crfInvitation();
    crpEnter($created);

    // Antes do envio não há conclusão: volta à revisão (e a sessão continua valendo).
    $this->get(route('clinical-review.done'))->assertRedirect(route('clinical-review.show'));

    $this->post(route('clinical-review.submit'), crpPayload($created))->assertRedirect(route('clinical-review.done'));

    $attestation = ClinicalReviewAttestation::query()->sole();
    $props       = crpProps(route('clinical-review.done'));

    expect($props['submission']['submission_hash'])->toBe($attestation->submission_hash)
        ->and($props['submission']['receipt_sent'])->toBeTrue()
        ->and($props['decisions'])->toHaveCount(1)
        ->and($props['decisions'][0]['label'])->toBe('Aprovo')
        ->and($props['decisions'][0]['title'])->toBe('Glaucoma')
        ->and(array_keys($props['t']))->toBe(['layout', 'done'])
        ->and($props['reviewerName'])->toBe($created['reviewer']->name);
});

// ── código de acesso (PIN) ──────────────────────────────────────────────────

it('com código de acesso, o link leva à tela do código e a revisão só abre depois dele', function () {
    $created = crfInvitation(pin: 'ABCD2345');

    crpOpen($created)->assertRedirect(route('clinical-review.code'));

    $this->get(route('clinical-review.show'))->assertRedirect(route('clinical-review.code'));
    $this->putJson(route('clinical-review.draft'), ['decisions' => []])->assertStatus(409)->assertJson(['message' => 'access_code_required']);
    $this->post(route('clinical-review.submit'), crpPayload($created))->assertRedirect(route('clinical-review.code'));

    $this->get(route('clinical-review.code'))->assertOk();

    $this->post(route('clinical-review.code.verify'), ['code' => 'ABCD2345'])->assertRedirect(route('clinical-review.show'));

    $this->get(route('clinical-review.show'))->assertOk();
    $this->get(route('clinical-review.code'))->assertRedirect(route('clinical-review.show'));
});

it('o código vale em minúsculas e com espaços; o errado volta com a mesma mensagem genérica', function () {
    $created = crfInvitation(pin: 'ABCD2345');
    crpOpen($created);

    $this->from(route('clinical-review.code'))
        ->post(route('clinical-review.code.verify'), ['code' => 'ZZZZ9999'])
        ->assertRedirect(route('clinical-review.code'))
        ->assertSessionHasErrors(['code' => __('clinical_review.public.code.invalid')]);

    expect($created['invitation']->fresh()->access_attempts)->toBe(1);

    $this->post(route('clinical-review.code.verify'), ['code' => ' abcd 2345 '])->assertRedirect(route('clinical-review.show'));
    expect($created['invitation']->fresh()->access_attempts)->toBe(0);
});

it('5 erros travam o código por 15 minutos — até o certo é recusado nesse intervalo', function () {
    $created = crfInvitation(pin: 'ABCD2345');
    crpOpen($created);

    foreach (range(1, 5) as $i) {
        $this->from(route('clinical-review.code'))->post(route('clinical-review.code.verify'), ['code' => 'ERRADO' . $i])->assertSessionHasErrors('code');
    }

    $locked = $created['invitation']->fresh();

    expect($locked->isAccessLocked())->toBeTrue()
        ->and($locked->access_locked_until->isSameMinute(now()->addMinutes(15)))->toBeTrue();

    // O código CERTO também é recusado, com a mesma mensagem (não confirma que era o certo).
    $this->from(route('clinical-review.code'))
        ->post(route('clinical-review.code.verify'), ['code' => 'ABCD2345'])
        ->assertSessionHasErrors(['code' => __('clinical_review.public.code.invalid')]);

    $this->get(route('clinical-review.show'))->assertRedirect(route('clinical-review.code'));

    $this->travel(16)->minutes();

    $this->post(route('clinical-review.code.verify'), ['code' => 'ABCD2345'])->assertRedirect(route('clinical-review.show'));
    $this->get(route('clinical-review.show'))->assertOk();
});

it('o código digitado vale só para o convite dele: outro convite no navegador pede o código de novo', function () {
    $first  = crfInvitation(versions: [crfFrozenVersion('glaucoma')], pin: 'ABCD2345');
    $second = crfInvitation(versions: [crfFrozenVersion('dmri')], pin: 'WXYZ6789');

    crpOpen($first);
    $this->post(route('clinical-review.code.verify'), ['code' => 'ABCD2345'])->assertRedirect(route('clinical-review.show'));

    crpOpen($second)->assertRedirect(route('clinical-review.code'));
    $this->get(route('clinical-review.show'))->assertRedirect(route('clinical-review.code'));
    $this->post(route('clinical-review.code.verify'), ['code' => 'ABCD2345'])->assertSessionHasErrors('code');
});

it('a tela do código tem teto por minuto, além do travamento do convite', function () {
    $created = crfInvitation(pin: 'ABCD2345');
    crpOpen($created);

    foreach (range(1, 10) as $i) {
        $this->post(route('clinical-review.code.verify'), ['code' => 'X' . $i]);
    }

    $this->post(route('clinical-review.code.verify'), ['code' => 'OUTRO'])->assertStatus(429);
});

it('convite sem código nunca mostra a tela do código', function () {
    $created = crfInvitation();
    crpOpen($created)->assertRedirect(route('clinical-review.show'));

    $this->get(route('clinical-review.code'))->assertRedirect(route('clinical-review.show'));
});

it('reenviar o convite zera o travamento e exige o código de novo', function () {
    $created = crfInvitation(pin: 'ABCD2345');
    crpOpen($created);

    foreach (range(1, 5) as $i) {
        $this->from(route('clinical-review.code'))->post(route('clinical-review.code.verify'), ['code' => 'ERRADO' . $i]);
    }

    $resent = app(ReviewInvitationService::class)->resend($created['invitation']);

    expect($resent['invitation']->fresh()->access_attempts)->toBe(0)->and($resent['invitation']->fresh()->isAccessLocked())->toBeFalse();

    $this->flushSession();
    $this->get(crfOpenUrl(['invitation' => $resent['invitation']->fresh()] + $resent))->assertRedirect(route('clinical-review.code'));
    $this->post(route('clinical-review.code.verify'), ['code' => 'ABCD2345'])->assertRedirect(route('clinical-review.show'));
});

// ── o link em si tem teto ───────────────────────────────────────────────────

it('o 1º salto tem teto por IP contra varredura', function () {
    $created = crfInvitation();
    $bad     = URL::temporarySignedRoute('clinical-review.open', now()->addDay(), ['invitation' => (string) Str::uuid(), 'token' => 'x']);

    foreach (range(1, 20) as $i) {
        $this->get($bad)->assertRedirect(route('clinical-review.unavailable'));
    }

    $this->get($bad)->assertStatus(429);
});

// ── a sessão e o idioma ─────────────────────────────────────────────────────

it('o 1º salto troca o ID da sessão (fixação de sessão): um cookie plantado antes do link não vale depois dele', function () {
    $name   = config('session.cookie');
    $first  = crfInvitation(versions: [crfFrozenVersion('glaucoma')]);
    $second = crfInvitation(versions: [crfFrozenVersion('dmri')]);

    $one = crpOpen($first);
    $id  = $one->getCookie($name)->getValue();

    // O navegador devolve o mesmo cookie ao abrir o próximo link: o ID TEM que mudar.
    $two = $this->withCookie($name, $id)->get(crfOpenUrl($second));

    expect($two->getCookie($name)->getValue())->not->toBe($id)
        ->and(session(EnsureClinicalReviewSession::SESSION_KEY . '.invitation_id'))->toBe($second['invitation']->id);
});

it('o código digitado vale só para o convite dele, mesmo que a sessão guarde outro convite', function () {
    $mine  = crfInvitation(versions: [crfFrozenVersion('glaucoma')], pin: 'ABCD2345');
    $other = crfInvitation(versions: [crfFrozenVersion('dmri')], pin: 'WXYZ6789');

    // Sessão do convite `mine`, mas com o "PIN já digitado" de OUTRO convite.
    $this->withSession([EnsureClinicalReviewSession::SESSION_KEY => [
        'invitation_id' => $mine['invitation']->id,
        'token_hash'    => $mine['invitation']->token_hash,
        'pin_ok'        => $other['invitation']->id,
    ]]);

    $this->get(route('clinical-review.show'))->assertRedirect(route('clinical-review.code'));

    $this->withSession([EnsureClinicalReviewSession::SESSION_KEY . '.pin_ok' => $mine['invitation']->id]);
    $this->get(route('clinical-review.show'))->assertOk();
});

it('a página troca de idioma por um endereço próprio (sem Referer) e volta para a mesma página', function () {
    $created = crfInvitation();
    crpEnter($created);

    $this->post(route('clinical-review.locale'), ['locale' => 'en', 'page' => 'show'])->assertRedirect(route('clinical-review.show'));
    expect(session('locale'))->toBe('en');

    $props = crpProps(route('clinical-review.show'));
    expect($props['t']['show']['title'])->toBe('Clinical review of follow-up protocols');

    $this->post(route('clinical-review.locale'), ['locale' => 'pt_BR', 'page' => 'unavailable'])->assertRedirect(route('clinical-review.unavailable'));
    $this->post(route('clinical-review.locale'), ['locale' => 'pt_BR'])->assertRedirect(route('clinical-review.unavailable'));
    expect(session('locale'))->toBe('pt_BR');
});

it('o idioma só aceita os suportados e as páginas da lista — nada de redirecionamento aberto', function () {
    $this->post(route('clinical-review.locale'), ['locale' => 'xx'])->assertSessionHasErrors('locale');
    $this->post(route('clinical-review.locale'), ['locale' => 'en', 'page' => 'https://exemplo.test'])->assertSessionHasErrors('page');
    $this->post(route('clinical-review.locale'), ['locale' => 'en', 'page' => '../admin'])->assertSessionHasErrors('page');
});

it('versão cujo hash não bate com o que o convite registrou aparece como indisponível', function () {
    $created = crfInvitation();
    $version = $created['versions'][0];

    // Adultera o hash gravado (só dá para fazer com o trigger de imutabilidade desligado).
    DB::unprepared('ALTER TABLE recall_protocol_versions DISABLE TRIGGER recall_protocol_versions_guard');
    DB::table('recall_protocol_versions')->where('id', $version->id)->update(['content_hash' => '1:' . str_repeat('0', 64)]);
    DB::unprepared('ALTER TABLE recall_protocol_versions ENABLE TRIGGER recall_protocol_versions_guard');

    crpEnter($created);

    $props = crpProps(route('clinical-review.show'));

    expect($props['available'])->toBeFalse()
        ->and($props['items'][0]['available'])->toBeFalse();
});

// ── sinal de vida e sessão expirada ─────────────────────────────────────────

it('o sinal de vida responde 204 com sessão válida e, sem ela, 409 com o destino (a página avisa cedo)', function () {
    $created = crfInvitation();

    $this->getJson(route('clinical-review.keepalive'))
        ->assertStatus(409)
        ->assertJson(['message' => 'unavailable', 'redirect' => route('clinical-review.unavailable')]);

    crpEnter($created);

    $this->getJson(route('clinical-review.keepalive'))->assertNoContent();
    crpAssertPublicHeaders($this->getJson(route('clinical-review.keepalive')));
});

it('o sinal de vida respeita o código de acesso e o convite já respondido', function () {
    Notification::fake();
    $pin = crfInvitation(versions: [crfFrozenVersion('glaucoma')], pin: 'ABCD2345');
    crpOpen($pin);

    $this->getJson(route('clinical-review.keepalive'))->assertStatus(409)->assertJson(['message' => 'access_code_required']);

    $done = crfInvitation(versions: [crfFrozenVersion('dmri')]);
    crpEnter($done);
    crfSubmit($done);

    $this->getJson(route('clinical-review.keepalive'))->assertStatus(409)->assertJson(['message' => 'answered', 'redirect' => route('clinical-review.done')]);
});

it('sessão expirada num envio (419): a mensagem fala do link do e-mail, não de "entrar novamente"', function () {
    Route::middleware('web')->get('/_teste/revisao-clinica-419', fn () => abort(419))->name('clinical-review.teste419');
    Route::middleware('web')->get('/_teste/outra-tela-419', fn () => abort(419))->name('panel.teste419');

    $review = $this->get('/_teste/revisao-clinica-419', crpInertiaHeaders());
    $other  = $this->get('/_teste/outra-tela-419', crpInertiaHeaders());

    $review->assertStatus(419);
    expect($review->getContent())->toContain('abra de novo o link do e-mail')->not->toContain('entre novamente');

    $other->assertStatus(419);
    expect($other->getContent())->toContain('entre novamente');
});
