<?php

declare(strict_types=1);

namespace App\Domains\ClinicalReview\Services;

use App\Domains\ClinicalReview\Enums\{ReviewDecision, ReviewInvitationStatus};
use App\Domains\ClinicalReview\Exceptions\ReviewSubmissionException;
use App\Domains\ClinicalReview\Models\{ClinicalReviewAttestation, ClinicalReviewInvitation, ClinicalReviewItem, ClinicalReviewer};
use App\Domains\ClinicalReview\Notifications\ClinicalReviewReceiptNotification;
use App\Domains\ClinicalReview\Support\{AttestationStatement, ReviewHashes, ReviewerIdentity};
use App\Domains\Recall\Enums\ProtocolVersionState;
use App\Domains\Recall\Models\{RecallProtocol, RecallProtocolVersion};
use Illuminate\Support\Facades\{DB, Notification};
use Throwable;

/**
 * O envio do parecer do Revisor (ADR 0002): UMA transação, UM POST, uma decisão por versão.
 *
 * Ordem de locks (global): versão (id crescente) → convite. Sob as travas o serviço confere
 * que o convite ainda está pendente e dentro do prazo, que cada versão continua `in_review`
 * COM o mesmo hash que o Revisor viu e que a declaração é a vigente; só então grava as
 * decisões, o atestado (texto exato da declaração, IP, navegador e o hash do envio) e fecha
 * o convite — tudo ou nada, com os eventos de governança na MESMA transação.
 *
 * O e-mail de recibo sai depois do commit; falha de envio não desfaz o parecer.
 */
class ReviewSubmissionService
{
    public function __construct(private readonly GovernanceLog $log)
    {
    }

    /**
     * @param array<string, array{decision: string, comment?: ?string, proposed_changes?: ?string}>                                                                                                                 $decisions   por id do item
     * @param array{name: string, email: string, crm_number: string, crm_uf: string, rqe: string, specialty: string, has_conflict: bool, conflict_description: ?string, allow_identity_display: bool, agreed: bool} $attestation
     *
     * @throws ReviewSubmissionException
     */
    public function submit(
        ClinicalReviewInvitation $invitation,
        array $decisions,
        array $attestation,
        string $statementTermVersionId,
        string $statementSha256,
        ?string $ip,
        ?string $userAgent,
    ): ClinicalReviewAttestation {
        $declared = $this->normalizeAttestation($attestation);

        $result = DB::transaction(function () use ($invitation, $decisions, $declared, $statementTermVersionId, $statementSha256, $ip, $userAgent): array {
            $versionIds = ClinicalReviewItem::query()->where('invitation_id', $invitation->id)->pluck('subject_id')->sort()->values()->all();

            $versions = RecallProtocolVersion::query()
                ->with('protocol:id,key')
                ->whereIn('id', $versionIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $locked = ClinicalReviewInvitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isOpenForReviewer()) {
                throw ReviewSubmissionException::notOpen();
            }

            $items = $locked->items()->get();

            $this->assertSetUnchanged($locked, $items->all(), $versions->all());

            $statement = AttestationStatement::current();
            $official  = (string) $statement->content;

            if ((string) $statement->id !== $statementTermVersionId || AttestationStatement::hash($official) !== $statementSha256) {
                throw ReviewSubmissionException::statementChanged();
            }

            if (! $declared['agreed']) {
                throw ReviewSubmissionException::statementNotAccepted();
            }

            if ($declared['has_conflict'] && ($declared['conflict_description'] ?? '') === '') {
                throw ReviewSubmissionException::conflictDescriptionRequired();
            }

            $normalizedDecisions = $this->normalizeDecisions($items->all(), $decisions);

            $signedAt = now();

            $attestationRow = [
                'name'                   => $declared['name'],
                'email'                  => $declared['email'],
                'crm_number'             => $declared['crm_number'],
                'crm_uf'                 => $declared['crm_uf'],
                'rqe'                    => $declared['rqe'],
                'specialty'              => $declared['specialty'],
                'statement_sha256'       => $statementSha256,
                'conflict_of_interest'   => $declared['has_conflict'],
                'conflict_description'   => $declared['conflict_description'],
                'allow_identity_display' => $declared['allow_identity_display'],
            ];

            $submissionHash = ReviewHashes::submissionHash(
                (string) $locked->set_hash,
                array_map(static fn (string $id, array $row): array => ['subject_id' => $id] + $row, array_keys($normalizedDecisions), $normalizedDecisions),
                $attestationRow,
                $signedAt,
            );

            foreach ($items as $item) {
                $row = $normalizedDecisions[(string) $item->id];

                $item->forceFill([
                    'decision'         => $row['decision'],
                    'comment'          => $row['comment'],
                    'proposed_changes' => $row['proposed_changes'],
                    'decided_at'       => $signedAt,
                ])->save();
            }

            $record = ClinicalReviewAttestation::query()->create([
                'invitation_id'             => $locked->id,
                'declared_name'             => $declared['name'],
                'declared_email'            => $declared['email'],
                'declared_crm_number'       => $declared['crm_number'],
                'declared_crm_uf'           => $declared['crm_uf'],
                'declared_rqe'              => $declared['rqe'],
                'declared_specialty'        => $declared['specialty'],
                'statement_term_version_id' => $statement->id,
                'statement_sha256'          => $statementSha256,
                'statement_text'            => $official,
                'conflict_of_interest'      => $declared['has_conflict'],
                'conflict_description'      => $declared['conflict_description'],
                'allow_identity_display'    => $declared['allow_identity_display'],
                'ip'                        => $ip,
                'user_agent'                => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
                'signed_at'                 => $signedAt,
                'set_hash'                  => $locked->set_hash,
                'submission_hash'           => $submissionHash,
            ]);

            $locked->forceFill([
                'status'         => ReviewInvitationStatus::Submitted->value,
                'submitted_at'   => $signedAt,
                'draft_payload'  => null,
                'draft_saved_at' => null,
            ])->save();

            $mismatch = $this->identityMismatch($locked->reviewer_id, $declared);
            $label    = 'reviewer:' . $locked->reviewer_id;

            $this->log->record(
                GovernanceLog::SUBJECT_REVIEW_INVITATION,
                (string) $locked->id,
                'submitted',
                ReviewInvitationStatus::Pending->value,
                ReviewInvitationStatus::Submitted->value,
                null,
                null,
                ['submission_hash' => $submissionHash, 'identity_matches_registry' => $mismatch === [], 'identity_mismatch_fields' => $mismatch],
                $label,
            );

            foreach ($items as $item) {
                $this->log->record(
                    GovernanceLog::SUBJECT_PROTOCOL_VERSION,
                    (string) $item->subject_id,
                    'review_submitted',
                    ProtocolVersionState::InReview->value,
                    ProtocolVersionState::InReview->value,
                    null,
                    null,
                    ['decision' => $normalizedDecisions[(string) $item->id]['decision'], 'invitation_id' => (string) $locked->id, 'submission_hash' => $submissionHash],
                    $label,
                );
            }

            return ['attestation' => $record, 'invitation' => $locked, 'decisions' => $normalizedDecisions, 'items' => $items];
        });

        $this->sendReceipt($result['invitation'], $result['attestation'], $result['items']->all(), $result['decisions']);

        return $result['attestation'];
    }

    /**
     * @param array<int, ClinicalReviewItem>       $items
     * @param array<string, RecallProtocolVersion> $versions
     *
     * @throws ReviewSubmissionException
     */
    private function assertSetUnchanged(ClinicalReviewInvitation $invitation, array $items, array $versions): void
    {
        $set = [];

        foreach ($items as $item) {
            $version = $versions[$item->subject_id] ?? null;

            if ($version === null || $version->state !== ProtocolVersionState::InReview || $version->content_hash !== $item->content_hash) {
                throw ReviewSubmissionException::setChanged();
            }

            $set[] = [
                'subject_type'    => $item->subject_type,
                'subject_key'     => $item->subject_key,
                'subject_version' => $item->subject_version,
                'content_hash'    => $item->content_hash,
            ];
        }

        if (ReviewHashes::setHash($set) !== $invitation->set_hash) {
            throw ReviewSubmissionException::setChanged();
        }
    }

    /**
     * Toda versão do convite tem decisão; só "aprovo" dispensa justificativa. Id que não é do
     * convite é ignorado (nunca decide por versão alheia).
     *
     * @param array<int, ClinicalReviewItem>                                                        $items
     * @param array<string, array{decision: string, comment?: ?string, proposed_changes?: ?string}> $decisions
     *
     * @return array<string, array{decision: string, comment: ?string, proposed_changes: ?string}>
     *
     * @throws ReviewSubmissionException
     */
    private function normalizeDecisions(array $items, array $decisions): array
    {
        $normalized = [];

        foreach ($items as $item) {
            $given    = $decisions[(string) $item->id] ?? null;
            $decision = is_array($given) ? ReviewDecision::tryFrom((string) ($given['decision'] ?? '')) : null;

            if ($decision === null) {
                throw ReviewSubmissionException::missingDecision();
            }

            $comment = ReviewerIdentity::text($given['comment'] ?? null);

            if ($decision->requiresComment() && mb_strlen($comment) < 10) {
                throw ReviewSubmissionException::commentRequired();
            }

            $proposed = ReviewerIdentity::text($given['proposed_changes'] ?? null);

            $normalized[(string) $item->id] = [
                'decision'         => $decision->value,
                'comment'          => $comment === '' ? null : $comment,
                'proposed_changes' => $proposed === '' ? null : $proposed,
            ];
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $attestation
     *
     * @return array{name: string, email: string, crm_number: string, crm_uf: string, rqe: string, specialty: string, has_conflict: bool, conflict_description: ?string, allow_identity_display: bool, agreed: bool}
     */
    private function normalizeAttestation(array $attestation): array
    {
        $description = ReviewerIdentity::text($attestation['conflict_description'] ?? null);

        return [
            'name'                   => ReviewerIdentity::text($attestation['name'] ?? null),
            'email'                  => ReviewerIdentity::email($attestation['email'] ?? null),
            'crm_number'             => ReviewerIdentity::number($attestation['crm_number'] ?? null),
            'crm_uf'                 => ReviewerIdentity::uf($attestation['crm_uf'] ?? null),
            'rqe'                    => ReviewerIdentity::number($attestation['rqe'] ?? null),
            'specialty'              => ReviewerIdentity::text($attestation['specialty'] ?? null) ?: 'Oftalmologia',
            'has_conflict'           => (bool) ($attestation['has_conflict'] ?? false),
            'conflict_description'   => (bool) ($attestation['has_conflict'] ?? false) && $description !== '' ? $description : null,
            'allow_identity_display' => (bool) ($attestation['allow_identity_display'] ?? false),
            'agreed'                 => (bool) ($attestation['agreed'] ?? false),
        ];
    }

    /**
     * Campos em que o que o Revisor declarou difere do cadastro da EasyEye. Não bloqueia (a
     * conferência de verdade é no CFM), mas fica registrado para quem for aceitar.
     *
     * @param array<string, mixed> $declared
     *
     * @return list<string>
     */
    private function identityMismatch(string $reviewerId, array $declared): array
    {
        $registered = ClinicalReviewer::query()->find($reviewerId);

        if ($registered === null) {
            return ['reviewer'];
        }

        $mismatch = [];

        foreach ([
            'crm_number' => $registered->crm_number,
            'crm_uf'     => $registered->crm_uf,
            'rqe'        => $registered->rqe,
            'email'      => ReviewerIdentity::email($registered->email),
        ] as $field => $value) {
            if ($declared[$field] !== $value) {
                $mismatch[] = $field;
            }
        }

        return $mismatch;
    }

    /**
     * Recibo por e-mail (síncrono, depois do commit). Falha de envio só deixa `receipt_sent_at`
     * vazio — o parecer já está gravado.
     *
     * @param array<int, ClinicalReviewItem>                                                      $items
     * @param array<string, array{decision: string, comment: ?string, proposed_changes: ?string}> $decisions
     */
    private function sendReceipt(ClinicalReviewInvitation $invitation, ClinicalReviewAttestation $attestation, array $items, array $decisions): void
    {
        $titles = RecallProtocol::query()->whereIn('key', array_map(static fn (ClinicalReviewItem $item): string => $item->subject_key, $items))->get()->keyBy('key');

        try {
            Notification::sendNow(
                Notification::route('mail', $invitation->reviewer_email),
                new ClinicalReviewReceiptNotification(
                    reviewerName: $invitation->reviewer_name,
                    submittedAt: $attestation->signed_at,
                    submissionHash: (string) $attestation->submission_hash,
                    decisions: array_map(static fn (ClinicalReviewItem $item): array => [
                        'title'    => $titles->get($item->subject_key)?->title('pt_BR') ?? $item->subject_key,
                        'version'  => $item->subject_version,
                        'decision' => ReviewDecision::from($decisions[(string) $item->id]['decision'])->label(),
                    ], $items),
                ),
            );

            $attestation->forceFill(['receipt_sent_at' => now()])->save();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
