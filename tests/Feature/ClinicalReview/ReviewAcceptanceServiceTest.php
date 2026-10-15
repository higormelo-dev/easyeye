<?php

declare(strict_types=1);

/**
 * O aceite da EasyEye (conferência do CRM/UF e do RQE no portal do CFM): o que muda no convite, em
 * cada versão e no log de governança — e tudo ou nada (ADR 0002, item 4).
 */

use App\Domains\ClinicalReview\Enums\{AcceptanceDecision as Decision, ReviewInvitationStatus as Status};
use App\Domains\ClinicalReview\Exceptions\ReviewAcceptanceException as Rejected;
use App\Domains\ClinicalReview\Models\{ClinicalGovernanceEvent, ClinicalReviewAcceptance};
use App\Domains\ClinicalReview\Services\{GovernanceLog, ReviewAcceptanceService};
use App\Domains\Recall\Enums\ProtocolVersionState as State;
use App\Models\User;
use Illuminate\Support\Facades\{DB, Notification};

beforeEach(function () {
    Notification::fake();
    $this->actor = User::factory()->create(['name' => 'Conferente CFM']);
});

/** Convite já respondido (`submitted`) pronto para o aceite. */
function raSubmitted(array $keys = ['glaucoma'], string $decision = 'approved', ?string $comment = null, array $attestation = [], ?array $decisions = null): array
{
    $versions = array_map(static fn (string $key) => crfFrozenVersion($key), $keys);
    $created  = crfInvitation(null, $versions);

    crfSubmit($created, $decision, $comment, $attestation, $decisions);

    return $created + ['invitation' => $created['invitation']->fresh()];
}

function raEvents(string $subjectId, string $event)
{
    return ClinicalGovernanceEvent::query()->where('subject_id', $subjectId)->where('event', $event)->get();
}

function raAccept(array $created, bool $crm = true, bool $rqe = true, ?string $note = null, ?User $actor = null): ClinicalReviewAcceptance
{
    return app(ReviewAcceptanceService::class)->record($created['invitation'], Decision::Accepted, $crm, $rqe, $note, $actor);
}

it('aceitar: o convite vira accepted e a versão APROVADA vira validated, apontando este aceite', function () {
    $created = raSubmitted();
    $version = $created['versions'][0];

    $acceptance = raAccept($created, actor: $this->actor);

    $fresh = $version->fresh();

    expect($created['invitation']->fresh()->status)->toBe(Status::Accepted)
        ->and($fresh->state)->toBe(State::Validated)
        ->and($fresh->validated_acceptance_id)->toBe($acceptance->id)
        ->and($fresh->validated_at)->not->toBeNull()
        ->and($fresh->lock_version)->toBe($version->lock_version + 1)
        ->and($fresh->content_hash)->toBe($version->content_hash)
        ->and($acceptance->decision)->toBe(Decision::Accepted)
        ->and($acceptance->accepted_by)->toBe($this->actor->id)
        ->and($acceptance->accepted_by_label)->toBe('CONFERENTE CFM')
        ->and($acceptance->verification_method)->toBe('cfm_portal')
        ->and($acceptance->crm_checked)->toBeTrue()
        ->and($acceptance->rqe_checked)->toBeTrue()
        ->and($acceptance->note)->toBeNull();
});

it('versão que o Revisor NÃO aprovou (ajuste ou rejeição) vira declined, não validated', function (string $decision) {
    $created = raSubmitted(decision: $decision, comment: 'Reduzir a campimetria para 9 meses.');

    raAccept($created);

    expect($created['versions'][0]->fresh()->state)->toBe(State::Declined)
        ->and($created['versions'][0]->fresh()->validated_acceptance_id)->toBeNull()
        ->and($created['versions'][0]->fresh()->validated_at)->toBeNull()
        ->and($created['invitation']->fresh()->status)->toBe(Status::Accepted);
})->with(['changes_requested', 'rejected']);

it('convite com várias versões: cada uma segue a decisão dela', function () {
    $versions = [crfFrozenVersion('glaucoma'), crfFrozenVersion('dmri'), crfFrozenVersion('ceratocone')];
    $created  = crfInvitation(null, $versions);

    $decisions = [];

    foreach ($created['invitation']->items()->get() as $item) {
        $decisions[(string) $item->id] = match ($item->subject_key) {
            'glaucoma'   => ['decision' => 'approved', 'comment' => null, 'proposed_changes' => null],
            'dmri'       => ['decision' => 'changes_requested', 'comment' => 'Ajustar o intervalo da OCT de mácula.', 'proposed_changes' => 'OCT a cada 4 meses.'],
            'ceratocone' => ['decision' => 'rejected', 'comment' => 'Não concordo com a topografia semestral.', 'proposed_changes' => null],
        };
    }

    crfSubmit($created, decisions: $decisions);
    raAccept($created + ['invitation' => $created['invitation']->fresh()]);

    expect($versions[0]->fresh()->state)->toBe(State::Validated)
        ->and($versions[1]->fresh()->state)->toBe(State::Declined)
        ->and($versions[2]->fresh()->state)->toBe(State::Declined);
});

it('grava o evento do convite e um por versão, no mesmo ato, com o ator e a prova (aceite, hash)', function () {
    $created = raSubmitted(['glaucoma', 'dmri']);

    $acceptance = raAccept($created, actor: $this->actor);

    $invitationEvent = raEvents((string) $created['invitation']->id, 'accepted')->sole();

    expect($invitationEvent->from_state)->toBe('submitted')
        ->and($invitationEvent->to_state)->toBe('accepted')
        ->and($invitationEvent->actor_user_id)->toBe($this->actor->id)
        ->and($invitationEvent->meta['acceptance_id'])->toBe($acceptance->id)
        ->and($invitationEvent->meta['identity_matches_registry'])->toBeTrue()
        ->and($invitationEvent->meta['identity_mismatch_fields'])->toBe([])
        ->and(collect($invitationEvent->meta['versions'])->pluck('result')->all())->toBe(['validated', 'validated']);

    foreach ($created['versions'] as $version) {
        $event = raEvents((string) $version->id, 'validated')->sole();

        expect($event->from_state)->toBe('in_review')
            ->and($event->to_state)->toBe('validated')
            ->and($event->actor_user_id)->toBe($this->actor->id)
            ->and($event->meta['acceptance_id'])->toBe($acceptance->id)
            ->and($event->meta['content_hash'])->toBe($version->content_hash)
            ->and($event->meta['decision'])->toBe('approved');
    }
});

it('aceitar exige as DUAS conferências (CRM/UF e RQE) — nada muda se faltar uma', function (bool $crm, bool $rqe) {
    $created = raSubmitted();

    expect(fn () => raAccept($created, $crm, $rqe))
        ->toThrow(Rejected::class)
        ->and($created['invitation']->fresh()->status)->toBe(Status::Submitted)
        ->and($created['versions'][0]->fresh()->state)->toBe(State::InReview)
        ->and(ClinicalReviewAcceptance::query()->count())->toBe(0);

    try {
        raAccept($created, $crm, $rqe);
    } catch (Rejected $e) {
        expect($e->reason())->toBe(Rejected::CHECKS_REQUIRED);
    }
})->with([[false, true], [true, false], [false, false]]);

it('recusar exige o motivo (10+ caracteres); recusado: o convite vira refused e as versões CONTINUAM em revisão', function () {
    $created = raSubmitted();

    foreach ([null, '', '   ', 'curto'] as $note) {
        try {
            app(ReviewAcceptanceService::class)->record($created['invitation'], Decision::Refused, false, false, $note, $this->actor);
            $this->fail('devia recusar a recusa sem motivo');
        } catch (Rejected $e) {
            expect($e->reason())->toBe(Rejected::NOTE_REQUIRED);
        }
    }

    expect($created['invitation']->fresh()->status)->toBe(Status::Submitted);

    $acceptance = app(ReviewAcceptanceService::class)->record($created['invitation'], Decision::Refused, false, false, 'O CRM informado não consta no portal do CFM.', $this->actor);

    expect($acceptance->decision)->toBe(Decision::Refused)
        ->and($acceptance->note)->toBe('O CRM informado não consta no portal do CFM.')
        ->and($created['invitation']->fresh()->status)->toBe(Status::Refused)
        // A identidade não foi confirmada: nada vale, a versão segue em revisão e pode ir a outro convite.
        ->and($created['versions'][0]->fresh()->state)->toBe(State::InReview)
        ->and($created['versions'][0]->fresh()->validated_acceptance_id)->toBeNull()
        ->and(raEvents((string) $created['invitation']->id, 'refused'))->toHaveCount(1)
        ->and(raEvents((string) $created['versions'][0]->id, 'validated'))->toHaveCount(0);
});

it('o aceite NÃO precisa das duas conferências marcadas quando é recusa (não confirmou nada)', function () {
    $created = raSubmitted();

    expect(app(ReviewAcceptanceService::class)->record($created['invitation'], Decision::Refused, true, false, 'Só conferi o CRM; o RQE não consta.', null)->decision)->toBe(Decision::Refused);
});

it('declarou identidade DIFERENTE do cadastro: aceitar exige nota e o evento mostra QUAIS campos divergem', function () {
    $created = raSubmitted(attestation: ['crm_number' => '777', 'rqe' => '12']);

    expect(fn () => raAccept($created))->toThrow(Rejected::class);

    $acceptance = raAccept($created, note: 'Cadastro estava errado; CRM/RQE conferidos no portal como declarados.', actor: $this->actor);
    $event      = raEvents((string) $created['invitation']->id, 'accepted')->sole();

    expect($acceptance->note)->toContain('Cadastro estava errado')
        ->and($event->meta['identity_matches_registry'])->toBeFalse()
        ->and($event->meta['identity_mismatch_fields'])->toEqualCanonicalizing(['crm_number', 'rqe'])
        ->and($created['versions'][0]->fresh()->state)->toBe(State::Validated);
});

it('identityMismatch: zeros à esquerda, pontuação e caixa do e-mail NÃO são divergência', function () {
    $reviewer = crfReviewer(['crm_number' => '4321', 'rqe' => '987', 'email' => 'confere@exemplo.test']);
    $created  = crfInvitation($reviewer, [crfFrozenVersion('glaucoma')]);

    crfSubmit($created, attestation: ['crm_number' => '04.321', 'rqe' => '0987', 'email' => ' CONFERE@Exemplo.test ']);

    $attestation = $created['invitation']->attestation()->firstOrFail();

    expect(app(ReviewAcceptanceService::class)->identityMismatch((string) $reviewer->id, $attestation))->toBe([]);

    $other = raSubmitted(attestation: ['email' => 'outro@exemplo.test', 'crm_uf' => 'RJ']);

    expect(app(ReviewAcceptanceService::class)->identityMismatch((string) $other['reviewer']->id, $other['invitation']->attestation()->firstOrFail()))->toEqualCanonicalizing(['email', 'crm_uf']);
});

it('só convite RESPONDIDO e ainda não conferido pode ser aceito ou recusado', function () {
    $pending = crfInvitation();

    expect(fn () => raAccept($pending))->toThrow(Rejected::class);

    $created = raSubmitted();
    raAccept($created);

    try {
        raAccept($created + ['invitation' => $created['invitation']->fresh()]);
        $this->fail('o 2º aceite devia ser recusado');
    } catch (Rejected $e) {
        expect($e->reason())->toBe(Rejected::NOT_SUBMITTED);
    }

    expect(ClinicalReviewAcceptance::query()->count())->toBe(1);

    $revoked = crfInvitation(versions: [crfFrozenVersion('dmri')]);
    DB::table('clinical_review_invitations')->where('id', $revoked['invitation']->id)->update(['status' => 'revoked']);

    expect(fn () => raAccept($revoked + ['invitation' => $revoked['invitation']->fresh()]))->toThrow(Rejected::class);
});

it('versão que saiu de revisão depois do parecer é IGNORADA (registrada), sem validar nem recusar em nome dela', function () {
    $created = raSubmitted(['glaucoma', 'dmri']);

    DB::table('recall_protocol_versions')->where('id', $created['versions'][1]->id)->update(['state' => 'withdrawn']);

    raAccept($created);

    $event = raEvents((string) $created['invitation']->id, 'accepted')->sole();

    expect($created['versions'][0]->fresh()->state)->toBe(State::Validated)
        ->and($created['versions'][1]->fresh()->state)->toBe(State::Withdrawn)
        ->and($created['versions'][1]->fresh()->validated_acceptance_id)->toBeNull()
        ->and(collect($event->meta['versions'])->firstWhere('version_id', (string) $created['versions'][1]->id)['result'])->toBe('skipped')
        ->and(raEvents((string) $created['versions'][1]->id, 'validated'))->toHaveCount(0);
});

it('versão cujo hash mudou desde o parecer NÃO é validada (o parecer vale para o conteúdo que o Revisor viu)', function () {
    $created = raSubmitted();

    crfTamperVersionHash($created['versions'][0]);

    raAccept($created);

    expect($created['versions'][0]->fresh()->state)->toBe(State::InReview)
        ->and($created['versions'][0]->fresh()->validated_acceptance_id)->toBeNull()
        ->and($created['invitation']->fresh()->status)->toBe(Status::Accepted);
});

it('tudo ou nada: se o log de governança falha, NADA do aceite vale (aceite, convite e versões desfeitos)', function () {
    $created = raSubmitted(['glaucoma', 'dmri']);

    app()->bind(GovernanceLog::class, fn () => new class() extends GovernanceLog {
        private int $calls = 0;

        public function record(string $subjectType, string $subjectId, string $event, ?string $fromState = null, ?string $toState = null, ?User $actor = null, ?string $reason = null, array $meta = [], ?string $actorLabel = null): ClinicalGovernanceEvent
        {
            // Deixa gravar o evento da 1ª versão e falha no seguinte: o que já foi gravado tem que ser desfeito.
            if (++$this->calls === 2) {
                throw new RuntimeException('log indisponível');
            }

            return parent::record($subjectType, $subjectId, $event, $fromState, $toState, $actor, $reason, $meta, $actorLabel);
        }
    });

    expect(fn () => app(ReviewAcceptanceService::class)->record($created['invitation'], Decision::Accepted, true, true, null, $this->actor))->toThrow(RuntimeException::class);

    expect(ClinicalReviewAcceptance::query()->count())->toBe(0)
        ->and($created['invitation']->fresh()->status)->toBe(Status::Submitted)
        ->and($created['versions'][0]->fresh()->state)->toBe(State::InReview)
        ->and($created['versions'][1]->fresh()->state)->toBe(State::InReview)
        ->and(ClinicalGovernanceEvent::query()->whereIn('event', ['validated', 'accepted'])->count())->toBe(0);
});

it('o aceite é append-only: não se edita nem se apaga (model e banco)', function () {
    $created    = raSubmitted();
    $acceptance = raAccept($created);

    expect(fn () => $acceptance->forceFill(['note' => 'reescrito'])->save())->toThrow(LogicException::class)
        ->and(fn () => $acceptance->delete())->toThrow(LogicException::class)
        ->and(rivRejected(fn () => DB::table('clinical_review_acceptances')->where('id', $acceptance->id)->update(['decision' => 'refused'])))->toBeTrue()
        ->and(rivRejected(fn () => DB::table('clinical_review_acceptances')->where('id', $acceptance->id)->delete()))->toBeTrue();
});

it('sem ator (sistema) o aceite fica com o rótulo system', function () {
    $created = raSubmitted();

    $acceptance = raAccept($created);

    expect($acceptance->accepted_by)->toBeNull()
        ->and($acceptance->accepted_by_label)->toBe('system');
});

it('nota é gravada como texto (normalizada, sem espaços nas pontas)', function () {
    $created = raSubmitted();

    $acceptance = raAccept($created, note: "  Conferido no portal em 14/10/2026.  \n");

    expect($acceptance->note)->toBe('Conferido no portal em 14/10/2026.');
});
