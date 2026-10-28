<?php

declare(strict_types=1);

/**
 * Rascunho do parecer: guardado no servidor, CIFRADO, só enquanto o convite está aberto, e
 * sempre peneirado. (O peneirado em si está em tests/Unit/ClinicalReview/ReviewDraftSanitizeTest.).
 */

use App\Domains\ClinicalReview\Exceptions\ReviewSubmissionException;
use App\Domains\ClinicalReview\Services\{ReviewDraftService, ReviewInvitationService};
use Illuminate\Support\Facades\{DB, Notification};

function rdsDraft(array $created, string $comment = 'Rascunho do comentário sobre o intervalo.'): array
{
    $itemId = (string) $created['invitation']->items()->sole()->id;

    return [
        'decisions'   => [$itemId => ['decision' => 'changes_requested', 'comment' => $comment, 'proposed_changes' => 'Campimetria a cada 9 meses.']],
        'attestation' => ['name' => 'Dra. Ana', 'crm_number' => '12345', 'crm_uf' => 'sp', 'has_conflict' => false, 'agreed' => true],
    ];
}

it('grava o rascunho peneirado e a hora em que foi salvo', function () {
    $created = crfInvitation();
    $draft   = rdsDraft($created);
    $itemId  = array_key_first($draft['decisions']);

    app(ReviewDraftService::class)->save($created['invitation'], $draft + ['campo' => 'lixo']);

    $saved = $created['invitation']->fresh();

    expect($saved->draft_payload['decisions'][$itemId]['decision'])->toBe('changes_requested')
        ->and($saved->draft_payload['decisions'][$itemId]['comment'])->toBe('Rascunho do comentário sobre o intervalo.')
        ->and($saved->draft_payload['attestation']['name'])->toBe('Dra. Ana')
        ->and($saved->draft_payload['attestation']['crm_uf'])->toBe('SP')
        ->and($saved->draft_payload)->not->toHaveKey('campo')
        ->and($saved->draft_saved_at)->not->toBeNull();
});

it('o rascunho é CIFRADO no banco: o que o Revisor digitou não aparece em claro', function () {
    $created = crfInvitation();

    app(ReviewDraftService::class)->save($created['invitation'], rdsDraft($created, 'SEGREDO clínico do parecer em rascunho.'));

    $raw = (string) DB::table('clinical_review_invitations')->where('id', $created['invitation']->id)->value('draft_payload');

    expect($raw)->not->toBe('')
        ->and($raw)->not->toContain('SEGREDO')
        ->and($raw)->not->toContain('changes_requested')
        ->and($raw)->not->toContain('Dra. Ana')
        // É o envelope do Crypt do Laravel (base64 de um JSON com iv/value/mac).
        ->and(json_decode((string) base64_decode($raw, true), true))->toHaveKeys(['iv', 'value', 'mac']);
});

it('salvar de novo substitui o rascunho anterior', function () {
    $created = crfInvitation();
    $service = app(ReviewDraftService::class);

    $service->save($created['invitation'], rdsDraft($created, 'Primeira versão do comentário.'));
    $service->save($created['invitation'], rdsDraft($created, 'Segunda versão do comentário.'));

    $payload = $created['invitation']->fresh()->draft_payload;

    expect(array_values($payload['decisions'])[0]['comment'])->toBe('Segunda versão do comentário.');
});

it('só convite aberto aceita rascunho: respondido, revogado ou expirado recusam e não gravam', function () {
    Notification::fake();
    $answered = crfInvitation(versions: [crfFrozenVersion('glaucoma')]);
    crfSubmit($answered);

    $revoked = crfInvitation(versions: [crfFrozenVersion('dmri')]);
    app(ReviewInvitationService::class)->revoke($revoked['invitation'], 'Motivo qualquer do teste.');

    $expired = crfInvitation(versions: [crfFrozenVersion('ceratocone')]);
    $expired['invitation']->forceFill(['expires_at' => now()->subSecond()])->save();

    foreach ([$answered, $revoked, $expired] as $created) {
        try {
            app(ReviewDraftService::class)->save($created['invitation'], rdsDraft($created));
            $this->fail('Era para recusar.');
        } catch (ReviewSubmissionException $e) {
            expect($e->reason())->toBe(ReviewSubmissionException::NOT_OPEN);
        }

        expect($created['invitation']->fresh()->draft_payload)->toBeNull();
    }
});

it('item de outro convite no rascunho é descartado', function () {
    $mine  = crfInvitation();
    $other = crfInvitation(versions: [crfFrozenVersion('dmri')]);

    app(ReviewDraftService::class)->save($mine['invitation'], [
        'decisions' => [(string) $other['invitation']->items()->sole()->id => ['decision' => 'approved', 'comment' => 'alheio']],
    ]);

    expect($mine['invitation']->fresh()->draft_payload['decisions'])->toBe([]);
});

it('o envio do parecer apaga o rascunho', function () {
    Notification::fake();
    $created = crfInvitation();
    app(ReviewDraftService::class)->save($created['invitation'], rdsDraft($created));

    expect($created['invitation']->fresh()->draft_payload)->not->toBeNull();

    crfSubmit($created);

    expect($created['invitation']->fresh()->draft_payload)->toBeNull()
        ->and(DB::table('clinical_review_invitations')->where('id', $created['invitation']->id)->value('draft_payload'))->toBeNull();
});
