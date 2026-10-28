<?php

/**
 * Fixtures da revisão clínica (Fase 6): Revisor, versão congelada, convite criado pelo
 * serviço de verdade (com o token em memória), parecer válido. Funções globais (como
 * P2ManifestFixture), com prefixo `crf` para não colidir com helper de outro teste.
 */

use App\Domains\ClinicalReview\Enums\AcceptanceDecision;
use App\Domains\ClinicalReview\Models\{ClinicalReviewAcceptance, ClinicalReviewAttestation};
use App\Domains\ClinicalReview\Models\{ClinicalReviewInvitation, ClinicalReviewer};
use App\Domains\ClinicalReview\Services\{ReviewAcceptanceService, ReviewInvitationService, ReviewSubmissionService};
use App\Domains\ClinicalReview\Support\AttestationStatement;
use App\Domains\Recall\Enums\ProtocolVersionState;
use App\Domains\Recall\Models\{RecallProtocol, RecallProtocolVersion};
use App\Domains\Recall\Services\ProtocolCatalogService;
use App\Domains\Recall\Support\ProposedProtocolDrafts;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\{DB, Mail};
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/** @param array<string, mixed> $overrides */
function crfReviewer(array $overrides = []): ClinicalReviewer
{
    static $sequence = 0;
    $sequence++;

    return ClinicalReviewer::query()->create(array_merge([
        'name'       => 'DRA. ANA PAULA SOUZA',
        'email'      => "revisora{$sequence}-" . Str::lower(Str::random(6)) . '@exemplo.test',
        'crm_number' => (string) (10000 + $sequence),
        'crm_uf'     => 'SP',
        'rqe'        => (string) (2000 + $sequence),
        'specialty'  => 'Oftalmologia',
        'active'     => true,
    ], $overrides));
}

/** Protocolo (da proposta inicial) com a versão 1 CONGELADA para revisão. */
function crfFrozenVersion(string $key = 'glaucoma', ?User $actor = null): RecallProtocolVersion
{
    $proposal = ProposedProtocolDrafts::all()[$key];
    $catalog  = app(ProtocolCatalogService::class);

    $protocol = RecallProtocol::query()->where('key', $key)->first()
        ?? $catalog->createProtocol($key, $proposal['translations'], $proposal['content'], $proposal['sort_order'], $actor);

    $version = $protocol->versions()->firstOrFail();

    // Idempotente: pedir de novo a versão já congelada devolve a mesma (vários convites do mesmo protocolo).
    return $version->state === ProtocolVersionState::Draft ? $catalog->freeze($version, $actor) : $version;
}

/**
 * Convite criado de verdade (ReviewInvitationService::create), SEM enviar e-mail.
 *
 * @param list<RecallProtocolVersion>|null $versions
 *
 * @return array{invitation: ClinicalReviewInvitation, token: string, accessCode: ?string, reviewer: ClinicalReviewer, versions: list<RecallProtocolVersion>}
 */
function crfInvitation(?ClinicalReviewer $reviewer = null, ?array $versions = null, ?string $pin = null, int $days = 14, ?User $actor = null): array
{
    $reviewer ??= crfReviewer();
    $versions ??= [crfFrozenVersion('glaucoma', $actor)];

    $created = app(ReviewInvitationService::class)->create(
        $reviewer,
        array_map(static fn (RecallProtocolVersion $version): string => (string) $version->id, $versions),
        $pin,
        $days,
        $actor,
    );

    return $created + ['reviewer' => $reviewer, 'versions' => $versions];
}

/** URL assinada do 1º salto (a que o e-mail leva). */
function crfOpenUrl(array $created): string
{
    return app(ReviewInvitationService::class)->url($created['invitation'], $created['token']);
}

/**
 * Decisões de todos os itens do convite.
 *
 * @return array<string, array{decision: string, comment: ?string, proposed_changes: ?string}>
 */
function crfDecisions(ClinicalReviewInvitation $invitation, string $decision = 'approved', ?string $comment = null): array
{
    $rows = [];

    foreach ($invitation->items()->get() as $item) {
        $rows[(string) $item->id] = ['decision' => $decision, 'comment' => $comment, 'proposed_changes' => null];
    }

    return $rows;
}

/**
 * Atestado válido (o que a página envia).
 *
 * @param array<string, mixed> $overrides
 *
 * @return array<string, mixed>
 */
function crfAttestation(ClinicalReviewer $reviewer, array $overrides = []): array
{
    return array_merge([
        'name'                   => $reviewer->name,
        'email'                  => $reviewer->email,
        'crm_number'             => $reviewer->crm_number,
        'crm_uf'                 => $reviewer->crm_uf,
        'rqe'                    => $reviewer->rqe,
        'specialty'              => $reviewer->specialty,
        'has_conflict'           => false,
        'conflict_description'   => null,
        'allow_identity_display' => true,
        'agreed'                 => true,
    ], $overrides);
}

/**
 * Envia o parecer pelo serviço (o que o POST faz).
 *
 * @param array<string, mixed> $created     resultado de crfInvitation()
 * @param array<string, mixed> $attestation
 */
function crfSubmit(array $created, string $decision = 'approved', ?string $comment = null, array $attestation = [], ?array $decisions = null): ClinicalReviewAttestation
{
    $statement = AttestationStatement::current();

    return app(ReviewSubmissionService::class)->submit(
        $created['invitation'],
        $decisions ?? crfDecisions($created['invitation'], $decision, $comment),
        crfAttestation($created['reviewer'], $attestation),
        (string) $statement->id,
        AttestationStatement::hash((string) $statement->content),
        '203.0.113.7',
        'Mozilla/5.0 (teste)',
    );
}

/**
 * Versão VALIDADA de verdade: congelada, convite, parecer aprovado e aceite com a conferência feita —
 * pelos mesmos serviços do produto. É o ponto de partida de liberar/suspender/aposentar.
 *
 * @return array{version: RecallProtocolVersion, invitation: ClinicalReviewInvitation, acceptance: ClinicalReviewAcceptance, created: array<string, mixed>, reviewer: ClinicalReviewer}
 */
function crfValidatedVersion(string $key = 'glaucoma', ?User $actor = null, ?ClinicalReviewer $reviewer = null): array
{
    $version = crfFrozenVersion($key, $actor);
    $created = crfInvitation($reviewer, [$version], null, 14, $actor);

    crfSubmit($created);

    $acceptance = app(ReviewAcceptanceService::class)->record($created['invitation']->fresh(), AcceptanceDecision::Accepted, true, true, null, $actor);

    return [
        'version'    => $version->fresh(),
        'invitation' => $created['invitation']->fresh(),
        'acceptance' => $acceptance,
        'created'    => $created,
        'reviewer'   => $created['reviewer'],
    ];
}

/**
 * O banco tem que recusar a instrução? Roda dentro de SAVEPOINT para não abortar a transação do
 * teste (PostgreSQL aborta tudo depois de um erro).
 */
function rivRejected(callable $statement): bool
{
    try {
        DB::transaction($statement);
    } catch (QueryException) {
        return true;
    }

    return false;
}

/**
 * Adultera o hash gravado de uma versão congelada, como faria quem é dono da tabela e desligou o
 * trigger de imutabilidade (a verificação noturna existe para pegar isso).
 */
function crfTamperVersionHash(RecallProtocolVersion $version): void
{
    DB::unprepared('ALTER TABLE recall_protocol_versions DISABLE TRIGGER recall_protocol_versions_guard');
    DB::table('recall_protocol_versions')->where('id', $version->id)->update(['content_hash' => '1:' . str_repeat('0', 64)]);
    DB::unprepared('ALTER TABLE recall_protocol_versions ENABLE TRIGGER recall_protocol_versions_guard');
}

/** Adultera o TEXTO gravado (o hash segue o original): o conteúdo deixa de bater com o hash. */
function crfTamperVersionContent(RecallProtocolVersion $version): void
{
    DB::unprepared('ALTER TABLE recall_protocol_versions DISABLE TRIGGER recall_protocol_versions_guard');
    DB::table('recall_protocol_versions')->where('id', $version->id)->update(['content' => DB::raw("content || ' '")]);
    DB::unprepared('ALTER TABLE recall_protocol_versions ENABLE TRIGGER recall_protocol_versions_guard');
}

/** Troca o transporte de e-mail por um que SEMPRE falha (como um SMTP fora do ar). */
function rivBreakMail(): void
{
    Mail::extend('failing-test', fn () => new class() extends AbstractTransport {
        protected function doSend(SentMessage $message): void
        {
            throw new TransportException('SMTP indisponível (teste)');
        }

        public function __toString(): string
        {
            return 'failing-test';
        }
    });

    config(['mail.default' => 'failing-test', 'mail.mailers.failing-test' => ['transport' => 'failing-test']]);
}
