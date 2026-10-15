<?php

declare(strict_types=1);

namespace App\Domains\ClinicalReview\Services;

use App\Domains\ClinicalReview\Enums\ReviewInvitationStatus;
use App\Domains\ClinicalReview\Exceptions\ReviewInvitationException;
use App\Domains\ClinicalReview\Models\{ClinicalReviewInvitation, ClinicalReviewItem, ClinicalReviewer};
use App\Domains\ClinicalReview\Notifications\ClinicalReviewInvitationNotification;
use App\Domains\ClinicalReview\Support\{ReviewHashes, ReviewToken};
use App\Domains\Recall\Enums\ProtocolVersionState;
use App\Domains\Recall\Models\{RecallProtocol, RecallProtocolVersion};
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\{DB, Hash, Notification, URL};
use Throwable;

/**
 * Convites de revisão clínica (ADR 0002): cria, envia por e-mail, reenvia (rotaciona o
 * token), revoga e expira. Tudo que decide estado roda numa transação com a linha travada
 * e grava o evento de governança NA MESMA transação.
 *
 * Ordem de locks (global): protocolo → versão (id crescente) → revisor → convite. O token
 * só existe em memória (devolvido a quem chamou para montar o link); nunca é gravado.
 */
class ReviewInvitationService
{
    public const DEFAULT_EXPIRY_DAYS = 14;

    public const MAX_EXPIRY_DAYS = 30;

    public const ACCESS_CODE_PATTERN = '/^[A-Z0-9]{6,12}$/';

    private const MIN_REASON_LENGTH = 10;

    public function __construct(private readonly GovernanceLog $log)
    {
    }

    /**
     * Cria o convite (sem enviar). O e-mail sai em `send()` DEPOIS do commit: um envio que
     * falha não desfaz o convite, e `sent_at` só existe depois do envio de verdade.
     *
     * @param list<string> $versionIds versões `in_review` a revisar
     *
     * @return array{invitation: ClinicalReviewInvitation, token: string, accessCode: ?string}
     *
     * @throws ReviewInvitationException
     */
    public function create(ClinicalReviewer $reviewer, array $versionIds, ?string $accessCode, int $expiresInDays, ?User $actor = null): array
    {
        $versionIds = array_values(array_unique($versionIds));
        sort($versionIds, SORT_STRING);

        if ($versionIds === []) {
            throw ReviewInvitationException::noVersions();
        }

        $accessCode = $this->normalizeAccessCode($accessCode);
        $expiresIn  = max(1, min(self::MAX_EXPIRY_DAYS, $expiresInDays));
        $generated  = ReviewToken::generate();

        $invitation = DB::transaction(function () use ($reviewer, $versionIds, $accessCode, $expiresIn, $generated, $actor): ClinicalReviewInvitation {
            $versions = RecallProtocolVersion::query()
                ->with('protocol:id,key')
                ->whereIn('id', $versionIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($versions->count() !== count($versionIds)) {
                throw ReviewInvitationException::versionNotFound();
            }

            foreach ($versions as $version) {
                if ($version->state !== ProtocolVersionState::InReview) {
                    throw ReviewInvitationException::versionNotInReview((string) $version->id, $version->state->value);
                }
            }

            $lockedReviewer = ClinicalReviewer::query()->whereKey($reviewer->id)->lockForUpdate()->firstOrFail();

            if (! $lockedReviewer->active) {
                throw ReviewInvitationException::reviewerInactive();
            }

            $items = $versions->map(static fn (RecallProtocolVersion $version): array => [
                'subject_type'    => ClinicalReviewItem::SUBJECT_PROTOCOL_VERSION,
                'subject_id'      => (string) $version->id,
                'subject_key'     => (string) $version->protocol->key,
                'subject_version' => (int) $version->version,
                'content_hash'    => (string) $version->content_hash,
            ])->all();

            $setHash = ReviewHashes::setHash($items);

            try {
                $invitation = new ClinicalReviewInvitation([
                    'reviewer_id'      => $lockedReviewer->id,
                    'reviewer_name'    => $lockedReviewer->name,
                    'reviewer_email'   => $lockedReviewer->email,
                    'token_hash'       => $generated['hash'],
                    'set_hash'         => $setHash,
                    'status'           => ReviewInvitationStatus::Pending->value,
                    'expires_at'       => now()->addDays($expiresIn),
                    'access_code_hash' => $accessCode !== null ? Hash::make($accessCode) : null,
                ]);
                $invitation->forceFill(['created_by' => $actor?->getKey(), 'updated_by' => $actor?->getKey()])->save();
            } catch (QueryException $e) {
                // Índice único parcial (reviewer_id, set_hash) WHERE status = 'pending'.
                if (str_contains($e->getMessage(), 'clinical_review_invitations_one_pending')) {
                    throw ReviewInvitationException::duplicatePending();
                }

                throw $e;
            }

            foreach ($items as $item) {
                ClinicalReviewItem::query()->create($item + ['invitation_id' => $invitation->id]);
            }

            $this->log->record(
                GovernanceLog::SUBJECT_REVIEW_INVITATION,
                (string) $invitation->id,
                'created',
                null,
                ReviewInvitationStatus::Pending->value,
                $actor,
                null,
                [
                    'reviewer'     => $lockedReviewer->crmLabel(),
                    'set_hash'     => $setHash,
                    'versions'     => array_map(static fn (array $item): string => $item['subject_key'] . ' v' . $item['subject_version'], $items),
                    'expires_at'   => $invitation->expires_at->toIso8601String(),
                    'pin_required' => $accessCode !== null,
                ],
            );

            foreach ($versions as $version) {
                $this->log->record(
                    GovernanceLog::SUBJECT_PROTOCOL_VERSION,
                    (string) $version->id,
                    'invited',
                    ProtocolVersionState::InReview->value,
                    ProtocolVersionState::InReview->value,
                    $actor,
                    null,
                    ['invitation_id' => (string) $invitation->id, 'reviewer' => $lockedReviewer->crmLabel()],
                );
            }

            return $invitation;
        });

        return ['invitation' => $invitation, 'token' => $generated['token'], 'accessCode' => $accessCode];
    }

    /**
     * Envia o e-mail do convite (síncrono — NUNCA fila) e só então grava `sent_at`. Falha de
     * transporte não derruba nada: fica em `last_send_error` e o Manager reenvia.
     */
    public function send(ClinicalReviewInvitation $invitation, string $token, ?User $actor = null): bool
    {
        $invitation->loadMissing('items');

        $titles = RecallProtocol::query()->whereIn('key', $invitation->items->pluck('subject_key'))->get()->keyBy('key');

        try {
            Notification::sendNow(
                Notification::route('mail', $invitation->reviewer_email),
                new ClinicalReviewInvitationNotification(
                    reviewerName: $invitation->reviewer_name,
                    url: $this->url($invitation, $token),
                    expiresAt: $invitation->expires_at,
                    versions: $invitation->items->map(static fn (ClinicalReviewItem $item): array => ['title' => $titles->get($item->subject_key)?->title('pt_BR') ?? $item->subject_key, 'version' => $item->subject_version])->all(),
                    pinRequired: $invitation->requiresAccessCode(),
                ),
            );
        } catch (Throwable $e) {
            report($e);

            DB::transaction(function () use ($invitation, $e, $actor): void {
                $invitation->forceFill(['last_send_error' => mb_substr($e::class . ': ' . $e->getMessage(), 0, 500)])->save();

                $this->log->record(GovernanceLog::SUBJECT_REVIEW_INVITATION, (string) $invitation->id, 'send_failed', null, null, $actor, null, ['error' => $e::class]);
            });

            return false;
        }

        DB::transaction(function () use ($invitation, $actor): void {
            $invitation->forceFill(['sent_at' => now(), 'last_send_error' => null])->save();

            $this->log->record(GovernanceLog::SUBJECT_REVIEW_INVITATION, (string) $invitation->id, 'sent', null, null, $actor);
        });

        return true;
    }

    /**
     * Reenvio: GERA UM TOKEN NOVO (o link antigo para de funcionar na hora), zera as
     * tentativas de PIN e manda o e-mail de novo. Só convite pendente e dentro do prazo.
     *
     * @return array{invitation: ClinicalReviewInvitation, token: string}
     *
     * @throws ReviewInvitationException
     */
    public function resend(ClinicalReviewInvitation $invitation, ?User $actor = null): array
    {
        $generated = ReviewToken::generate();

        $locked = DB::transaction(function () use ($invitation, $generated, $actor): ClinicalReviewInvitation {
            $locked = $this->lock($invitation);

            $this->assertOpen($locked);

            $locked->forceFill([
                'token_hash'          => $generated['hash'],
                'sent_at'             => null,
                'last_send_error'     => null,
                'access_attempts'     => 0,
                'access_locked_until' => null,
            ])->save();

            $this->log->record(GovernanceLog::SUBJECT_REVIEW_INVITATION, (string) $locked->id, 'resent', ReviewInvitationStatus::Pending->value, ReviewInvitationStatus::Pending->value, $actor, null, ['token_rotated' => true]);

            return $locked;
        });

        return ['invitation' => $locked, 'token' => $generated['token']];
    }

    /**
     * @throws ReviewInvitationException
     */
    public function revoke(ClinicalReviewInvitation $invitation, string $reason, ?User $actor = null): ClinicalReviewInvitation
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < self::MIN_REASON_LENGTH) {
            throw ReviewInvitationException::reasonTooShort();
        }

        return DB::transaction(function () use ($invitation, $reason, $actor): ClinicalReviewInvitation {
            $locked = $this->lock($invitation);

            if ($locked->status !== ReviewInvitationStatus::Pending) {
                throw ReviewInvitationException::notPending($locked->status->value);
            }

            $this->close($locked, ReviewInvitationStatus::Revoked, 'revoked', $actor, $reason, [
                'revoked_at'     => now(),
                'revoked_by'     => $actor?->getKey(),
                'revoked_reason' => $reason,
            ]);

            return $locked;
        });
    }

    /**
     * Revoga os convites pendentes de um Revisor (ao desativá-lo). Devolve quantos.
     */
    public function revokePendingFor(ClinicalReviewer $reviewer, string $reason, ?User $actor = null): int
    {
        $count = 0;

        ClinicalReviewInvitation::query()
            ->where('reviewer_id', $reviewer->id)
            ->where('status', ReviewInvitationStatus::Pending->value)
            ->eachById(function (ClinicalReviewInvitation $invitation) use ($reason, $actor, &$count): void {
                DB::transaction(function () use ($invitation, $reason, $actor, &$count): void {
                    $locked = $this->lock($invitation);

                    if ($locked->status !== ReviewInvitationStatus::Pending) {
                        return;
                    }

                    $this->close($locked, ReviewInvitationStatus::Revoked, 'revoked', $actor, $reason, [
                        'revoked_at'     => now(),
                        'revoked_by'     => $actor?->getKey(),
                        'revoked_reason' => $reason,
                    ]);

                    $count++;
                });
            });

        return $count;
    }

    /**
     * Revoga os convites PENDENTES que citam uma versão que acabou de sair de revisão (retirada,
     * aposentada, hash divergente): não há mais o que revisar. Convite já respondido não é tocado —
     * o parecer fica no histórico e o aceite ignora a versão que saiu. Chamado DENTRO da transação
     * da governança, que já travou a versão (ordem global: versão → convite). Devolve quantos.
     */
    public function revokePendingForVersion(string $versionId, string $reason, ?User $actor = null): int
    {
        $count = 0;

        $ids = ClinicalReviewInvitation::query()
            ->where('status', ReviewInvitationStatus::Pending->value)
            ->whereIn('id', ClinicalReviewItem::query()->where('subject_id', $versionId)->select('invitation_id'))
            ->orderBy('id')
            ->pluck('id');

        foreach ($ids as $id) {
            DB::transaction(function () use ($id, $reason, $actor, &$count): void {
                $locked = ClinicalReviewInvitation::query()->whereKey($id)->lockForUpdate()->first();

                if ($locked === null || $locked->status !== ReviewInvitationStatus::Pending) {
                    return;
                }

                $this->close($locked, ReviewInvitationStatus::Revoked, 'revoked', $actor, $reason, [
                    'revoked_at'     => now(),
                    'revoked_by'     => $actor?->getKey(),
                    'revoked_reason' => $reason,
                ]);

                $count++;
            });
        }

        return $count;
    }

    /**
     * Convites pendentes cujo prazo passou viram `expired` e perdem o rascunho. Idempotente.
     */
    public function expireStale(?CarbonInterface $now = null): int
    {
        $now ??= now();
        $count = 0;

        ClinicalReviewInvitation::query()
            ->where('status', ReviewInvitationStatus::Pending->value)
            ->where('expires_at', '<', $now)
            ->eachById(function (ClinicalReviewInvitation $invitation) use ($now, &$count): void {
                DB::transaction(function () use ($invitation, $now, &$count): void {
                    $locked = $this->lock($invitation);

                    if ($locked->status !== ReviewInvitationStatus::Pending || ! $locked->expires_at->lt($now)) {
                        return;
                    }

                    $this->close($locked, ReviewInvitationStatus::Expired, 'expired', null, null);

                    $count++;
                });
            });

        return $count;
    }

    /** Link assinado (APP_KEY) do convite: leva o token e vale até o fim do prazo. */
    public function url(ClinicalReviewInvitation $invitation, string $token): string
    {
        return URL::temporarySignedRoute(
            'clinical-review.open',
            $invitation->expires_at,
            ['invitation' => $invitation->id, 'token' => $token],
        );
    }

    // ── apoio ───────────────────────────────────────────────────────────────

    /** Relê o convite sob FOR UPDATE (dentro da transação do chamador). */
    private function lock(ClinicalReviewInvitation $invitation): ClinicalReviewInvitation
    {
        return ClinicalReviewInvitation::query()->whereKey($invitation->getKey())->lockForUpdate()->firstOrFail();
    }

    /** @throws ReviewInvitationException */
    private function assertOpen(ClinicalReviewInvitation $invitation): void
    {
        if ($invitation->status !== ReviewInvitationStatus::Pending) {
            throw ReviewInvitationException::notPending($invitation->status->value);
        }

        if ($invitation->isExpired()) {
            throw ReviewInvitationException::expired();
        }
    }

    /**
     * Fecha o convite (estado final), apaga o rascunho e grava o evento.
     *
     * @param array<string, mixed> $extra
     */
    private function close(ClinicalReviewInvitation $invitation, ReviewInvitationStatus $to, string $event, ?User $actor, ?string $reason, array $extra = []): void
    {
        $from = $invitation->status;

        $invitation->forceFill($extra + [
            'status'         => $to->value,
            'draft_payload'  => null,
            'draft_saved_at' => null,
        ])->save();

        $this->log->record(GovernanceLog::SUBJECT_REVIEW_INVITATION, (string) $invitation->id, $event, $from->value, $to->value, $actor, $reason);
    }

    /** @throws ReviewInvitationException */
    private function normalizeAccessCode(?string $code): ?string
    {
        if ($code === null || trim($code) === '') {
            return null;
        }

        $normalized = ReviewToken::normalizeAccessCode($code);

        if (preg_match(self::ACCESS_CODE_PATTERN, $normalized) !== 1) {
            throw ReviewInvitationException::invalidAccessCode();
        }

        return $normalized;
    }
}
