<?php

declare(strict_types=1);

namespace App\Domains\Recall\Services;

use App\Domains\ClinicalReview\Models\ClinicalGovernanceEvent;
use App\Domains\ClinicalReview\Services\{GovernanceLog, ReviewInvitationService};
use App\Domains\Recall\Enums\ProtocolVersionState as State;
use App\Domains\Recall\Exceptions\{ProtocolGovernanceException, ProtocolVersionException};
use App\Domains\Recall\Models\{RecallProtocol, RecallProtocolVersion};
use App\Domains\Recall\Support\{ProtocolContentSchema, ValidationEvidence};
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A vida de uma versão DEPOIS da revisão (ADR 0002): liberar, suspender, retomar, aposentar,
 * retirar da revisão e a verificação de integridade. É o freio de mão da governança clínica:
 * só o que passou por aqui chega às clínicas, e o que perde a confiança sai de uso na hora.
 *
 * Toda operação roda numa transação que trava o protocolo e TODAS as versões dele (id
 * crescente) — "no máximo uma liberada" não depende de índice para ser respeitada — e grava o
 * evento de governança na mesma transação. Ordem de locks (global): protocolo → versão (id
 * crescente) → convite.
 *
 * Liberar exige a prova completa (invariante I1): aceite `accepted` da conferência no CFM +
 * decisão `approved` do Revisor sobre ESTA versão + o mesmo hash + conteúdo íntegro.
 */
class ProtocolGovernanceService
{
    /** Meses até a versão liberada pedir nova validação (aviso; não bloqueia o uso). */
    public const DEFAULT_REVALIDATION_MONTHS = 24;

    public const MIN_REASON_LENGTH = 20;

    public const MIN_WITHDRAW_REASON_LENGTH = 10;

    public function __construct(
        private readonly GovernanceLog $log,
        private readonly ReviewInvitationService $invitations,
    ) {
    }

    /**
     * `validated → released`. A versão liberada que estava no ar passa a `superseded`: segue
     * válida para quem já a adotou, mas deixa de ser oferecida.
     *
     * @throws ProtocolVersionException
     * @throws ProtocolGovernanceException
     */
    public function release(RecallProtocolVersion $version, ?User $actor = null): RecallProtocolVersion
    {
        return DB::transaction(function () use ($version, $actor): RecallProtocolVersion {
            [$locked, $siblings] = $this->lockProtocol($version);

            // Só a VALIDADA é liberada. `suspended → released` existe na máquina de estados, mas é a retomada
            // (resume): liberar uma suspensa passaria por cima do motivo da suspensão.
            if ($locked->state !== State::Validated) {
                throw ProtocolVersionException::invalidTransition($locked->state, State::Released);
            }

            $this->assertValidationEvidence($locked);

            $previous = $siblings->first(static fn (RecallProtocolVersion $sibling): bool => $sibling->state === State::Released && $sibling->id !== $locked->id);

            if ($previous !== null) {
                $this->move($previous, State::Superseded, [], $actor, 'superseded', null, ['superseded_by' => (string) $locked->id, 'by_version' => $locked->version]);
            }

            $dueAt = now()->addMonths((int) config('recall.revalidation_months', self::DEFAULT_REVALIDATION_MONTHS))->toDateString();

            $this->move($locked, State::Released, [
                'released_at'   => now(),
                'released_by'   => $actor?->getKey(),
                'review_due_at' => $dueAt,
            ], $actor, 'released', null, [
                'version'       => $locked->version,
                'content_hash'  => $locked->content_hash,
                'review_due_at' => $dueAt,
                'superseded'    => $previous !== null ? (string) $previous->id : null,
                'acceptance_id' => (string) $locked->validated_acceptance_id,
            ]);

            return $locked;
        });
    }

    /**
     * `released | superseded → suspended`: a versão sai de uso na hora, para todas as clínicas.
     *
     * @throws ProtocolVersionException
     * @throws ProtocolGovernanceException
     */
    public function suspend(RecallProtocolVersion $version, string $reason, ?User $actor = null): RecallProtocolVersion
    {
        $reason = $this->reason($reason, self::MIN_REASON_LENGTH);

        return DB::transaction(function () use ($version, $reason, $actor): RecallProtocolVersion {
            [$locked] = $this->lockProtocol($version);

            $this->assertTransition($locked, State::Suspended);

            $from = $locked->state;

            $this->move($locked, State::Suspended, [
                'suspended_at'         => now(),
                'suspended_by'         => $actor?->getKey(),
                'suspended_reason'     => $reason,
                'suspended_from_state' => $from->value,
            ], $actor, 'suspended', $reason, ['version' => $locked->version]);

            return $locked;
        });
    }

    /**
     * `suspended → released | superseded` (o que era antes). Refaz a verificação de integridade:
     * uma versão suspensa por hash divergente não volta enquanto o conteúdo não bater. Se, na
     * suspensão, uma versão mais nova foi liberada, esta volta como `superseded` — nunca dois
     * `released`.
     *
     * @throws ProtocolVersionException
     * @throws ProtocolGovernanceException
     */
    public function resume(RecallProtocolVersion $version, ?User $actor = null): RecallProtocolVersion
    {
        return DB::transaction(function () use ($version, $actor): RecallProtocolVersion {
            [$locked, $siblings] = $this->lockProtocol($version);

            if ($locked->state !== State::Suspended) {
                throw ProtocolVersionException::invalidTransition($locked->state, State::Released);
            }

            if (! $locked->integrityOk()) {
                throw ProtocolGovernanceException::integrityFailed((string) $locked->id);
            }

            $target  = State::tryFrom((string) $locked->suspended_from_state) ?? State::Superseded;
            $demoted = false;

            if ($target === State::Released && $siblings->contains(static fn (RecallProtocolVersion $sibling): bool => $sibling->state === State::Released && $sibling->id !== $locked->id)) {
                $target  = State::Superseded;
                $demoted = true;
            }

            $this->assertTransition($locked, $target);

            $this->move($locked, $target, [
                'suspended_at'         => null,
                'suspended_by'         => null,
                'suspended_reason'     => null,
                'suspended_from_state' => null,
            ], $actor, 'resumed', null, ['version' => $locked->version, 'restored_to' => $target->value, 'demoted_by_newer_release' => $demoted]);

            return $locked;
        });
    }

    /**
     * Qualquer estado (menos rascunho) `→ retired`: terminal. Convites pendentes que citam a
     * versão em revisão são revogados (não há mais o que revisar).
     *
     * @throws ProtocolVersionException
     * @throws ProtocolGovernanceException
     */
    public function retire(RecallProtocolVersion $version, string $reason, ?User $actor = null): RecallProtocolVersion
    {
        $reason = $this->reason($reason, self::MIN_REASON_LENGTH);

        return DB::transaction(function () use ($version, $reason, $actor): RecallProtocolVersion {
            [$locked] = $this->lockProtocol($version);

            $this->assertTransition($locked, State::Retired);

            $revoked = $locked->state === State::InReview ? $this->invitations->revokePendingForVersion((string) $locked->id, "Versão aposentada: {$reason}", $actor) : 0;

            $this->move($locked, State::Retired, [
                'retired_at'     => now(),
                'retired_by'     => $actor?->getKey(),
                'retired_reason' => $reason,
            ], $actor, 'retired', $reason, ['version' => $locked->version, 'invitations_revoked' => $revoked]);

            return $locked;
        });
    }

    /**
     * `in_review | validated → withdrawn`: a EasyEye desiste desta versão antes de liberá-la
     * (achou erro, vai mudar). Convites pendentes dela são revogados; o parecer já enviado
     * continua no histórico, mas não valida mais esta versão.
     *
     * @throws ProtocolVersionException
     * @throws ProtocolGovernanceException
     */
    public function withdraw(RecallProtocolVersion $version, string $reason, ?User $actor = null): RecallProtocolVersion
    {
        $reason = $this->reason($reason, self::MIN_WITHDRAW_REASON_LENGTH);

        return DB::transaction(function () use ($version, $reason, $actor): RecallProtocolVersion {
            [$locked] = $this->lockProtocol($version);

            $this->assertTransition($locked, State::Withdrawn);

            $revoked = $this->invitations->revokePendingForVersion((string) $locked->id, "Versão retirada da revisão: {$reason}", $actor);

            $this->move($locked, State::Withdrawn, [], $actor, 'withdrawn', $reason, ['version' => $locked->version, 'invitations_revoked' => $revoked]);

            return $locked;
        });
    }

    /**
     * Confere o hash gravado de toda versão que ainda importa (em revisão, validada, liberada,
     * superada, suspensa) contra o conteúdo gravado. Quem divergir sai de uso: liberada/superada
     * é SUSPENSA (reversível depois da investigação), em revisão/validada é RETIRADA. O trigger
     * do banco já recusa a adulteração; isto pega quem a contornou (dono da tabela, restore).
     *
     * @return array{checked: int, failed: list<array{version_id: string, protocol: string, version: int, state: string, action: string}>}
     */
    public function verifyIntegrity(): array
    {
        $report = ['checked' => 0, 'failed' => []];

        RecallProtocolVersion::query()
            ->whereIn('state', array_map(static fn (State $state): string => $state->value, [State::InReview, State::Validated, State::Released, State::Superseded, State::Suspended]))
            ->orderBy('id')
            ->chunkById(100, function (Collection $versions) use (&$report): void {
                foreach ($versions as $version) {
                    $report['checked']++;

                    if ($version->integrityOk()) {
                        continue;
                    }

                    $outcome = $this->quarantine($version);

                    if ($outcome !== null) {
                        $report['failed'][] = $outcome;
                    }
                }
            }, 'id');

        return $report;
    }

    // ── apoio ───────────────────────────────────────────────────────────────

    /**
     * Trava o protocolo e TODAS as versões dele (id crescente) e devolve a versão pedida relida
     * com as irmãs.
     *
     * @return array{0: RecallProtocolVersion, 1: Collection<string, RecallProtocolVersion>}
     */
    private function lockProtocol(RecallProtocolVersion $version): array
    {
        RecallProtocol::query()->whereKey($version->protocol_id)->lockForUpdate()->firstOrFail();

        $siblings = RecallProtocolVersion::query()
            ->where('protocol_id', $version->protocol_id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy(static fn (RecallProtocolVersion $sibling): string => (string) $sibling->id);

        /** @var RecallProtocolVersion $locked */
        $locked = $siblings->get((string) $version->getKey()) ?? RecallProtocolVersion::query()->findOrFail($version->getKey());

        return [$locked, $siblings];
    }

    /** @throws ProtocolVersionException */
    private function assertTransition(RecallProtocolVersion $version, State $to): void
    {
        if (! $version->state->canTransitionTo($to)) {
            throw ProtocolVersionException::invalidTransition($version->state, $to);
        }
    }

    /**
     * A prova completa para liberar (I1): a MESMA que a clínica reconfere ao adotar (ValidationEvidence).
     *
     * @throws ProtocolGovernanceException
     */
    private function assertValidationEvidence(RecallProtocolVersion $version): void
    {
        ValidationEvidence::assert($version);
    }

    /**
     * Muda o estado, grava os marcos e o evento de governança (mesma transação).
     *
     * @param array<string, mixed> $marks
     * @param array<string, mixed> $meta
     */
    private function move(RecallProtocolVersion $version, State $to, array $marks, ?User $actor, string $event, ?string $reason, array $meta): void
    {
        $from = $version->state;

        $version->forceFill($marks + [
            'state'        => $to->value,
            'lock_version' => $version->lock_version + 1,
            'updated_by'   => $actor?->getKey(),
        ])->save();

        $this->log->record(GovernanceLog::SUBJECT_PROTOCOL_VERSION, (string) $version->id, $event, $from->value, $to->value, $actor, $reason, $meta);

        // A versão saiu de uso (suspensa, aposentada) ou voltou: os retornos calculados por ela precisam refletir isso
        // já (`rule_suspended` é persistido e as ações da equipe e o agendamento o leem), não na próxima ação do
        // paciente. Mesma transação: a marca nasce e morre com a mudança de estado.
        if ($from->isInUse() !== $to->isInUse()) {
            RecallOutbox::markVersion((string) $version->id, 'protocol_governance');
        }
    }

    /** @throws ProtocolGovernanceException */
    private function reason(string $reason, int $minimum): string
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < $minimum) {
            throw ProtocolGovernanceException::reasonTooShort($minimum);
        }

        return $reason;
    }

    /**
     * Tira de uso a versão cujo hash não confere. Relê sob trava (outra sessão pode ter mexido).
     *
     * @return array{version_id: string, protocol: string, version: int, state: string, action: string}|null
     */
    private function quarantine(RecallProtocolVersion $stale): ?array
    {
        return DB::transaction(function () use ($stale): ?array {
            [$locked] = $this->lockProtocol($stale);

            if ($locked->integrityOk() || ! in_array($locked->state, [State::InReview, State::Validated, State::Released, State::Superseded, State::Suspended], true)) {
                return null;
            }

            $protocol = RecallProtocol::query()->findOrFail($locked->protocol_id);
            $meta     = [
                'version'     => $locked->version,
                'stored_hash' => $locked->content_hash,
                'actual_hash' => CanonicalJson::hash(ProtocolContentSchema::HASH_DOMAIN, (string) $locked->content),
            ];
            $reason = 'Verificação de integridade: o hash gravado não confere com o conteúdo gravado.';
            $from   = $locked->state;

            if ($from === State::Suspended) {
                // Já fora de uso: não muda nada, e só avisa uma vez por suspensão (a verificação roda toda noite).
                $alreadyFlagged = ClinicalGovernanceEvent::query()
                    ->where('subject_id', $locked->id)
                    ->where('event', 'integrity_failed')
                    ->when($locked->suspended_at, fn ($query) => $query->where('created_at', '>=', $locked->suspended_at))
                    ->exists();

                if (! $alreadyFlagged) {
                    $this->log->record(GovernanceLog::SUBJECT_PROTOCOL_VERSION, (string) $locked->id, 'integrity_failed', $from->value, $from->value, null, $reason, $meta + ['action' => 'none']);
                }

                return $alreadyFlagged ? null : $this->outcome($locked, $protocol, 'already_suspended');
            }

            if ($from === State::Released || $from === State::Superseded) {
                $this->move($locked, State::Suspended, [
                    'suspended_at'         => now(),
                    'suspended_by'         => null,
                    'suspended_reason'     => $reason,
                    'suspended_from_state' => $from->value,
                ], null, 'integrity_failed', $reason, $meta + ['action' => 'suspended']);

                return $this->outcome($locked, $protocol, 'suspended');
            }

            $revoked = $this->invitations->revokePendingForVersion((string) $locked->id, $reason, null);

            $this->move($locked, State::Withdrawn, [], null, 'integrity_failed', $reason, $meta + ['action' => 'withdrawn', 'invitations_revoked' => $revoked]);

            return $this->outcome($locked, $protocol, 'withdrawn');
        });
    }

    /** @return array{version_id: string, protocol: string, version: int, state: string, action: string} */
    private function outcome(RecallProtocolVersion $version, RecallProtocol $protocol, string $action): array
    {
        return [
            'version_id' => (string) $version->id,
            'protocol'   => $protocol->key,
            'version'    => $version->version,
            'state'      => $version->state->value,
            'action'     => $action,
        ];
    }
}
