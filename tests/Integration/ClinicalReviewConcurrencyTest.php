<?php

declare(strict_types=1);

/**
 * Fluxo de revisão clínica × duas sessões PostgreSQL de verdade (ADR 0002).
 *
 * Em cada cenário o processo FILHO faz a operação e SEGURA a transação aberta alguns
 * segundos (com a linha do convite — ou do Revisor — travada); o principal tenta a operação
 * concorrente nessa janela. O serviço tem que ESPERAR a trava e, depois do commit do outro,
 * decidir pelo estado COMITADO: nunca duas respostas ao mesmo convite, nunca resposta a
 * convite revogado, nunca rascunho ressuscitando depois do envio, nunca convite para
 * Revisor desativado.
 *
 * Fica em tests/Integration (DatabaseMigrations, dados comitados) e roda por caminho
 * explícito; depois: php artisan migrate:fresh --env=testing --force.
 */

use App\Domains\ClinicalReview\Enums\{AcceptanceDecision, ReviewInvitationStatus as Status};
use App\Domains\ClinicalReview\Exceptions\{ReviewAcceptanceException, ReviewInvitationException, ReviewSubmissionException};
use App\Domains\ClinicalReview\Models\{ClinicalGovernanceEvent, ClinicalReviewAttestation, ClinicalReviewInvitation};
use App\Domains\ClinicalReview\Services\{ClinicalReviewerService, ReviewAcceptanceService, ReviewDraftService, ReviewInvitationService};
use App\Domains\ClinicalReview\Support\ReviewToken;
use App\Domains\Recall\Enums\ProtocolVersionState as VState;
use App\Domains\Recall\Exceptions\ProtocolVersionException;
use App\Domains\Recall\Models\RecallProtocolVersion;
use App\Domains\Recall\Services\{ProtocolCatalogService, ProtocolGovernanceService};
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Notification, Process};
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, DatabaseMigrations::class);

/** Ambiente do processo filho: mesmo banco de teste desta suíte. */
function crcChildEnv(array $extra = []): array
{
    $db = config('database.connections.' . config('database.default'));

    return $extra + [
        'APP_ENV'          => 'testing',
        'CACHE_STORE'      => 'array',
        'SESSION_DRIVER'   => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'MAIL_MAILER'      => 'array',
        'DB_CONNECTION'    => config('database.default'),
        'DB_HOST'          => (string) ($db['host'] ?? ''),
        'DB_PORT'          => (string) ($db['port'] ?? ''),
        'DB_DATABASE'      => (string) ($db['database'] ?? ''),
        'DB_USERNAME'      => (string) ($db['username'] ?? ''),
        'DB_PASSWORD'      => (string) ($db['password'] ?? ''),
    ];
}

/**
 * Sobe o filho, espera ele estar com a operação feita e a transação aberta (marcador) e
 * roda `$mainOperation` no principal nessa janela.
 *
 * @return array{0: float, 1: ?Throwable, 2: ProcessResult} quanto o principal esperou, o que lançou, o resultado do filho
 */
function crcRaceAgainstChild(string $mode, string $id, callable $mainOperation, array $env = [], float $hold = 2.5): array
{
    $marker = sys_get_temp_dir() . '/crc-' . Str::uuid();

    $child = Process::path(base_path())
        ->env(crcChildEnv($env))
        ->timeout(60)
        ->start([PHP_BINARY, 'tests/Integration/Support/clinical_review_child.php', $mode, $id, $marker, (string) $hold]);

    try {
        $deadline = microtime(true) + 30;

        while (! is_file($marker) && microtime(true) < $deadline && $child->running()) {
            usleep(50_000);
        }

        // Rede de segurança contra pendurar a suíte.
        DB::statement("set statement_timeout = '30s'");

        $startedAt = microtime(true);
        $thrown    = null;

        try {
            $mainOperation();
        } catch (Throwable $e) {
            $thrown = $e;
        }

        return [microtime(true) - $startedAt, $thrown, $child->wait()];
    } finally {
        @unlink($marker);
    }
}

function crcEvents(string $subjectId, string $event): int
{
    return ClinicalGovernanceEvent::query()->where('subject_id', $subjectId)->where('event', $event)->count();
}

beforeEach(fn () => Notification::fake());

it('[CORRIDA] dois envios do parecer: o 2º espera, vê o convite respondido e é recusado — UM atestado, a decisão do 1º fica', function () {
    $created = crfInvitation();
    $id      = (string) $created['invitation']->id;

    [$waited, $thrown, $child] = crcRaceAgainstChild('submit', $id, fn () => crfSubmit($created, 'rejected', 'Segundo envio, que não pode valer.'));

    expect($child->successful())->toBeTrue("filho falhou — stderr: {$child->errorOutput()}")
        ->and($waited)->toBeGreaterThan(1.0)
        ->and($thrown)->toBeInstanceOf(ReviewSubmissionException::class)
        ->and($thrown->reason())->toBe(ReviewSubmissionException::NOT_OPEN)
        ->and(ClinicalReviewAttestation::query()->count())->toBe(1)
        ->and(crcEvents($id, 'submitted'))->toBe(1)
        // A decisão gravada é a do filho (aprovado), não a do envio que perdeu a corrida.
        ->and($created['invitation']->items()->sole()->decision->value)->toBe('approved')
        ->and($created['invitation']->fresh()->status)->toBe(Status::Submitted);
});

it('[CORRIDA] envio × revogação: a revogação espera, vê o convite respondido e é recusada — nada fica revogado', function () {
    $created = crfInvitation();
    $id      = (string) $created['invitation']->id;

    [$waited, $thrown, $child] = crcRaceAgainstChild('submit', $id, fn () => app(ReviewInvitationService::class)->revoke($created['invitation'], 'Revogando no meio do envio.'));

    $invitation = $created['invitation']->fresh();

    expect($child->successful())->toBeTrue("filho falhou — stderr: {$child->errorOutput()}")
        ->and($waited)->toBeGreaterThan(1.0)
        ->and($thrown)->toBeInstanceOf(ReviewInvitationException::class)
        ->and($thrown->reason())->toBe(ReviewInvitationException::NOT_PENDING)
        ->and($invitation->status)->toBe(Status::Submitted)
        ->and($invitation->revoked_at)->toBeNull()
        ->and(crcEvents($id, 'revoked'))->toBe(0)
        ->and(ClinicalReviewAttestation::query()->count())->toBe(1);
});

it('[CORRIDA] revogação × envio: o envio espera, vê o convite revogado e é recusado — nenhuma decisão nem atestado', function () {
    $created = crfInvitation();
    $id      = (string) $created['invitation']->id;

    [$waited, $thrown, $child] = crcRaceAgainstChild('revoke', $id, fn () => crfSubmit($created));

    expect($child->successful())->toBeTrue("filho falhou — stderr: {$child->errorOutput()}")
        ->and($waited)->toBeGreaterThan(1.0)
        ->and($thrown)->toBeInstanceOf(ReviewSubmissionException::class)
        ->and($thrown->reason())->toBe(ReviewSubmissionException::NOT_OPEN)
        ->and($created['invitation']->fresh()->status)->toBe(Status::Revoked)
        ->and(ClinicalReviewAttestation::query()->count())->toBe(0)
        ->and($created['invitation']->items()->sole()->decision)->toBeNull();
});

it('[CORRIDA] rascunho tardio × envio: o rascunho espera, vê o convite respondido e NÃO ressuscita — nada cifrado fica para trás', function () {
    $created = crfInvitation();
    $id      = (string) $created['invitation']->id;
    $itemId  = (string) $created['invitation']->items()->sole()->id;

    [$waited, $thrown, $child] = crcRaceAgainstChild(
        'submit',
        $id,
        fn () => app(ReviewDraftService::class)->save($created['invitation'], ['decisions' => [$itemId => ['decision' => 'approved', 'comment' => 'texto que não pode ficar gravado']]]),
    );

    $invitation = $created['invitation']->fresh();

    expect($child->successful())->toBeTrue("filho falhou — stderr: {$child->errorOutput()}")
        ->and($waited)->toBeGreaterThan(1.0)
        ->and($thrown)->toBeInstanceOf(ReviewSubmissionException::class)
        ->and($thrown->reason())->toBe(ReviewSubmissionException::NOT_OPEN)
        ->and($invitation->status)->toBe(Status::Submitted)
        ->and(DB::table('clinical_review_invitations')->where('id', $id)->value('draft_payload'))->toBeNull()
        ->and($invitation->draft_saved_at)->toBeNull();
});

it('[CORRIDA] envio × rascunho em andamento: o envio espera o rascunho comitar e depois LIMPA o rascunho', function () {
    $created = crfInvitation();
    $id      = (string) $created['invitation']->id;

    [$waited, $thrown, $child] = crcRaceAgainstChild('draft', $id, fn () => crfSubmit($created));

    expect($child->successful())->toBeTrue("filho falhou — stderr: {$child->errorOutput()}")
        ->and($waited)->toBeGreaterThan(1.0)
        ->and($thrown)->toBeNull()
        ->and($created['invitation']->fresh()->status)->toBe(Status::Submitted)
        ->and(DB::table('clinical_review_invitations')->where('id', $id)->value('draft_payload'))->toBeNull()
        ->and(ClinicalReviewAttestation::query()->count())->toBe(1);
});

it('[CORRIDA] dois convites do mesmo Revisor para o mesmo conjunto: o 2º espera e recebe o erro de negócio, não violação de índice', function () {
    $reviewer = crfReviewer();
    $version  = crfFrozenVersion('glaucoma');

    [$waited, $thrown, $child] = crcRaceAgainstChild(
        'create',
        (string) $reviewer->id,
        fn ()              => app(ReviewInvitationService::class)->create($reviewer, [(string) $version->id], null, 14),
        ['CRC_VERSION_IDS' => (string) $version->id],
    );

    expect($child->successful())->toBeTrue("filho falhou — stderr: {$child->errorOutput()}")
        ->and($waited)->toBeGreaterThan(1.0)
        ->and($thrown)->toBeInstanceOf(ReviewInvitationException::class)
        ->and($thrown->reason())->toBe(ReviewInvitationException::DUPLICATE_PENDING)
        ->and(ClinicalReviewInvitation::query()->where('status', 'pending')->count())->toBe(1);
});

it('[CORRIDA] desativar o Revisor × criar convite: a criação espera, vê o Revisor inativo e é recusada', function () {
    $reviewer = crfReviewer();
    $version  = crfFrozenVersion('glaucoma');

    [$waited, $thrown, $child] = crcRaceAgainstChild(
        'deactivate',
        (string) $reviewer->id,
        fn () => app(ReviewInvitationService::class)->create($reviewer, [(string) $version->id], null, 14),
    );

    expect($child->successful())->toBeTrue("filho falhou — stderr: {$child->errorOutput()}")
        ->and($waited)->toBeGreaterThan(1.0)
        ->and($thrown)->toBeInstanceOf(ReviewInvitationException::class)
        ->and($thrown->reason())->toBe(ReviewInvitationException::REVIEWER_INACTIVE)
        ->and(ClinicalReviewInvitation::query()->count())->toBe(0)
        ->and($reviewer->fresh()->active)->toBeFalse();
});

it('[CORRIDA] criar convite × desativar o Revisor: a desativação espera e REVOGA o convite que acabou de nascer', function () {
    $reviewer = crfReviewer();
    $version  = crfFrozenVersion('glaucoma');

    [$waited, $thrown, $child] = crcRaceAgainstChild(
        'create',
        (string) $reviewer->id,
        fn ()              => app(ClinicalReviewerService::class)->setActive($reviewer, false),
        ['CRC_VERSION_IDS' => (string) $version->id],
    );

    $invitation = ClinicalReviewInvitation::query()->sole();

    expect($child->successful())->toBeTrue("filho falhou — stderr: {$child->errorOutput()}")
        ->and($waited)->toBeGreaterThan(1.0)
        ->and($thrown)->toBeNull()
        ->and($reviewer->fresh()->active)->toBeFalse()
        // Sem isto o Revisor desativado ficaria com um link pendente funcionando.
        ->and($invitation->status)->toBe(Status::Revoked);
});

it('[CORRIDA] dois reenvios: valem os dois em sequência e SÓ o último link continua abrindo', function () {
    $created = crfInvitation();
    $id      = (string) $created['invitation']->id;

    $mainToken = null;

    [$waited, $thrown, $child] = crcRaceAgainstChild('resend', $id, function () use ($created, &$mainToken) {
        $mainToken = app(ReviewInvitationService::class)->resend($created['invitation'])['token'];
    });

    $childToken = trim(Str::after($child->output(), 'OK '));
    $stored     = (string) $created['invitation']->fresh()->token_hash;

    expect($child->successful())->toBeTrue("filho falhou — stderr: {$child->errorOutput()}")
        ->and($waited)->toBeGreaterThan(1.0)
        ->and($thrown)->toBeNull()
        ->and($childToken)->not->toBe('')
        ->and(ReviewToken::matches((string) $mainToken, $stored))->toBeTrue()
        ->and(ReviewToken::matches($childToken, $stored))->toBeFalse()
        ->and(ReviewToken::matches($created['token'], $stored))->toBeFalse()
        ->and(crcEvents($id, 'resent'))->toBe(2);
});

// ── governança: aceite, liberação e retirada × outra sessão ─────────────────

it('[CORRIDA] dois aceites do mesmo parecer: o 2º espera, vê o convite já conferido e é recusado — UM aceite', function () {
    $created = crfInvitation();
    crfSubmit($created);
    $id = (string) $created['invitation']->id;

    [$waited, $thrown, $child] = crcRaceAgainstChild('accept', $id, fn () => app(ReviewAcceptanceService::class)->record($created['invitation']->fresh(), AcceptanceDecision::Accepted, true, true, null));

    expect($child->successful())->toBeTrue("filho falhou — stderr: {$child->errorOutput()}")
        ->and($waited)->toBeGreaterThan(1.0)
        ->and($thrown)->toBeInstanceOf(ReviewAcceptanceException::class)
        ->and($thrown->reason())->toBe(ReviewAcceptanceException::NOT_SUBMITTED)
        ->and(DB::table('clinical_review_acceptances')->count())->toBe(1)
        ->and($created['versions'][0]->fresh()->state)->toBe(VState::Validated)
        ->and(crcEvents((string) $created['versions'][0]->id, 'validated'))->toBe(1);
});

it('[CORRIDA] retirar a versão × aceite: o aceite espera, vê a versão retirada e NÃO a valida', function () {
    $created = crfInvitation();
    crfSubmit($created);
    $version = $created['versions'][0];

    [$waited, $thrown, $child] = crcRaceAgainstChild('withdraw', (string) $version->id, fn () => app(ReviewAcceptanceService::class)->record($created['invitation']->fresh(), AcceptanceDecision::Accepted, true, true, null));

    expect($child->successful())->toBeTrue("filho falhou — stderr: {$child->errorOutput()}")
        ->and($waited)->toBeGreaterThan(1.0)
        ->and($thrown)->toBeNull()
        // O aceite vale para o convite, mas a versão que saiu de revisão fica como saiu: retirada, nunca validada.
        ->and($version->fresh()->state)->toBe(VState::Withdrawn)
        ->and($version->fresh()->validated_acceptance_id)->toBeNull()
        ->and(crcEvents((string) $version->id, 'validated'))->toBe(0);
});

it('[CORRIDA] retirar a versão × criar convite: a criação espera, vê a versão retirada e é recusada — nenhum convite pendente órfão', function () {
    $reviewer = crfReviewer();
    $version  = crfFrozenVersion('glaucoma');

    [$waited, $thrown, $child] = crcRaceAgainstChild(
        'withdraw',
        (string) $version->id,
        fn () => app(ReviewInvitationService::class)->create($reviewer, [(string) $version->id], null, 14),
    );

    expect($child->successful())->toBeTrue("filho falhou — stderr: {$child->errorOutput()}")
        ->and($waited)->toBeGreaterThan(1.0)
        ->and($thrown)->toBeInstanceOf(ReviewInvitationException::class)
        ->and($thrown->reason())->toBe(ReviewInvitationException::VERSION_NOT_IN_REVIEW)
        ->and(ClinicalReviewInvitation::query()->count())->toBe(0);
});

it('[CORRIDA] retirar a versão × envio do parecer: o envio espera, vê o convite revogado e é recusado', function () {
    $created = crfInvitation();

    [$waited, $thrown, $child] = crcRaceAgainstChild('withdraw', (string) $created['versions'][0]->id, fn () => crfSubmit($created));

    expect($child->successful())->toBeTrue("filho falhou — stderr: {$child->errorOutput()}")
        ->and($waited)->toBeGreaterThan(1.0)
        ->and($thrown)->toBeInstanceOf(ReviewSubmissionException::class)
        ->and($thrown->reason())->toBe(ReviewSubmissionException::NOT_OPEN)
        ->and($created['invitation']->fresh()->status)->toBe(Status::Revoked)
        ->and(ClinicalReviewAttestation::query()->count())->toBe(0);
});

it('[CORRIDA] duas liberações da mesma versão: a 2ª espera, vê released e é recusada — um só evento de liberação', function () {
    $validated = crfValidatedVersion();
    $version   = $validated['version'];

    [$waited, $thrown, $child] = crcRaceAgainstChild('release', (string) $version->id, fn () => app(ProtocolGovernanceService::class)->release($version));

    expect($child->successful())->toBeTrue("filho falhou — stderr: {$child->errorOutput()}")
        ->and($waited)->toBeGreaterThan(1.0)
        ->and($thrown)->toBeInstanceOf(ProtocolVersionException::class)
        ->and($thrown->reason())->toBe(ProtocolVersionException::INVALID_TRANSITION)
        ->and($version->fresh()->state)->toBe(VState::Released)
        ->and(crcEvents((string) $version->id, 'released'))->toBe(1);
});

it('[CORRIDA] liberar a v1 × liberar a v2: a 2ª espera, substitui a v1 — no fim UMA released e a outra superseded', function () {
    $governance = app(ProtocolGovernanceService::class);
    $catalog    = app(ProtocolCatalogService::class);

    $first = crfValidatedVersion('glaucoma')['version'];

    // A v2 nasce enquanto a v1 só está validada (não há versão "em andamento").
    $second  = $catalog->startRevision($first->protocol);
    $content = $second->contentArray();

    $content['change_note']                     = 'Campimetria a cada 9 meses.';
    $content['tasks'][1]['interval']['default'] = 9;
    $catalog->saveDraft($second, $content, 0);
    $second  = $catalog->freeze($second->fresh());
    $created = crfInvitation(null, [$second]);
    crfSubmit($created);
    app(ReviewAcceptanceService::class)->record($created['invitation']->fresh(), AcceptanceDecision::Accepted, true, true, null);

    [$waited, $thrown, $child] = crcRaceAgainstChild('release', (string) $first->id, fn () => $governance->release($second->fresh()));

    expect($child->successful())->toBeTrue("filho falhou — stderr: {$child->errorOutput()}")
        ->and($waited)->toBeGreaterThan(1.0)
        ->and($thrown)->toBeNull()
        ->and($first->fresh()->state)->toBe(VState::Superseded)
        ->and($second->fresh()->state)->toBe(VState::Released)
        ->and(RecallProtocolVersion::query()->where('protocol_id', $first->protocol_id)->where('state', 'released')->count())->toBe(1);
});

it('[CORRIDA] duas suspensões: a 2ª espera, vê suspensa e é recusada — um só evento', function () {
    $governance = app(ProtocolGovernanceService::class);
    $released   = $governance->release(crfValidatedVersion()['version']);

    [$waited, $thrown, $child] = crcRaceAgainstChild('suspend', (string) $released->id, fn () => $governance->suspend($released, 'Segunda suspensão, com um motivo suficientemente longo.'));

    expect($child->successful())->toBeTrue("filho falhou — stderr: {$child->errorOutput()}")
        ->and($waited)->toBeGreaterThan(1.0)
        ->and($thrown)->toBeInstanceOf(ProtocolVersionException::class)
        ->and($released->fresh()->state)->toBe(VState::Suspended)
        ->and(crcEvents((string) $released->id, 'suspended'))->toBe(1);
});

// A governança lê a versão sob a trava das irmãs: o aceite (que trava só as versões, sem o protocolo) comitado no
// meio do caminho tem que ser visto — senão a ação parte de um estado velho e o histórico mente.

it('[CORRIDA] aceite × aposentar a versão: a aposentadoria espera, vê a versão VALIDADA e grava a transição a partir dela', function () {
    $created = crfInvitation();
    crfSubmit($created);
    $version = $created['versions'][0];

    [$waited, $thrown, $child] = crcRaceAgainstChild('accept', (string) $created['invitation']->id, fn () => app(ProtocolGovernanceService::class)->retire($version, 'Aposentada no meio do aceite, com motivo longo.'));

    $event = ClinicalGovernanceEvent::query()->where('subject_id', $version->id)->where('event', 'retired')->sole();

    expect($child->successful())->toBeTrue("filho falhou — stderr: {$child->errorOutput()}")
        ->and($waited)->toBeGreaterThan(1.0)
        ->and($thrown)->toBeNull()
        ->and($version->fresh()->state)->toBe(VState::Retired)
        // O aceite comitou primeiro: a aposentadoria parte de "validada", não da leitura velha ("em revisão").
        ->and($event->from_state)->toBe(VState::Validated->value)
        ->and(crcEvents((string) $version->id, 'validated'))->toBe(1);
});

it('[CORRIDA] aceite × liberação: a liberação espera o aceite comitar, vê a versão validada e LIBERA — não recusa por leitura velha', function () {
    $created = crfInvitation();
    crfSubmit($created);
    $version = $created['versions'][0];

    [$waited, $thrown, $child] = crcRaceAgainstChild('accept', (string) $created['invitation']->id, fn () => app(ProtocolGovernanceService::class)->release($version));

    expect($child->successful())->toBeTrue("filho falhou — stderr: {$child->errorOutput()}")
        ->and($waited)->toBeGreaterThan(1.0)
        ->and($thrown)->toBeNull()
        ->and($version->fresh()->state)->toBe(VState::Released)
        ->and(crcEvents((string) $version->id, 'released'))->toBe(1);
});
