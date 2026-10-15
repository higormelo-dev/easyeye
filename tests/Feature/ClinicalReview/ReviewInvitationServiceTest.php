<?php

declare(strict_types=1);

/**
 * Convites de revisão clínica (ReviewInvitationService): criar, enviar, reenviar, revogar,
 * expirar — e o que NUNCA pode acontecer (token em claro no banco, PIN no e-mail, convite
 * duplicado, versão fora de revisão em convite, envio que falha marcando "enviado").
 */

use App\Domains\ClinicalReview\Enums\ReviewInvitationStatus as Status;
use App\Domains\ClinicalReview\Exceptions\ReviewInvitationException;
use App\Domains\ClinicalReview\Models\{ClinicalGovernanceEvent, ClinicalReviewInvitation, ClinicalReviewItem};
use App\Domains\ClinicalReview\Notifications\ClinicalReviewInvitationNotification;
use App\Domains\ClinicalReview\Services\ReviewInvitationService;
use App\Domains\ClinicalReview\Support\{ReviewHashes, ReviewToken};
use App\Domains\Recall\Enums\ProtocolVersionState;
use App\Domains\Recall\Services\ProtocolCatalogService;
use App\Domains\Recall\Support\ProposedProtocolDrafts;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\{Collection, Str};
use Illuminate\Support\Facades\{DB, Hash, Mail, Notification, URL};

function rivService(): ReviewInvitationService
{
    return app(ReviewInvitationService::class);
}

/** Renderiza o e-mail no idioma da notificação (o despacho faz isso sozinho; aqui, fora dele, é na mão). */
function rivRenderMail(object $notification, object $notifiable): MailMessage
{
    $previous = app()->getLocale();
    app()->setLocale($notification->locale);

    try {
        return $notification->toMail($notifiable);
    } finally {
        app()->setLocale($previous);
    }
}

function rivEvents(string $event, ?string $subjectId = null): Collection
{
    return ClinicalGovernanceEvent::query()
        ->where('event', $event)
        ->when($subjectId, fn ($query) => $query->where('subject_id', $subjectId))
        ->get();
}

// ── criar ───────────────────────────────────────────────────────────────────

it('cria o convite pendente com itens, hash do conjunto, prazo e retrato do Revisor', function () {
    $actor   = User::factory()->create();
    $created = crfInvitation(actor: $actor);

    $invitation = $created['invitation']->fresh();
    $version    = $created['versions'][0];

    expect($invitation->status)->toBe(Status::Pending)
        ->and($invitation->reviewer_id)->toBe($created['reviewer']->id)
        ->and($invitation->reviewer_name)->toBe($created['reviewer']->name)
        ->and($invitation->reviewer_email)->toBe($created['reviewer']->email)
        ->and($invitation->sent_at)->toBeNull()
        ->and($invitation->draft_payload)->toBeNull()
        ->and($invitation->open_count)->toBe(0)
        ->and($invitation->expires_at->isSameDay(now()->addDays(14)))->toBeTrue();

    $item = $invitation->items()->sole();

    expect($item->subject_type)->toBe('recall_protocol_version')
        ->and($item->subject_id)->toBe($version->id)
        ->and($item->subject_key)->toBe('glaucoma')
        ->and($item->subject_version)->toBe(1)
        ->and($item->content_hash)->toBe($version->content_hash)
        ->and($item->decision)->toBeNull()
        ->and($invitation->set_hash)->toBe(ReviewHashes::setHash([[
            'subject_type' => 'recall_protocol_version', 'subject_key' => 'glaucoma', 'subject_version' => 1, 'content_hash' => $version->content_hash,
        ]]));
});

it('o token só existe na memória de quem criou: o banco guarda o sha256', function () {
    $created = crfInvitation();
    $row     = (array) DB::table('clinical_review_invitations')->where('id', $created['invitation']->id)->first();

    expect($row['token_hash'])->toBe(hash('sha256', $created['token']))
        ->and(ReviewToken::matches($created['token'], $row['token_hash']))->toBeTrue();

    foreach ($row as $column => $value) {
        $this->assertStringNotContainsString($created['token'], (string) $value, "token em claro na coluna {$column}");
    }

    foreach (ClinicalGovernanceEvent::query()->get() as $event) {
        expect(json_encode($event->toArray()))->not->toContain($created['token']);
    }
});

it('o prazo padrão é 14 dias e é limitado entre 1 e 30', function (int $asked, int $expectedDays) {
    $created = crfInvitation(days: $asked);

    expect($created['invitation']->fresh()->expires_at->isSameDay(now()->addDays($expectedDays)))->toBeTrue();
})->with([[14, 14], [0, 1], [-5, 1], [1, 1], [30, 30], [99, 30]]);

it('o código de acesso é normalizado, vira hash e não aparece em claro', function () {
    $created = crfInvitation(pin: ' ab cd 2345 ');

    $row = (array) DB::table('clinical_review_invitations')->where('id', $created['invitation']->id)->first();

    expect($created['accessCode'])->toBe('ABCD2345')
        ->and(Hash::check('ABCD2345', $row['access_code_hash']))->toBeTrue()
        ->and(Hash::check('abcd2345', $row['access_code_hash']))->toBeFalse()
        ->and($created['invitation']->requiresAccessCode())->toBeTrue();

    foreach ($row as $column => $value) {
        $this->assertStringNotContainsString('ABCD2345', (string) $value, "PIN em claro na coluna {$column}");
    }
});

it('sem código de acesso (nulo ou vazio) o convite não exige PIN', function (?string $pin) {
    expect(crfInvitation(pin: $pin)['invitation']->requiresAccessCode())->toBeFalse();
})->with([[null], [''], ['   ']]);

it('recusa código de acesso fora do formato (6 a 12 letras e números)', function (string $pin) {
    try {
        crfInvitation(pin: $pin);
        $this->fail('Era para recusar.');
    } catch (ReviewInvitationException $e) {
        expect($e->reason())->toBe(ReviewInvitationException::INVALID_ACCESS_CODE);
    }

    expect(ClinicalReviewInvitation::query()->count())->toBe(0);
})->with(['curto' => ['abc12'], 'longo' => ['abcdefghijklm'], 'símbolo' => ['abc-1234'], 'acento' => ['açaí1234']]);

it('grava o evento do convite e um "invited" por versão, com quem convidou', function () {
    $actor   = User::factory()->create();
    $created = crfInvitation(versions: [crfFrozenVersion('glaucoma', $actor), crfFrozenVersion('dmri', $actor)], pin: 'ABC12345', actor: $actor);

    $event = rivEvents('created', (string) $created['invitation']->id)->sole();

    expect($event->subject_type)->toBe('clinical_review_invitation')
        ->and($event->from_state)->toBeNull()
        ->and($event->to_state)->toBe('pending')
        ->and($event->actor_user_id)->toBe($actor->id)
        ->and($event->meta['pin_required'])->toBeTrue()
        ->and($event->meta['set_hash'])->toBe($created['invitation']->set_hash)
        ->and($event->meta['versions'])->toEqualCanonicalizing(['glaucoma v1', 'dmri v1'])
        ->and($event->meta['reviewer'])->toBe($created['reviewer']->crmLabel());

    foreach ($created['versions'] as $version) {
        $invited = rivEvents('invited', (string) $version->id)->sole();

        expect($invited->subject_type)->toBe('recall_protocol_version')
            ->and($invited->from_state)->toBe('in_review')
            ->and($invited->to_state)->toBe('in_review')
            ->and($invited->meta['invitation_id'])->toBe((string) $created['invitation']->id);
    }
});

it('convite com duas versões: itens das duas e o hash do conjunto das duas', function () {
    $glaucoma = crfFrozenVersion('glaucoma');
    $dmri     = crfFrozenVersion('dmri');
    $created  = crfInvitation(versions: [$dmri, $glaucoma]);

    $items = $created['invitation']->items()->get();

    expect($items->pluck('subject_key')->all())->toBe(['dmri', 'glaucoma'])
        ->and($created['invitation']->set_hash)->toBe(ReviewHashes::setHash([
            ['subject_type' => 'recall_protocol_version', 'subject_key' => 'glaucoma', 'subject_version' => 1, 'content_hash' => $glaucoma->content_hash],
            ['subject_type' => 'recall_protocol_version', 'subject_key' => 'dmri', 'subject_version' => 1, 'content_hash' => $dmri->content_hash],
        ]));
});

it('só versão EM REVISÃO entra num convite', function (ProtocolVersionState $state) {
    $version = crfFrozenVersion();
    DB::table('recall_protocol_versions')->where('id', $version->id)->update(['state' => $state->value]);

    try {
        crfInvitation(versions: [$version->fresh()]);
        $this->fail('Era para recusar.');
    } catch (ReviewInvitationException $e) {
        expect($e->reason())->toBe(ReviewInvitationException::VERSION_NOT_IN_REVIEW);
    }

    expect(ClinicalReviewInvitation::query()->count())->toBe(0)
        ->and(ClinicalReviewItem::query()->count())->toBe(0)
        ->and(rivEvents('created')->where('subject_type', 'clinical_review_invitation'))->toHaveCount(0);
})->with([ProtocolVersionState::Validated, ProtocolVersionState::Released, ProtocolVersionState::Declined, ProtocolVersionState::Withdrawn, ProtocolVersionState::Retired]);

it('rascunho também não entra', function () {
    $proposal = ProposedProtocolDrafts::all()['glaucoma'];
    $protocol = app(ProtocolCatalogService::class)->createProtocol('glaucoma', $proposal['translations'], $proposal['content']);

    expect(fn () => crfInvitation(versions: [$protocol->versions()->firstOrFail()]))->toThrow(ReviewInvitationException::class);
});

it('recusa sem versão, com versão inexistente e com Revisor inativo — sem gravar nada', function () {
    $reviewer = crfReviewer();
    $service  = rivService();
    $version  = crfFrozenVersion();

    $reason = function (callable $attempt): string {
        try {
            $attempt();
        } catch (ReviewInvitationException $e) {
            return $e->reason();
        }

        return 'nenhuma exceção';
    };

    expect($reason(fn () => $service->create($reviewer, [], null, 14)))->toBe(ReviewInvitationException::NO_VERSIONS)
        ->and($reason(fn () => $service->create($reviewer, [(string) Str::uuid()], null, 14)))->toBe(ReviewInvitationException::VERSION_NOT_FOUND)
        ->and($reason(fn () => $service->create(crfReviewer(['active' => false]), [(string) $version->id], null, 14)))->toBe(ReviewInvitationException::REVIEWER_INACTIVE);

    expect(ClinicalReviewInvitation::query()->count())->toBe(0);
});

it('não cria dois convites pendentes do mesmo Revisor para o mesmo conjunto', function () {
    $reviewer = crfReviewer();
    $version  = crfFrozenVersion();

    crfInvitation($reviewer, [$version]);

    try {
        crfInvitation($reviewer, [$version]);
        $this->fail('Era para recusar.');
    } catch (ReviewInvitationException $e) {
        expect($e->reason())->toBe(ReviewInvitationException::DUPLICATE_PENDING);
    }

    expect(ClinicalReviewInvitation::query()->count())->toBe(1)
        ->and(ClinicalReviewItem::query()->count())->toBe(1);
});

it('o mesmo conjunto pode ir a OUTRO Revisor, ou ao mesmo depois de revogado', function () {
    $reviewer = crfReviewer();
    $version  = crfFrozenVersion();

    $first = crfInvitation($reviewer, [$version]);

    expect(crfInvitation(crfReviewer(), [$version])['invitation']->id)->not->toBe($first['invitation']->id);

    rivService()->revoke($first['invitation'], 'Convite enviado com o PIN errado.');

    expect(crfInvitation($reviewer, [$version])['invitation']->status)->toBe(Status::Pending);
});

it('versões repetidas na lista viram um item só', function () {
    $version = crfFrozenVersion();

    $created = rivService()->create(crfReviewer(), [(string) $version->id, (string) $version->id], null, 14);

    expect($created['invitation']->items()->count())->toBe(1);
});

// ── enviar ──────────────────────────────────────────────────────────────────

it('envia o e-mail com o link assinado e só então grava sent_at e o evento', function () {
    Notification::fake();
    $created = crfInvitation();

    expect($created['invitation']->fresh()->sent_at)->toBeNull();

    $sent = rivService()->send($created['invitation'], $created['token']);

    expect($sent)->toBeTrue()
        ->and($created['invitation']->fresh()->sent_at)->not->toBeNull()
        ->and($created['invitation']->fresh()->last_send_error)->toBeNull()
        ->and(rivEvents('sent', (string) $created['invitation']->id))->toHaveCount(1);

    Notification::assertSentOnDemand(ClinicalReviewInvitationNotification::class, function ($notification, $channels, $notifiable) use ($created) {
        $mail = $notification->toMail($notifiable);

        return $notifiable->routes['mail'] === $created['reviewer']->email
            && $notification->url === crfOpenUrl($created)
            && str_contains($mail->actionUrl, $created['token'])
            && $notification->pinRequired === false
            && $notification->versions === [['title' => 'Glaucoma', 'version' => 1]];
    });
});

it('o e-mail em si: português, versões listadas, prazo, sem dado de paciente e SEM o código de acesso', function () {
    Notification::fake();
    app()->setLocale('en');
    $created = crfInvitation(pin: 'ZXCV2345');

    rivService()->send($created['invitation'], $created['token']);

    Notification::assertSentOnDemand(ClinicalReviewInvitationNotification::class, function ($notification, $channels, $notifiable) use ($created) {
        // O despacho aplica o idioma da notificação; aqui, fora dele, aplica-se na mão.
        $mail = rivRenderMail($notification, $notifiable);
        $text = implode("\n", [$mail->subject, $mail->greeting, ...$mail->introLines, $mail->actionText, ...$mail->outroLines, $mail->salutation]);

        expect($text)->toContain('convite para revisão clínica')
            ->toContain('Glaucoma — versão 1')
            ->toContain($created['invitation']->expires_at->format('d/m/Y'))
            ->toContain('código de acesso')
            ->toContain('A página não mostra dados de pacientes')
            // O PIN vai por OUTRO canal: nem o código nem o token (fora do link) entram no texto.
            ->not->toContain('ZXCV2345')
            ->not->toContain($created['token']);

        return $notification->pinRequired === true;
    });
});

it('falha de transporte não derruba nada: sent_at fica vazio, o erro é guardado e o convite segue pendente', function () {
    rivBreakMail();
    $created = crfInvitation();

    $sent = rivService()->send($created['invitation'], $created['token']);

    $invitation = $created['invitation']->fresh();

    expect($sent)->toBeFalse()
        ->and($invitation->sent_at)->toBeNull()
        ->and($invitation->status)->toBe(Status::Pending)
        ->and($invitation->last_send_error)->toContain('TransportException')
        ->and(rivEvents('send_failed', (string) $invitation->id))->toHaveCount(1)
        ->and(rivEvents('sent', (string) $invitation->id))->toHaveCount(0);
});

it('depois da falha, reenviar com o e-mail de volta marca sent_at e limpa o erro', function () {
    rivBreakMail();
    $created = crfInvitation();
    rivService()->send($created['invitation'], $created['token']);

    config(['mail.default' => 'array']);
    Notification::fake();

    $resent = rivService()->resend($created['invitation']);

    expect(rivService()->send($resent['invitation'], $resent['token']))->toBeTrue();

    $invitation = $created['invitation']->fresh();

    expect($invitation->sent_at)->not->toBeNull()->and($invitation->last_send_error)->toBeNull();
});

// ── reenviar ────────────────────────────────────────────────────────────────

it('reenviar GERA TOKEN NOVO: o link antigo deixa de valer, e o contador de PIN zera', function () {
    $created = crfInvitation(pin: 'ABC12345');
    $created['invitation']->forceFill(['access_attempts' => 4, 'access_locked_until' => now()->addMinutes(10), 'sent_at' => now()])->save();
    $oldHash = $created['invitation']->token_hash;

    $resent = rivService()->resend($created['invitation'], User::factory()->create());

    $fresh = $created['invitation']->fresh();

    expect($resent['token'])->not->toBe($created['token'])
        ->and($fresh->token_hash)->not->toBe($oldHash)
        ->and(ReviewToken::matches($created['token'], $fresh->token_hash))->toBeFalse()
        ->and(ReviewToken::matches($resent['token'], $fresh->token_hash))->toBeTrue()
        ->and($fresh->sent_at)->toBeNull()
        ->and($fresh->access_attempts)->toBe(0)
        ->and($fresh->access_locked_until)->toBeNull()
        ->and($fresh->status)->toBe(Status::Pending)
        ->and(rivEvents('resent', (string) $fresh->id))->toHaveCount(1);
});

it('só convite pendente e dentro do prazo pode ser reenviado', function () {
    $revoked = crfInvitation();
    rivService()->revoke($revoked['invitation'], 'Motivo qualquer do teste.');

    $submitted = crfInvitation();
    crfSubmit($submitted);

    $expired = crfInvitation();
    $expired['invitation']->forceFill(['expires_at' => now()->subMinute()])->save();

    foreach ([[$revoked, ReviewInvitationException::NOT_PENDING], [$submitted, ReviewInvitationException::NOT_PENDING], [$expired, ReviewInvitationException::EXPIRED]] as [$created, $reason]) {
        try {
            rivService()->resend($created['invitation']);
            $this->fail('Era para recusar.');
        } catch (ReviewInvitationException $e) {
            expect($e->reason())->toBe($reason);
        }
    }
});

// ── revogar ─────────────────────────────────────────────────────────────────

it('revoga: estado, quem, quando, motivo, rascunho apagado e evento', function () {
    $actor   = User::factory()->create();
    $created = crfInvitation();
    $created['invitation']->forceFill(['draft_payload' => ['decisions' => [], 'attestation' => ['name' => 'rascunho']], 'draft_saved_at' => now()])->save();

    rivService()->revoke($created['invitation'], '  Enviado ao Revisor errado.  ', $actor);

    $fresh = $created['invitation']->fresh();

    expect($fresh->status)->toBe(Status::Revoked)
        ->and($fresh->revoked_at)->not->toBeNull()
        ->and($fresh->revoked_by)->toBe($actor->id)
        ->and($fresh->revoked_reason)->toBe('Enviado ao Revisor errado.')
        ->and($fresh->draft_payload)->toBeNull()
        ->and($fresh->draft_saved_at)->toBeNull();

    $event = rivEvents('revoked', (string) $fresh->id)->sole();

    expect($event->from_state)->toBe('pending')->and($event->to_state)->toBe('revoked')
        ->and($event->reason)->toBe('Enviado ao Revisor errado.')
        ->and($event->actor_user_id)->toBe($actor->id);
});

it('revogar exige motivo de ao menos 10 caracteres e convite pendente', function () {
    $created = crfInvitation();

    foreach (['', '   ', 'curto'] as $reason) {
        try {
            rivService()->revoke($created['invitation'], $reason);
            $this->fail('Era para recusar.');
        } catch (ReviewInvitationException $e) {
            expect($e->reason())->toBe(ReviewInvitationException::REASON_TOO_SHORT);
        }
    }

    expect($created['invitation']->fresh()->status)->toBe(Status::Pending);

    rivService()->revoke($created['invitation'], 'Motivo com mais de dez.');

    expect(fn () => rivService()->revoke($created['invitation'], 'Revogar de novo o mesmo.'))->toThrow(ReviewInvitationException::class);
});

it('as versões continuam em revisão depois da revogação (e podem ir a um convite novo)', function () {
    $created = crfInvitation();
    rivService()->revoke($created['invitation'], 'Motivo qualquer do teste.');

    expect($created['versions'][0]->fresh()->state)->toBe(ProtocolVersionState::InReview);
});

it('revokePendingFor revoga só os pendentes do Revisor e devolve quantos', function () {
    $reviewer = crfReviewer();
    $mine     = crfInvitation($reviewer, [crfFrozenVersion('glaucoma')]);
    $mine2    = crfInvitation($reviewer, [crfFrozenVersion('dmri')]);
    $other    = crfInvitation(crfReviewer(), [crfFrozenVersion('ceratocone')]);
    $done     = crfInvitation($reviewer, [crfFrozenVersion('retinopatia_diabetica')]);
    crfSubmit($done);

    $count = rivService()->revokePendingFor($reviewer, 'Revisor desativado.');

    expect($count)->toBe(2)
        ->and($mine['invitation']->fresh()->status)->toBe(Status::Revoked)
        ->and($mine2['invitation']->fresh()->status)->toBe(Status::Revoked)
        ->and($other['invitation']->fresh()->status)->toBe(Status::Pending)
        ->and($done['invitation']->fresh()->status)->toBe(Status::Submitted);
});

// ── expirar ─────────────────────────────────────────────────────────────────

it('expira os pendentes vencidos, apaga o rascunho e é idempotente', function () {
    $overdue = crfInvitation(versions: [crfFrozenVersion('glaucoma')]);
    $overdue['invitation']->forceFill(['expires_at' => now()->subSecond(), 'draft_payload' => ['decisions' => []], 'draft_saved_at' => now()])->save();

    $future = crfInvitation(versions: [crfFrozenVersion('dmri')]);

    $answered = crfInvitation(versions: [crfFrozenVersion('ceratocone')]);
    crfSubmit($answered);
    $answered['invitation']->forceFill(['expires_at' => now()->subDay()])->save();

    expect(rivService()->expireStale())->toBe(1)
        ->and(rivService()->expireStale())->toBe(0);

    $fresh = $overdue['invitation']->fresh();

    expect($fresh->status)->toBe(Status::Expired)
        ->and($fresh->draft_payload)->toBeNull()
        ->and($future['invitation']->fresh()->status)->toBe(Status::Pending)
        ->and($answered['invitation']->fresh()->status)->toBe(Status::Submitted);

    $event = rivEvents('expired', (string) $fresh->id)->sole();

    expect($event->actor_label)->toBe('system')->and($event->from_state)->toBe('pending')->and($event->to_state)->toBe('expired');
});

it('o prazo é estrito: só expira DEPOIS do instante, não nele', function () {
    $created = crfInvitation();
    $created['invitation']->forceFill(['expires_at' => now()->addSeconds(30)])->save();

    expect(rivService()->expireStale())->toBe(0)
        ->and(rivService()->expireStale(now()->addMinute()))->toBe(1);
});

it('o comando clinical-review:expire expira e avisa quantos', function () {
    $created = crfInvitation();
    $created['invitation']->forceFill(['expires_at' => now()->subMinute()])->save();

    $this->artisan('clinical-review:expire')->expectsOutput('Convites de revisão expirados: 1')->assertExitCode(0);

    expect($created['invitation']->fresh()->status)->toBe(Status::Expired);
});

// ── link ────────────────────────────────────────────────────────────────────

it('o link é assinado, leva convite e token e vale até o fim do prazo', function () {
    $created = crfInvitation();
    $url     = crfOpenUrl($created);

    expect($url)->toContain('/revisao-clinica/abrir/' . $created['invitation']->id . '/' . $created['token'])
        ->and($url)->toContain('signature=')
        ->and($url)->toContain('expires=' . $created['invitation']->expires_at->getTimestamp())
        ->and(URL::hasValidSignature(Request::create($url)))->toBeTrue()
        ->and(URL::hasValidSignature(Request::create(str_replace($created['token'], 'outro-token', $url))))->toBeFalse();

    $this->travelTo($created['invitation']->expires_at->addSecond());

    expect(URL::hasValidSignature(Request::create($url)))->toBeFalse();
});
