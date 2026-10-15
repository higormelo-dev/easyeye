<?php

declare(strict_types=1);

/** Cadastro dos Revisores Clínicos: normalização, log de governança e o efeito de desativar. */

use App\Domains\ClinicalReview\Enums\ReviewInvitationStatus as Status;
use App\Domains\ClinicalReview\Models\{ClinicalGovernanceEvent, ClinicalReviewer};
use App\Domains\ClinicalReview\Services\ClinicalReviewerService;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function crsService(): ClinicalReviewerService
{
    return app(ClinicalReviewerService::class);
}

function crsData(array $overrides = []): array
{
    return array_merge([
        'name'       => '  Dra. Ana Paula Souza ',
        'email'      => 'ANA.Souza@Exemplo.test',
        'crm_number' => '012.345',
        'crm_uf'     => 'sp',
        'rqe'        => '2.001',
        'phone'      => '(11) 98765-4321',
        'notes'      => 'Indicada pelo CBO.',
    ], $overrides);
}

function crsEvents(string $event, string $reviewerId)
{
    return ClinicalGovernanceEvent::query()->where('subject_type', 'clinical_reviewer')->where('subject_id', $reviewerId)->where('event', $event)->get();
}

it('cadastra o Revisor com os dados normalizados, ativo, e registra quem cadastrou', function () {
    $actor = User::factory()->create();

    $reviewer = crsService()->create(crsData(), $actor);

    expect($reviewer->name)->toBe('Dra. Ana Paula Souza')
        ->and($reviewer->email)->toBe('ana.souza@exemplo.test')
        ->and($reviewer->crm_number)->toBe('12345')
        ->and($reviewer->crm_uf)->toBe('SP')
        ->and($reviewer->rqe)->toBe('2001')
        ->and($reviewer->phone)->toBe('11987654321')
        ->and($reviewer->specialty)->toBe('Oftalmologia')
        ->and($reviewer->active)->toBeTrue()
        ->and($reviewer->crmLabel())->toBe('CRM 12345/SP')
        ->and($reviewer->created_by)->toBe($actor->id);

    $event = crsEvents('created', (string) $reviewer->id)->sole();

    expect($event->actor_user_id)->toBe($actor->id)->and($event->to_state)->toBe('active')->and($event->meta['reviewer'])->toBe('CRM 12345/SP');
});

it('e-mail e CRM/UF são únicos no banco (o e-mail sem diferenciar caixa)', function () {
    crsService()->create(crsData());

    expect(rivRejected(fn () => crsService()->create(crsData(['crm_number' => '99999', 'email' => 'ana.souza@EXEMPLO.test']))))->toBeTrue('e-mail')
        ->and(rivRejected(fn () => crsService()->create(crsData(['email' => 'outra@exemplo.test']))))->toBeTrue('CRM/UF')
        ->and(rivRejected(fn () => crsService()->create(crsData(['email' => 'outra@exemplo.test', 'crm_uf' => 'RJ']))))->toBeFalse('mesmo CRM em outra UF é outro registro')
        ->and(ClinicalReviewer::query()->count())->toBe(2);
});

it('atualiza e registra só OS NOMES dos campos que mudaram', function () {
    $reviewer = crsService()->create(crsData());
    $actor    = User::factory()->create();

    crsService()->update($reviewer, crsData(['phone' => '(11) 91111-2222', 'notes' => 'Contrato assinado em 10/2026.']), $actor);

    $event = crsEvents('updated', (string) $reviewer->id)->sole();

    expect($reviewer->fresh()->phone)->toBe('11911112222')
        ->and($event->meta['fields'])->toEqualCanonicalizing(['phone', 'notes'])
        ->and($event->actor_user_id)->toBe($actor->id)
        // Sem repetir dado pessoal no log.
        ->and(json_encode($event->meta))->not->toContain('91111-2222')->not->toContain('11911112222');
});

it('atualizar sem mudar nada não grava evento', function () {
    $reviewer = crsService()->create(crsData());

    crsService()->update($reviewer, crsData());

    expect(crsEvents('updated', (string) $reviewer->id))->toHaveCount(0);
});

it('desativar revoga os convites PENDENTES do Revisor na mesma operação e registra quantos', function () {
    $reviewer = crsService()->create(crsData());
    $mine     = crfInvitation($reviewer, [crfFrozenVersion('glaucoma')]);
    $mine2    = crfInvitation($reviewer, [crfFrozenVersion('dmri')]);
    $other    = crfInvitation(crfReviewer(), [crfFrozenVersion('ceratocone')]);
    $actor    = User::factory()->create();

    crsService()->setActive($reviewer, false, $actor);

    expect($reviewer->fresh()->active)->toBeFalse()
        ->and($mine['invitation']->fresh()->status)->toBe(Status::Revoked)
        ->and($mine2['invitation']->fresh()->status)->toBe(Status::Revoked)
        ->and($mine['invitation']->fresh()->revoked_reason)->toBe('Revisor desativado.')
        ->and($other['invitation']->fresh()->status)->toBe(Status::Pending);

    $event = crsEvents('deactivated', (string) $reviewer->id)->sole();

    expect($event->from_state)->toBe('active')->and($event->to_state)->toBe('inactive')->and($event->meta['invitations_revoked'])->toBe(2);
});

it('reativar volta a permitir convites; repetir o mesmo estado não faz nada', function () {
    $reviewer = crsService()->create(crsData());

    crsService()->setActive($reviewer, false);
    crsService()->setActive($reviewer, false);
    crsService()->setActive($reviewer, true);
    crsService()->setActive($reviewer, true);

    expect($reviewer->fresh()->active)->toBeTrue()
        ->and(crsEvents('deactivated', (string) $reviewer->id))->toHaveCount(1)
        ->and(crsEvents('activated', (string) $reviewer->id))->toHaveCount(1)
        ->and(crfInvitation($reviewer->fresh())['invitation']->status)->toBe(Status::Pending);
});

it('o Revisor com convite não pode ser apagado do banco (restrict)', function () {
    $created = crfInvitation();

    expect(rivRejected(fn () => DB::table('clinical_reviewers')->where('id', $created['reviewer']->id)->delete()))->toBeTrue();
});
