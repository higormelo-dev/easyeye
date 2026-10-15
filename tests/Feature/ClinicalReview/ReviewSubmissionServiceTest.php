<?php

declare(strict_types=1);

/**
 * O envio do parecer (ReviewSubmissionService): um POST, uma decisão por versão, o atestado
 * com o texto exato da declaração — tudo numa transação, sob trava. E, sobretudo, o que o
 * envio RECUSA sem deixar rastro.
 */

use App\Domains\ClinicalReview\Enums\{ReviewDecision, ReviewInvitationStatus as Status};
use App\Domains\ClinicalReview\Exceptions\ReviewSubmissionException;
use App\Domains\ClinicalReview\Models\{ClinicalGovernanceEvent, ClinicalReviewAttestation, ClinicalReviewInvitation, ClinicalReviewItem};
use App\Domains\ClinicalReview\Notifications\ClinicalReviewReceiptNotification;
use App\Domains\ClinicalReview\Services\{GovernanceLog, ReviewInvitationService, ReviewSubmissionService};
use App\Domains\ClinicalReview\Support\{AttestationStatement, ReviewHashes};
use App\Domains\Recall\Enums\ProtocolVersionState;
use App\Models\User;
use Illuminate\Support\Facades\{DB, Notification};
use Illuminate\Support\Str;

/** Tenta o envio e devolve o código da recusa (ou 'nenhuma exceção'). */
function rssReason(callable $attempt): string
{
    try {
        $attempt();
    } catch (ReviewSubmissionException $e) {
        return $e->reason();
    }

    return 'nenhuma exceção';
}

/** Nada foi gravado: convite pendente, itens sem decisão, sem atestado, sem evento de envio. */
function rssNothingPersisted(ClinicalReviewInvitation $invitation): bool
{
    return $invitation->fresh()->status === Status::Pending
        && ClinicalReviewItem::query()->where('invitation_id', $invitation->id)->whereNotNull('decision')->count() === 0
        && ClinicalReviewAttestation::query()->where('invitation_id', $invitation->id)->count() === 0
        && ClinicalGovernanceEvent::query()->whereIn('event', ['submitted', 'review_submitted'])->count() === 0;
}

// ── o que grava ─────────────────────────────────────────────────────────────

it('grava as decisões, o atestado e fecha o convite — com o texto EXATO da declaração', function () {
    Notification::fake();
    $created = crfInvitation();
    $created['invitation']->forceFill(['draft_payload' => ['decisions' => []], 'draft_saved_at' => now()])->save();

    $attestation = crfSubmit($created, 'changes_requested', 'Campimetria a cada 9 meses é mais prudente.');

    $invitation = $created['invitation']->fresh();
    $item       = $invitation->items()->sole();
    $statement  = AttestationStatement::current();

    expect($invitation->status)->toBe(Status::Submitted)
        ->and($invitation->submitted_at)->not->toBeNull()
        ->and($invitation->draft_payload)->toBeNull()
        ->and($invitation->draft_saved_at)->toBeNull()
        ->and($item->decision)->toBe(ReviewDecision::ChangesRequested)
        ->and($item->comment)->toBe('Campimetria a cada 9 meses é mais prudente.')
        ->and($item->decided_at)->not->toBeNull()
        ->and($attestation->invitation_id)->toBe($invitation->id)
        ->and($attestation->declared_name)->toBe($created['reviewer']->name)
        ->and($attestation->declared_email)->toBe($created['reviewer']->email)
        ->and($attestation->declared_crm_number)->toBe($created['reviewer']->crm_number)
        ->and($attestation->declared_crm_uf)->toBe('SP')
        ->and($attestation->declared_rqe)->toBe($created['reviewer']->rqe)
        ->and($attestation->statement_term_version_id)->toBe($statement->id)
        ->and($attestation->statement_text)->toBe($statement->content)
        ->and($attestation->statement_sha256)->toBe(hash('sha256', $statement->content))
        ->and($attestation->conflict_of_interest)->toBeFalse()
        ->and($attestation->allow_identity_display)->toBeTrue()
        ->and($attestation->ip)->toBe('203.0.113.7')
        ->and($attestation->user_agent)->toBe('Mozilla/5.0 (teste)')
        ->and($attestation->set_hash)->toBe($invitation->set_hash)
        ->and($attestation->signed_at->equalTo($invitation->submitted_at))->toBeTrue();
});

it('o submission_hash é recalculável só com o que ficou gravado (a prova se verifica sozinha)', function () {
    Notification::fake();
    $created             = crfInvitation(versions: [crfFrozenVersion('glaucoma'), crfFrozenVersion('dmri')]);
    $decisions           = crfDecisions($created['invitation']);
    $firstId             = array_key_first($decisions);
    $decisions[$firstId] = ['decision' => 'rejected', 'comment' => 'Intervalo curto demais para este quadro.', 'proposed_changes' => 'Subir o máximo para 18 meses.'];

    $attestation = crfSubmit($created, decisions: $decisions, attestation: ['has_conflict' => true, 'conflict_description' => 'Palestrante em evento patrocinado.', 'allow_identity_display' => false]);

    $fresh = ClinicalReviewAttestation::query()->findOrFail($attestation->id);
    $items = ClinicalReviewItem::query()->where('invitation_id', $fresh->invitation_id)->get();

    $recomputed = ReviewHashes::submissionHash(
        (string) $fresh->set_hash,
        $items->map(fn (ClinicalReviewItem $item): array => [
            'subject_id'       => (string) $item->id,
            'decision'         => $item->decision->value,
            'comment'          => $item->comment,
            'proposed_changes' => $item->proposed_changes,
        ])->all(),
        [
            'name'                   => $fresh->declared_name,
            'email'                  => $fresh->declared_email,
            'crm_number'             => $fresh->declared_crm_number,
            'crm_uf'                 => $fresh->declared_crm_uf,
            'rqe'                    => $fresh->declared_rqe,
            'specialty'              => $fresh->declared_specialty,
            'statement_sha256'       => $fresh->statement_sha256,
            'conflict_of_interest'   => $fresh->conflict_of_interest,
            'conflict_description'   => $fresh->conflict_description,
            'allow_identity_display' => $fresh->allow_identity_display,
        ],
        $fresh->signed_at,
    );

    expect($recomputed)->toBe($fresh->submission_hash)
        ->and($fresh->conflict_of_interest)->toBeTrue()
        ->and($fresh->conflict_description)->toBe('Palestrante em evento patrocinado.')
        ->and($fresh->allow_identity_display)->toBeFalse();
});

it('decisões diferentes por versão: aprovo, aprovo com ajustes, não aprovo', function () {
    Notification::fake();
    $created = crfInvitation(versions: [crfFrozenVersion('glaucoma'), crfFrozenVersion('dmri'), crfFrozenVersion('ceratocone')]);
    $items   = $created['invitation']->items()->get();

    $decisions = [
        (string) $items[0]->id => ['decision' => 'approved'],
        (string) $items[1]->id => ['decision' => 'changes_requested', 'comment' => 'Reavaliar o intervalo do OCT.', 'proposed_changes' => 'OCT a cada 9 meses.'],
        (string) $items[2]->id => ['decision' => 'rejected', 'comment' => 'Sem base para o número de 6 meses.'],
    ];

    crfSubmit($created, decisions: $decisions);

    $saved = $created['invitation']->items()->get()->keyBy('id');

    expect($saved[$items[0]->id]->decision)->toBe(ReviewDecision::Approved)
        ->and($saved[$items[0]->id]->comment)->toBeNull()
        ->and($saved[$items[1]->id]->decision)->toBe(ReviewDecision::ChangesRequested)
        ->and($saved[$items[1]->id]->proposed_changes)->toBe('OCT a cada 9 meses.')
        ->and($saved[$items[2]->id]->decision)->toBe(ReviewDecision::Rejected);
});

it('normaliza o que o Revisor declarou: CRM sem pontuação/zeros, e-mail em minúsculas, conflito só se declarado', function () {
    Notification::fake();
    $created = crfInvitation();

    $attestation = crfSubmit($created, attestation: [
        'crm_number'           => '0' . $created['reviewer']->crm_number,
        'rqe'                  => ' ' . $created['reviewer']->rqe . ' ',
        'crm_uf'               => 'sp',
        'email'                => strtoupper($created['reviewer']->email),
        'specialty'            => '',
        'has_conflict'         => false,
        'conflict_description' => 'texto que não deve ficar sem conflito',
    ]);

    expect($attestation->declared_crm_number)->toBe($created['reviewer']->crm_number)
        ->and($attestation->declared_rqe)->toBe($created['reviewer']->rqe)
        ->and($attestation->declared_crm_uf)->toBe('SP')
        ->and($attestation->declared_email)->toBe($created['reviewer']->email)
        ->and($attestation->declared_specialty)->toBe('Oftalmologia')
        ->and($attestation->conflict_description)->toBeNull();
});

it('grava o evento do convite e um "review_submitted" por versão, atribuídos ao Revisor', function () {
    Notification::fake();
    $created = crfInvitation();

    $attestation = crfSubmit($created, 'rejected', 'Não concordo com o intervalo máximo.');

    $event = ClinicalGovernanceEvent::query()->where('event', 'submitted')->sole();

    expect($event->subject_type)->toBe('clinical_review_invitation')
        ->and($event->subject_id)->toBe((string) $created['invitation']->id)
        ->and($event->from_state)->toBe('pending')
        ->and($event->to_state)->toBe('submitted')
        ->and($event->actor_user_id)->toBeNull()
        ->and($event->actor_label)->toBe('reviewer:' . $created['reviewer']->id)
        ->and($event->meta['submission_hash'])->toBe($attestation->submission_hash)
        ->and($event->meta['identity_matches_registry'])->toBeTrue()
        ->and($event->meta['identity_mismatch_fields'])->toBe([]);

    $versionEvent = ClinicalGovernanceEvent::query()->where('event', 'review_submitted')->sole();

    expect($versionEvent->subject_type)->toBe('recall_protocol_version')
        ->and($versionEvent->subject_id)->toBe((string) $created['versions'][0]->id)
        ->and($versionEvent->meta['decision'])->toBe('rejected')
        ->and($versionEvent->meta['submission_hash'])->toBe($attestation->submission_hash)
        // A versão continua em revisão: quem a move é o aceite da EasyEye, não o parecer.
        ->and($created['versions'][0]->fresh()->state)->toBe(ProtocolVersionState::InReview);
});

it('identidade declarada diferente do cadastro NÃO bloqueia, mas fica registrada', function () {
    Notification::fake();
    $created = crfInvitation();

    crfSubmit($created, attestation: ['crm_number' => '99999', 'rqe' => '88888']);

    $event = ClinicalGovernanceEvent::query()->where('event', 'submitted')->sole();

    expect($event->meta['identity_matches_registry'])->toBeFalse()
        ->and($event->meta['identity_mismatch_fields'])->toEqualCanonicalizing(['crm_number', 'rqe']);
});

// ── recibo ──────────────────────────────────────────────────────────────────

it('manda o recibo por e-mail ao Revisor, com o código de verificação, e marca receipt_sent_at', function () {
    Notification::fake();
    $created = crfInvitation();

    $attestation = crfSubmit($created, 'changes_requested', 'Ajustar a campimetria para 9 meses.');

    Notification::assertSentOnDemand(ClinicalReviewReceiptNotification::class, function ($notification, $channels, $notifiable) use ($created, $attestation) {
        return $notifiable->routes['mail'] === $created['reviewer']->email
            && $notification->submissionHash === $attestation->submission_hash
            && $notification->decisions === [['title' => 'Glaucoma', 'version' => 1, 'decision' => 'Aprovo com ajustes']];
    });

    expect($attestation->fresh()->receipt_sent_at)->not->toBeNull();
});

it('o e-mail do recibo não leva o texto do parecer nem dado de paciente', function () {
    Notification::fake();
    $created = crfInvitation();

    crfSubmit($created, 'rejected', 'SEGREDO-DO-PARECER não deve sair por e-mail.');

    Notification::assertSentOnDemand(ClinicalReviewReceiptNotification::class, function ($notification, $channels, $notifiable) {
        $previous = app()->getLocale();
        app()->setLocale($notification->locale);
        $mail = $notification->toMail($notifiable);
        app()->setLocale($previous);

        $text = implode("\n", [$mail->subject, $mail->greeting, ...$mail->introLines, ...$mail->outroLines, $mail->salutation]);

        expect($text)->toContain('Código de verificação')->toContain($notification->submissionHash)->not->toContain('SEGREDO-DO-PARECER');

        return true;
    });
});

it('se o recibo não sai, o parecer continua gravado e receipt_sent_at fica vazio', function () {
    rivBreakMail();
    $created = crfInvitation();

    $attestation = crfSubmit($created);

    expect($created['invitation']->fresh()->status)->toBe(Status::Submitted)
        ->and($attestation->fresh()->receipt_sent_at)->toBeNull();
});

// ── recusas, sem rastro ─────────────────────────────────────────────────────

it('recusa decisão faltando, decisão inválida e justificativa ausente/curta nas que não são "aprovo"', function () {
    $created = crfInvitation(versions: [crfFrozenVersion('glaucoma'), crfFrozenVersion('dmri')]);
    $ids     = $created['invitation']->items()->pluck('id')->map(fn ($id) => (string) $id)->all();

    $cases = [
        'falta uma decisão'        => [[$ids[0] => ['decision' => 'approved']], ReviewSubmissionException::MISSING_DECISION],
        'nenhuma decisão'          => [[], ReviewSubmissionException::MISSING_DECISION],
        'decisão inválida'         => [[$ids[0] => ['decision' => 'approved'], $ids[1] => ['decision' => 'talvez']], ReviewSubmissionException::MISSING_DECISION],
        'ajuste sem texto'         => [[$ids[0] => ['decision' => 'approved'], $ids[1] => ['decision' => 'changes_requested']], ReviewSubmissionException::COMMENT_REQUIRED],
        'rejeição com texto curto' => [[$ids[0] => ['decision' => 'rejected', 'comment' => 'curto'], $ids[1] => ['decision' => 'approved']], ReviewSubmissionException::COMMENT_REQUIRED],
        'rejeição só com espaço'   => [[$ids[0] => ['decision' => 'rejected', 'comment' => '            '], $ids[1] => ['decision' => 'approved']], ReviewSubmissionException::COMMENT_REQUIRED],
    ];

    foreach ($cases as $name => [$decisions, $reason]) {
        expect(rssReason(fn () => crfSubmit($created, decisions: $decisions)))->toBe($reason, $name)
            ->and(rssNothingPersisted($created['invitation']))->toBeTrue($name);
    }
});

it('só "aprovo" dispensa justificativa', function () {
    Notification::fake();
    $created = crfInvitation();

    expect(rssReason(fn () => crfSubmit($created, 'approved')))->toBe('nenhuma exceção');
});

it('ignora decisão para item que não é do convite: não decide por versão alheia', function () {
    Notification::fake();
    $mine  = crfInvitation();
    $other = crfInvitation(versions: [crfFrozenVersion('dmri')]);

    $decisions             = crfDecisions($mine['invitation']);
    $foreignId             = (string) $other['invitation']->items()->sole()->id;
    $decisions[$foreignId] = ['decision' => 'rejected', 'comment' => 'tentando decidir o item de outro convite'];

    crfSubmit($mine, decisions: $decisions);

    expect($other['invitation']->items()->sole()->fresh()->decision)->toBeNull()
        ->and($other['invitation']->fresh()->status)->toBe(Status::Pending)
        ->and(ClinicalReviewItem::query()->whereNotNull('decision')->count())->toBe(1);
});

it('recusa sem concordar com a declaração e sem descrever o conflito declarado', function () {
    $created = crfInvitation();

    expect(rssReason(fn () => crfSubmit($created, attestation: ['agreed' => false])))->toBe(ReviewSubmissionException::STATEMENT_NOT_ACCEPTED)
        ->and(rssReason(fn () => crfSubmit($created, attestation: ['has_conflict' => true, 'conflict_description' => '   '])))->toBe(ReviewSubmissionException::CONFLICT_DESCRIPTION_REQUIRED)
        ->and(rssNothingPersisted($created['invitation']))->toBeTrue();
});

it('recusa se a declaração mudou depois que o Revisor a leu', function () {
    $created   = crfInvitation();
    $statement = AttestationStatement::current();
    $service   = app(ReviewSubmissionService::class);

    $wrongVersion = fn () => $service->submit($created['invitation'], crfDecisions($created['invitation']), crfAttestation($created['reviewer']), (string) Str::uuid(), hash('sha256', $statement->content), '1.2.3.4', 'ua');
    $wrongHash    = fn () => $service->submit($created['invitation'], crfDecisions($created['invitation']), crfAttestation($created['reviewer']), (string) $statement->id, hash('sha256', 'outro texto'), '1.2.3.4', 'ua');

    expect(rssReason($wrongVersion))->toBe(ReviewSubmissionException::STATEMENT_CHANGED)
        ->and(rssReason($wrongHash))->toBe(ReviewSubmissionException::STATEMENT_CHANGED)
        ->and(rssNothingPersisted($created['invitation']))->toBeTrue();
});

it('recusa se o conjunto mudou: versão retirada da revisão, ou hash diferente', function (string $change) {
    $created = crfInvitation(versions: [crfFrozenVersion('glaucoma'), crfFrozenVersion('dmri')]);

    match ($change) {
        'retirada'            => DB::table('recall_protocol_versions')->where('id', $created['versions'][1]->id)->update(['state' => 'withdrawn']),
        'aposentada'          => DB::table('recall_protocol_versions')->where('id', $created['versions'][0]->id)->update(['state' => 'retired']),
        'hash do item'        => DB::table('clinical_review_items')->where('subject_id', $created['versions'][0]->id)->update(['content_hash' => '1:' . str_repeat('9', 64)]),
        'set_hash do convite' => DB::table('clinical_review_invitations')->where('id', $created['invitation']->id)->update(['set_hash' => '1:' . str_repeat('8', 64)]),
    };

    expect(rssReason(fn () => crfSubmit($created)))->toBe(ReviewSubmissionException::SET_CHANGED)
        ->and(rssNothingPersisted($created['invitation']))->toBeTrue();
})->with(['retirada', 'aposentada', 'hash do item', 'set_hash do convite']);

it('recusa convite que não está aberto: expirado, revogado, já respondido', function () {
    $expired = crfInvitation(versions: [crfFrozenVersion('glaucoma')]);
    $expired['invitation']->forceFill(['expires_at' => now()->subSecond()])->save();

    $revoked = crfInvitation(versions: [crfFrozenVersion('dmri')]);
    app(ReviewInvitationService::class)->revoke($revoked['invitation'], 'Motivo qualquer do teste.');

    Notification::fake();
    $answered = crfInvitation(versions: [crfFrozenVersion('ceratocone')]);
    crfSubmit($answered);

    foreach ([$expired, $revoked, $answered] as $created) {
        expect(rssReason(fn () => crfSubmit($created)))->toBe(ReviewSubmissionException::NOT_OPEN);
    }

    expect(ClinicalReviewAttestation::query()->count())->toBe(1);
});

it('um segundo envio do mesmo convite é recusado e não duplica nada', function () {
    Notification::fake();
    $created = crfInvitation();

    crfSubmit($created, 'rejected', 'Primeira decisão, a que vale.');

    expect(rssReason(fn () => crfSubmit($created, 'approved')))->toBe(ReviewSubmissionException::NOT_OPEN)
        ->and(ClinicalReviewAttestation::query()->count())->toBe(1)
        ->and($created['invitation']->items()->sole()->decision)->toBe(ReviewDecision::Rejected)
        ->and(ClinicalGovernanceEvent::query()->where('event', 'submitted')->count())->toBe(1);
});

it('é tudo ou nada: se o evento de governança falha, nada do parecer fica', function () {
    $created = crfInvitation();

    app()->bind(GovernanceLog::class, fn () => new class() extends GovernanceLog {
        public function record(string $subjectType, string $subjectId, string $event, ?string $fromState = null, ?string $toState = null, ?User $actor = null, ?string $reason = null, array $meta = [], ?string $actorLabel = null): ClinicalGovernanceEvent
        {
            throw new RuntimeException('log indisponível');
        }
    });

    expect(fn () => app(ReviewSubmissionService::class)->submit(
        $created['invitation'],
        crfDecisions($created['invitation']),
        crfAttestation($created['reviewer']),
        (string) AttestationStatement::current()->id,
        hash('sha256', AttestationStatement::current()->content),
        '1.2.3.4',
        'ua',
    ))->toThrow(RuntimeException::class);

    expect(rssNothingPersisted($created['invitation']))->toBeTrue();
});

// ── a prova não muda depois ─────────────────────────────────────────────────

it('a decisão gravada não muda: nem pelo model, nem pelo banco', function () {
    Notification::fake();
    $created = crfInvitation();
    crfSubmit($created, 'rejected', 'Decisão gravada uma vez só.');

    $item = $created['invitation']->items()->sole();

    if (DB::getDriverName() === 'pgsql') {
        foreach (['decision' => 'approved', 'comment' => 'reescrito', 'content_hash' => '1:' . str_repeat('7', 64)] as $column => $value) {
            expect(rivRejected(fn () => DB::table('clinical_review_items')->where('id', $item->id)->update([$column => $value])))->toBeTrue($column);
        }

        expect(rivRejected(fn () => DB::table('clinical_review_items')->where('id', $item->id)->delete()))->toBeTrue();
    }

    expect($item->fresh()->decision)->toBe(ReviewDecision::Rejected)->and($item->fresh()->comment)->toBe('Decisão gravada uma vez só.');
});

it('o atestado é append-only: só o recibo pode ser marcado depois', function () {
    Notification::fake();
    $created     = crfInvitation();
    $attestation = crfSubmit($created);

    // Model: qualquer campo, menos receipt_sent_at.
    expect(fn () => $attestation->fresh()->forceFill(['declared_name' => 'OUTRO NOME'])->save())->toThrow(LogicException::class)
        ->and(fn () => $attestation->fresh()->delete())->toThrow(LogicException::class);

    // Banco: o mesmo, por SQL.
    if (DB::getDriverName() === 'pgsql') {
        foreach (['declared_name' => 'OUTRO NOME', 'statement_text' => 'reescrito', 'submission_hash' => '1:' . str_repeat('6', 64), 'ip' => '9.9.9.9'] as $column => $value) {
            expect(rivRejected(fn () => DB::table('clinical_review_attestations')->where('id', $attestation->id)->update([$column => $value])))->toBeTrue($column);
        }

        expect(rivRejected(fn () => DB::table('clinical_review_attestations')->where('id', $attestation->id)->delete()))->toBeTrue()
            ->and(DB::table('clinical_review_attestations')->where('id', $attestation->id)->update(['receipt_sent_at' => now()]))->toBe(1);
    }

    expect($attestation->fresh()->declared_name)->toBe($created['reviewer']->name);
});
