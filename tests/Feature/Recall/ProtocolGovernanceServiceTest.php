<?php

declare(strict_types=1);

/**
 * A vida de uma versão depois da revisão (ADR 0002): liberar, suspender, retomar, aposentar,
 * retirar da revisão e a verificação de integridade. O freio de mão da governança clínica.
 */

use App\Domains\ClinicalReview\Enums\{AcceptanceDecision, ReviewInvitationStatus as InvStatus};
use App\Domains\ClinicalReview\Models\ClinicalGovernanceEvent;
use App\Domains\ClinicalReview\Services\{GovernanceLog, ReviewAcceptanceService};
use App\Domains\Recall\Enums\ProtocolVersionState as State;
use App\Domains\Recall\Exceptions\{ProtocolGovernanceException as Gov, ProtocolVersionException as Vex};
use App\Domains\Recall\Models\{RecallProtocol, RecallProtocolVersion};
use App\Domains\Recall\Services\{ProtocolCatalogService, ProtocolGovernanceService};
use App\Domains\Recall\Support\ProposedProtocolDrafts;
use App\Models\User;
use Illuminate\Support\Facades\{DB, Notification};

beforeEach(function () {
    Notification::fake();
    $this->actor = User::factory()->create(['name' => 'Gestor Clínico']);
    $this->gov   = app(ProtocolGovernanceService::class);
});

function pgEvents(RecallProtocolVersion $version, string $event)
{
    return ClinicalGovernanceEvent::query()->where('subject_id', $version->id)->where('event', $event)->orderBy('created_at')->get();
}

/** Uma 2ª versão do glaucoma, VALIDADA de verdade (derivada da 1ª, que precisa ter saído da revisão). */
function pgSecondValidated(RecallProtocolVersion $first, ?User $actor = null): RecallProtocolVersion
{
    $catalog = app(ProtocolCatalogService::class);
    $second  = $catalog->startRevision($first->protocol, $actor);

    $content                                    = $second->contentArray();
    $content['change_note']                     = 'Campimetria a cada 9 meses.';
    $content['tasks'][1]['interval']['default'] = 9;
    $catalog->saveDraft($second, $content, 0, $actor);
    $second = $catalog->freeze($second->fresh(), $actor);

    $created = crfInvitation(null, [$second], null, 14, $actor);
    crfSubmit($created);
    app(ReviewAcceptanceService::class)->record($created['invitation']->fresh(), AcceptanceDecision::Accepted, true, true, null, $actor);

    return $second->fresh();
}

// ── liberar ─────────────────────────────────────────────────────────────────

it('libera a versão validada: released, quem e quando, prazo de revalidação, evento com a prova', function () {
    $validated = crfValidatedVersion('glaucoma', $this->actor);

    $released = $this->gov->release($validated['version'], $this->actor);

    expect($released->state)->toBe(State::Released)
        ->and($released->released_at)->not->toBeNull()
        ->and($released->released_by)->toBe($this->actor->id)
        ->and($released->review_due_at->toDateString())->toBe(now()->addMonths(24)->toDateString())
        ->and($released->lock_version)->toBe($validated['version']->lock_version + 1)
        ->and($released->protocol->releasedVersion()?->id)->toBe($released->id);

    $event = pgEvents($released, 'released')->sole();

    expect($event->from_state)->toBe('validated')
        ->and($event->to_state)->toBe('released')
        ->and($event->actor_user_id)->toBe($this->actor->id)
        ->and($event->meta['content_hash'])->toBe($released->content_hash)
        ->and($event->meta['acceptance_id'])->toBe($validated['acceptance']->id)
        ->and($event->meta['review_due_at'])->toBe($released->review_due_at->toDateString())
        ->and($event->meta['superseded'])->toBeNull();
});

it('o prazo de revalidação vem da configuração', function () {
    config(['recall.revalidation_months' => 12]);

    $released = $this->gov->release(crfValidatedVersion()['version'], $this->actor);

    expect($released->review_due_at->toDateString())->toBe(now()->addMonths(12)->toDateString());
});

it('liberar uma versão nova passa a anterior a superseded (válida para quem a adotou) — nunca duas released', function () {
    $first = crfValidatedVersion('glaucoma', $this->actor)['version'];
    $this->gov->release($first, $this->actor);
    DB::table('recall_protocol_versions')->where('id', $first->id)->update(['state' => 'released']);

    // A 2ª versão só nasce quando a 1ª sai de "em andamento" — a 1ª já está liberada.
    $second = pgSecondValidated($first->fresh(), $this->actor);

    $this->gov->release($second, $this->actor);

    expect($first->fresh()->state)->toBe(State::Superseded)
        ->and($second->fresh()->state)->toBe(State::Released)
        ->and(RecallProtocolVersion::query()->where('protocol_id', $first->protocol_id)->where('state', 'released')->count())->toBe(1)
        ->and(pgEvents($first->fresh(), 'superseded')->sole()->meta['superseded_by'])->toBe((string) $second->id)
        ->and(pgEvents($second->fresh(), 'released')->sole()->meta['superseded'])->toBe((string) $first->id);
});

it('só a versão VALIDADA pode ser liberada', function (State $state) {
    $version = crfFrozenVersion('glaucoma');
    DB::table('recall_protocol_versions')->where('id', $version->id)->update(['state' => $state->value]);

    expect(fn () => $this->gov->release($version->fresh(), $this->actor))->toThrow(Vex::class);

    $fresh = $version->fresh();

    expect($fresh->state)->toBe($state)->and($fresh->released_at)->toBeNull();
})->with([State::InReview, State::Declined, State::Withdrawn, State::Retired, State::Released, State::Suspended]);

it('rascunho nem chega a ser liberado', function () {
    $draft = RecallProtocol::query()->where('key', 'glaucoma')->first()?->versions()->first()
        ?? (function () {
            $proposal = ProposedProtocolDrafts::all()['glaucoma'];

            return app(ProtocolCatalogService::class)->createProtocol('glaucoma', $proposal['translations'], $proposal['content'])->versions()->firstOrFail();
        })();

    expect(fn () => $this->gov->release($draft, $this->actor))->toThrow(Vex::class);
});

it('SEM a prova completa não libera, mesmo que o estado diga validated (I1)', function (string $break, string $detail) {
    $validated = crfValidatedVersion('glaucoma', $this->actor);
    $version   = $validated['version'];

    match ($break) {
        'no_acceptance'     => DB::table('recall_protocol_versions')->where('id', $version->id)->update(['validated_acceptance_id' => null]),
        'item_not_approved' => (function () use ($validated) {
            DB::unprepared('ALTER TABLE clinical_review_items DISABLE TRIGGER clinical_review_items_decision_once');
            DB::table('clinical_review_items')->where('invitation_id', $validated['invitation']->id)->update(['decision' => 'changes_requested']);
            DB::unprepared('ALTER TABLE clinical_review_items ENABLE TRIGGER clinical_review_items_decision_once');
        })(),
        'other_hash' => (function () use ($validated) {
            DB::unprepared('ALTER TABLE clinical_review_items DISABLE TRIGGER clinical_review_items_decision_once');
            DB::table('clinical_review_items')->where('invitation_id', $validated['invitation']->id)->update(['content_hash' => '1:' . str_repeat('a', 64)]);
            DB::unprepared('ALTER TABLE clinical_review_items ENABLE TRIGGER clinical_review_items_decision_once');
        })(),
        'tampered_hash' => crfTamperVersionHash($version),
        'integrity'     => crfTamperVersionContent($version),
    };

    try {
        $this->gov->release($version->fresh(), $this->actor);
        $this->fail('devia recusar a liberação sem prova');
    } catch (Gov $e) {
        expect($e->reason())->toBe($break === 'integrity' ? Gov::INTEGRITY_FAILED : Gov::NO_VALIDATION_EVIDENCE)
            ->and($e->getMessage())->toContain($detail);
    }

    expect($version->fresh()->state)->toBe(State::Validated)
        ->and(pgEvents($version, 'released'))->toHaveCount(0);
})->with([
    'sem aceite'            => ['no_acceptance', 'aceite'],
    'Revisor não aprovou'   => ['item_not_approved', 'não aprovou'],
    'hash aprovado é outro' => ['other_hash', 'conteúdo aprovado'],
    'hash adulterado'       => ['tampered_hash', 'conteúdo aprovado'],
    'conteúdo adulterado'   => ['integrity', 'hash gravado'],
]);

it('aceite RECUSADO nunca libera: a versão não chega a validated', function () {
    $version = crfFrozenVersion('glaucoma');
    $created = crfInvitation(null, [$version]);
    crfSubmit($created);

    app(ReviewAcceptanceService::class)->record($created['invitation']->fresh(), AcceptanceDecision::Refused, false, false, 'O CRM informado não consta no portal do CFM.', $this->actor);

    expect(fn () => $this->gov->release($version->fresh(), $this->actor))->toThrow(Vex::class);
});

it('liberar é tudo ou nada: se o log falha, a anterior continua released e a nova continua validated', function () {
    $first = crfValidatedVersion('glaucoma', $this->actor)['version'];
    $this->gov->release($first, $this->actor);
    $second = pgSecondValidated($first->fresh(), $this->actor);

    app()->bind(GovernanceLog::class, fn () => new class() extends GovernanceLog {
        private int $calls = 0;

        public function record(string $subjectType, string $subjectId, string $event, ?string $fromState = null, ?string $toState = null, ?User $actor = null, ?string $reason = null, array $meta = [], ?string $actorLabel = null): ClinicalGovernanceEvent
        {
            if (++$this->calls === 2) {
                throw new RuntimeException('log indisponível');
            }

            return parent::record($subjectType, $subjectId, $event, $fromState, $toState, $actor, $reason, $meta, $actorLabel);
        }
    });

    expect(fn () => app(ProtocolGovernanceService::class)->release($second, $this->actor))->toThrow(RuntimeException::class);

    expect($first->fresh()->state)->toBe(State::Released)
        ->and($second->fresh()->state)->toBe(State::Validated)
        ->and(pgEvents($first->fresh(), 'superseded'))->toHaveCount(0);
});

// ── suspender / retomar ─────────────────────────────────────────────────────

it('suspender: released → suspended com o motivo, quem, quando e de onde veio', function () {
    $released = $this->gov->release(crfValidatedVersion('glaucoma', $this->actor)['version'], $this->actor);

    $suspended = $this->gov->suspend($released, 'Revisor apontou erro no intervalo da campimetria.', $this->actor);

    expect($suspended->state)->toBe(State::Suspended)
        ->and($suspended->suspended_reason)->toBe('Revisor apontou erro no intervalo da campimetria.')
        ->and($suspended->suspended_by)->toBe($this->actor->id)
        ->and($suspended->suspended_at)->not->toBeNull()
        ->and($suspended->suspended_from_state)->toBe('released')
        // Sem versão liberada: nenhuma clínica recebe regra nova, e quem usa é parado pelo avaliador (isInUse).
        ->and($suspended->protocol->releasedVersion())->toBeNull()
        ->and($suspended->state->isInUse())->toBeFalse();

    $event = pgEvents($suspended, 'suspended')->sole();

    expect($event->reason)->toBe('Revisor apontou erro no intervalo da campimetria.')
        ->and($event->from_state)->toBe('released')
        ->and($event->to_state)->toBe('suspended');
});

it('uma versão superseded também pode ser suspensa (correção clínica na antiga)', function () {
    $first = crfValidatedVersion('glaucoma', $this->actor)['version'];
    $this->gov->release($first, $this->actor);
    $second = pgSecondValidated($first->fresh(), $this->actor);
    $this->gov->release($second, $this->actor);

    $suspended = $this->gov->suspend($first->fresh(), 'A versão antiga tinha um intervalo errado e precisa parar.', $this->actor);

    expect($suspended->suspended_from_state)->toBe('superseded')
        ->and($second->fresh()->state)->toBe(State::Released);
});

it('suspender e aposentar exigem motivo de verdade (20+ caracteres, sem só espaços)', function () {
    $released = $this->gov->release(crfValidatedVersion('glaucoma', $this->actor)['version'], $this->actor);

    foreach (['', '   ', 'curto demais', str_repeat(' ', 30)] as $reason) {
        try {
            $this->gov->suspend($released, $reason, $this->actor);
            $this->fail('devia recusar o motivo curto');
        } catch (Gov $e) {
            expect($e->reason())->toBe(Gov::REASON_TOO_SHORT);
        }
    }

    expect($released->fresh()->state)->toBe(State::Released);
});

it('só released ou superseded são suspensas', function (State $state) {
    $version = crfFrozenVersion('glaucoma');
    DB::table('recall_protocol_versions')->where('id', $version->id)->update(['state' => $state->value]);

    expect(fn () => $this->gov->suspend($version->fresh(), 'Motivo suficientemente longo para valer.', $this->actor))->toThrow(Vex::class);
})->with([State::InReview, State::Validated, State::Declined, State::Withdrawn, State::Retired, State::Suspended]);

it('retomar volta ao estado de antes e limpa os marcos da suspensão (o histórico fica nos eventos)', function () {
    $released = $this->gov->release(crfValidatedVersion('glaucoma', $this->actor)['version'], $this->actor);
    $this->gov->suspend($released, 'Pausa para conferir o intervalo com o Revisor.', $this->actor);

    $resumed = $this->gov->resume($released->fresh(), $this->actor);

    expect($resumed->state)->toBe(State::Released)
        ->and($resumed->suspended_at)->toBeNull()
        ->and($resumed->suspended_by)->toBeNull()
        ->and($resumed->suspended_reason)->toBeNull()
        ->and($resumed->suspended_from_state)->toBeNull();

    $event = pgEvents($resumed, 'resumed')->sole();

    expect($event->meta['restored_to'])->toBe('released')
        ->and($event->meta['demoted_by_newer_release'])->toBeFalse()
        ->and(pgEvents($resumed, 'suspended')->sole()->reason)->toBe('Pausa para conferir o intervalo com o Revisor.');
});

it('retomar uma que era released, depois de uma NOVA ter sido liberada: volta como superseded (nunca dois released)', function () {
    $first = $this->gov->release(crfValidatedVersion('glaucoma', $this->actor)['version'], $this->actor);
    $this->gov->suspend($first, 'Pausa enquanto a versão 2 é validada pelo Revisor.', $this->actor);

    $second = pgSecondValidated($first->fresh(), $this->actor);
    $this->gov->release($second, $this->actor);

    $resumed = $this->gov->resume($first->fresh(), $this->actor);

    expect($resumed->state)->toBe(State::Superseded)
        ->and($second->fresh()->state)->toBe(State::Released)
        ->and(pgEvents($resumed, 'resumed')->sole()->meta['demoted_by_newer_release'])->toBeTrue()
        ->and(RecallProtocolVersion::query()->where('protocol_id', $first->protocol_id)->where('state', 'released')->count())->toBe(1);
});

it('não retoma versão com o hash divergente — a suspensão por integridade só sai depois de investigada', function () {
    $released = $this->gov->release(crfValidatedVersion('glaucoma', $this->actor)['version'], $this->actor);
    $this->gov->suspend($released, 'Suspeita de adulteração do conteúdo gravado.', $this->actor);
    crfTamperVersionHash($released);

    try {
        $this->gov->resume($released->fresh(), $this->actor);
        $this->fail('devia recusar');
    } catch (Gov $e) {
        expect($e->reason())->toBe(Gov::INTEGRITY_FAILED);
    }

    expect($released->fresh()->state)->toBe(State::Suspended);
});

it('só suspensa se retoma', function () {
    $released = $this->gov->release(crfValidatedVersion('glaucoma', $this->actor)['version'], $this->actor);

    expect(fn () => $this->gov->resume($released->fresh(), $this->actor))->toThrow(Vex::class);
});

// ── aposentar / retirar ─────────────────────────────────────────────────────

it('aposentar é terminal e guarda quem, quando e por quê', function (State $state) {
    $version = crfFrozenVersion('glaucoma');
    DB::table('recall_protocol_versions')->where('id', $version->id)->update(['state' => $state->value]);

    $retired = $this->gov->retire($version->fresh(), 'Substituída por outra regra e fora de uso.', $this->actor);

    expect($retired->state)->toBe(State::Retired)
        ->and($retired->retired_reason)->toBe('Substituída por outra regra e fora de uso.')
        ->and($retired->retired_by)->toBe($this->actor->id)
        ->and($retired->retired_at)->not->toBeNull()
        ->and(pgEvents($retired, 'retired')->sole()->from_state)->toBe($state->value);

    expect(fn () => $this->gov->retire($retired->fresh(), 'Tentativa de aposentar de novo a mesma.', $this->actor))->toThrow(Vex::class)
        ->and(fn () => $this->gov->resume($retired->fresh(), $this->actor))->toThrow(Vex::class);
})->with([State::InReview, State::Validated, State::Released, State::Superseded, State::Suspended, State::Declined, State::Withdrawn]);

it('rascunho não se aposenta', function () {
    $proposal = ProposedProtocolDrafts::all()['glaucoma'];
    $draft    = app(ProtocolCatalogService::class)->createProtocol('glaucoma', $proposal['translations'], $proposal['content'])->versions()->firstOrFail();

    expect(fn () => $this->gov->retire($draft, 'Motivo suficientemente longo para valer.', $this->actor))->toThrow(Vex::class);
});

it('aposentar uma versão EM REVISÃO revoga os convites pendentes dela', function () {
    $created = crfInvitation();

    $this->gov->retire($created['versions'][0], 'Versão abandonada antes de o Revisor responder.', $this->actor);

    $invitation = $created['invitation']->fresh();

    expect($invitation->status)->toBe(InvStatus::Revoked)
        ->and($invitation->revoked_reason)->toContain('Versão aposentada')
        ->and(pgEvents($created['versions'][0]->fresh(), 'retired')->sole()->meta['invitations_revoked'])->toBe(1);
});

it('retirar da revisão: in_review/validated → withdrawn, e os convites PENDENTES que citam a versão são revogados', function () {
    $versions = [crfFrozenVersion('glaucoma'), crfFrozenVersion('dmri')];
    $both     = crfInvitation(null, $versions);
    $single   = crfInvitation(null, [$versions[1]]);
    $other    = crfInvitation(null, [crfFrozenVersion('ceratocone')]);

    $withdrawn = $this->gov->withdraw($versions[1], 'Achamos um erro na tabela de intervalos.', $this->actor);

    expect($withdrawn->state)->toBe(State::Withdrawn)
        ->and($both['invitation']->fresh()->status)->toBe(InvStatus::Revoked)
        ->and($single['invitation']->fresh()->status)->toBe(InvStatus::Revoked)
        // Convite que não cita a versão fica como está.
        ->and($other['invitation']->fresh()->status)->toBe(InvStatus::Pending)
        ->and($versions[0]->fresh()->state)->toBe(State::InReview)
        ->and(pgEvents($withdrawn, 'withdrawn')->sole()->meta['invitations_revoked'])->toBe(2);
});

it('o link de um convite revogado por retirada deixa de abrir', function () {
    $created = crfInvitation();

    $this->gov->withdraw($created['versions'][0], 'Versão retirada para correção do conteúdo.', $this->actor);

    $this->get(crfOpenUrl($created))->assertRedirect(route('clinical-review.unavailable'));
});

it('convite JÁ respondido não é tocado pela retirada (o parecer fica no histórico)', function () {
    $created = crfInvitation();
    crfSubmit($created);

    $this->gov->withdraw($created['versions'][0], 'Versão retirada depois do parecer do Revisor.', $this->actor);

    expect($created['invitation']->fresh()->status)->toBe(InvStatus::Submitted);
});

it('retirar uma validada (antes de liberar) também vale; liberada não se retira', function () {
    $validated = crfValidatedVersion('glaucoma', $this->actor);

    expect($this->gov->withdraw($validated['version'], 'Vamos corrigir antes de liberar às clínicas.', $this->actor)->state)->toBe(State::Withdrawn);

    $released = $this->gov->release(crfValidatedVersion('dmri', $this->actor)['version'], $this->actor);

    expect(fn () => $this->gov->withdraw($released, 'Tentativa de retirar a que já está no ar.', $this->actor))->toThrow(Vex::class);
});

it('retirar pede um motivo (10+)', function () {
    $version = crfFrozenVersion('glaucoma');

    try {
        $this->gov->withdraw($version, 'curto', $this->actor);
        $this->fail('devia recusar');
    } catch (Gov $e) {
        expect($e->reason())->toBe(Gov::REASON_TOO_SHORT);
    }

    expect($version->fresh()->state)->toBe(State::InReview);
});

// ── verificação de integridade ──────────────────────────────────────────────

it('tudo íntegro: confere o que está em revisão/validado/liberado/suspenso e não muda nada', function () {
    crfFrozenVersion('dmri');
    $released = $this->gov->release(crfValidatedVersion('glaucoma', $this->actor)['version'], $this->actor);
    $this->gov->suspend($this->gov->release(crfValidatedVersion('ceratocone', $this->actor)['version'], $this->actor), 'Pausa de teste para conferir a integridade.', $this->actor);

    $report = $this->gov->verifyIntegrity();

    expect($report['checked'])->toBe(3)
        ->and($report['failed'])->toBe([])
        ->and($released->fresh()->state)->toBe(State::Released)
        ->and(ClinicalGovernanceEvent::query()->where('event', 'integrity_failed')->count())->toBe(0);
});

it('rascunho, recusada, retirada e aposentada não entram na verificação', function () {
    $proposal = ProposedProtocolDrafts::all()['glaucoma'];
    app(ProtocolCatalogService::class)->createProtocol('glaucoma', $proposal['translations'], $proposal['content']);

    expect($this->gov->verifyIntegrity()['checked'])->toBe(0);
});

it('hash divergente em versão LIBERADA: suspende na hora, com o motivo e o evento', function () {
    $released = $this->gov->release(crfValidatedVersion('glaucoma', $this->actor)['version'], $this->actor);
    crfTamperVersionHash($released);

    $report = $this->gov->verifyIntegrity();

    $fresh = $released->fresh();

    expect($report['failed'])->toHaveCount(1)
        ->and($report['failed'][0])->toMatchArray(['protocol' => 'glaucoma', 'version' => 1, 'state' => 'suspended', 'action' => 'suspended'])
        ->and($fresh->state)->toBe(State::Suspended)
        ->and($fresh->suspended_from_state)->toBe('released')
        ->and($fresh->suspended_by)->toBeNull()
        ->and($fresh->suspended_reason)->toContain('Verificação de integridade')
        ->and($fresh->protocol->releasedVersion())->toBeNull();

    $event = pgEvents($fresh, 'integrity_failed')->sole();

    expect($event->actor_label)->toBe('system')
        ->and($event->from_state)->toBe('released')
        ->and($event->to_state)->toBe('suspended')
        ->and($event->meta['stored_hash'])->toBe('1:' . str_repeat('0', 64))
        ->and($event->meta['actual_hash'])->toStartWith('1:')->not->toBe($event->meta['stored_hash'])
        ->and($event->meta['action'])->toBe('suspended');
});

it('hash divergente em versão EM REVISÃO ou VALIDADA: é retirada e os convites pendentes, revogados', function () {
    $inReview  = crfInvitation();
    $validated = crfValidatedVersion('dmri', $this->actor)['version'];
    crfTamperVersionHash($inReview['versions'][0]);
    crfTamperVersionHash($validated);

    $report = $this->gov->verifyIntegrity();

    expect($report['failed'])->toHaveCount(2)
        ->and(collect($report['failed'])->pluck('action')->unique()->all())->toBe(['withdrawn'])
        ->and($inReview['versions'][0]->fresh()->state)->toBe(State::Withdrawn)
        ->and($validated->fresh()->state)->toBe(State::Withdrawn)
        ->and($inReview['invitation']->fresh()->status)->toBe(InvStatus::Revoked);
});

it('versão já suspensa e adulterada: não muda e o evento sai UMA vez, mesmo rodando toda noite', function () {
    $released = $this->gov->release(crfValidatedVersion('glaucoma', $this->actor)['version'], $this->actor);
    $this->gov->suspend($released, 'Pausa de teste para conferir a integridade.', $this->actor);
    crfTamperVersionHash($released);

    $first  = $this->gov->verifyIntegrity();
    $second = $this->gov->verifyIntegrity();

    expect($first['failed'])->toHaveCount(1)
        ->and($first['failed'][0]['action'])->toBe('already_suspended')
        ->and($second['failed'])->toBe([])
        ->and($released->fresh()->state)->toBe(State::Suspended)
        ->and(pgEvents($released->fresh(), 'integrity_failed'))->toHaveCount(1);
});

it('é idempotente: a 2ª execução não acha nada de novo', function () {
    $released = $this->gov->release(crfValidatedVersion('glaucoma', $this->actor)['version'], $this->actor);
    crfTamperVersionHash($released);

    $this->gov->verifyIntegrity();

    $again = $this->gov->verifyIntegrity();

    expect($again['failed'])->toBe([])
        ->and(pgEvents($released->fresh(), 'integrity_failed'))->toHaveCount(1);
});

// ── o trigger do banco como 1ª barreira ─────────────────────────────────────

it('o banco recusa reescrever o conteúdo/hash de versão congelada e voltar a rascunho', function () {
    $validated = crfValidatedVersion('glaucoma', $this->actor)['version'];

    expect(rivRejected(fn () => DB::table('recall_protocol_versions')->where('id', $validated->id)->update(['content_hash' => '1:' . str_repeat('1', 64)])))->toBeTrue()
        ->and(rivRejected(fn () => DB::table('recall_protocol_versions')->where('id', $validated->id)->update(['state' => 'draft'])))->toBeTrue()
        ->and(rivRejected(fn () => DB::table('recall_protocol_versions')->where('id', $validated->id)->delete()))->toBeTrue()
        // Mudar o ESTADO (o que a governança faz) segue permitido.
        ->and(rivRejected(fn () => DB::table('recall_protocol_versions')->where('id', $validated->id)->update(['state' => 'retired'])))->toBeFalse();
});
