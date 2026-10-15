<?php

declare(strict_types=1);

use App\Domains\ClinicalReview\Enums\{AcceptanceDecision, ReviewDecision, ReviewInvitationStatus as Status};
use Tests\TestCase;

uses(TestCase::class);

it('o convite só anda pendente → respondido → aceito/recusado; pendente também expira ou é revogado', function () {
    expect(Status::Pending->canTransitionTo(Status::Submitted))->toBeTrue()
        ->and(Status::Pending->canTransitionTo(Status::Expired))->toBeTrue()
        ->and(Status::Pending->canTransitionTo(Status::Revoked))->toBeTrue()
        ->and(Status::Submitted->canTransitionTo(Status::Accepted))->toBeTrue()
        ->and(Status::Submitted->canTransitionTo(Status::Refused))->toBeTrue();
});

it('não se pula nem se volta: pendente não é aceito direto; respondido não expira nem se revoga', function () {
    expect(Status::Pending->canTransitionTo(Status::Accepted))->toBeFalse()
        ->and(Status::Pending->canTransitionTo(Status::Refused))->toBeFalse()
        ->and(Status::Submitted->canTransitionTo(Status::Pending))->toBeFalse()
        ->and(Status::Submitted->canTransitionTo(Status::Revoked))->toBeFalse()
        ->and(Status::Submitted->canTransitionTo(Status::Expired))->toBeFalse();
});

it('aceito, recusado, expirado e revogado são finais', function () {
    foreach ([Status::Accepted, Status::Refused, Status::Expired, Status::Revoked] as $final) {
        expect($final->isFinal())->toBeTrue($final->value);

        foreach (Status::cases() as $to) {
            expect($final->canTransitionTo($to))->toBeFalse("{$final->value} → {$to->value}");
        }
    }

    expect(Status::Pending->isFinal())->toBeFalse()->and(Status::Submitted->isFinal())->toBeFalse();
});

it('só o convite pendente está aberto para o Revisor', function () {
    foreach (Status::cases() as $status) {
        expect($status->isOpenForReviewer())->toBe($status === Status::Pending, $status->value);
    }
});

it('só "aprovo" dispensa justificativa', function () {
    expect(ReviewDecision::Approved->requiresComment())->toBeFalse()
        ->and(ReviewDecision::ChangesRequested->requiresComment())->toBeTrue()
        ->and(ReviewDecision::Rejected->requiresComment())->toBeTrue();
});

it('todo estado, decisão e aceite tem rótulo traduzido nos dois idiomas', function () {
    foreach (['pt_BR', 'en'] as $locale) {
        app()->setLocale($locale);

        foreach ([...Status::cases(), ...ReviewDecision::cases(), ...AcceptanceDecision::cases()] as $case) {
            expect($case->label())->not->toStartWith('clinical_review.')->and($case->label())->not->toBeEmpty();
        }
    }

    expect(Status::values())->toBe(['pending', 'submitted', 'accepted', 'refused', 'expired', 'revoked'])
        ->and(ReviewDecision::values())->toBe(['approved', 'changes_requested', 'rejected']);
});
