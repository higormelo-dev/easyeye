<?php

declare(strict_types=1);

namespace App\Domains\ClinicalReview\Services;

use App\Domains\ClinicalReview\Enums\{AcceptanceDecision, ReviewDecision, ReviewInvitationStatus};
use App\Domains\ClinicalReview\Exceptions\ReviewAcceptanceException;
use App\Domains\ClinicalReview\Models\{ClinicalReviewAcceptance, ClinicalReviewAttestation, ClinicalReviewInvitation, ClinicalReviewItem, ClinicalReviewer};
use App\Domains\ClinicalReview\Support\ReviewerIdentity;
use App\Domains\Recall\Enums\ProtocolVersionState;
use App\Domains\Recall\Models\RecallProtocolVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * O aceite da EasyEye (ADR 0002, item 4): alguém conferiu — ou não — o CRM/UF e o RQE que o
 * Revisor DECLAROU no atestado, no portal do CFM. É a segunda chave da identidade: o link
 * sozinho nunca basta para validar uma regra clínica.
 *
 *  - aceito  → o convite vira `accepted`; cada versão que o Revisor APROVOU (e que segue em
 *    revisão, com o mesmo hash que ele viu) vira `validated`, apontando este aceite; as que
 *    ele pediu ajuste ou não aprovou viram `declined` (a EasyEye prepara a próxima versão);
 *  - recusado → o convite vira `refused`; as versões continuam em revisão (a identidade não
 *    foi confirmada: nada vale), prontas para um novo convite.
 *
 * Ordem de locks (global): versões (id crescente) → convite. O aceite e as decisões são
 * gravados na MESMA transação que os eventos de governança, e nada aqui se reescreve depois
 * (o aceite é append-only, a decisão do item é imutável).
 */
class ReviewAcceptanceService
{
    public function __construct(private readonly GovernanceLog $log)
    {
    }

    /**
     * @throws ReviewAcceptanceException
     */
    public function record(
        ClinicalReviewInvitation $invitation,
        AcceptanceDecision $decision,
        bool $crmChecked,
        bool $rqeChecked,
        ?string $note,
        ?User $actor = null,
    ): ClinicalReviewAcceptance {
        $note = ReviewerIdentity::text($note);
        $note = $note === '' ? null : $note;

        return DB::transaction(function () use ($invitation, $decision, $crmChecked, $rqeChecked, $note, $actor): ClinicalReviewAcceptance {
            $versionIds = ClinicalReviewItem::query()->where('invitation_id', $invitation->id)->pluck('subject_id')->sort()->values()->all();

            $versions = RecallProtocolVersion::query()
                ->with('protocol:id,key')
                ->whereIn('id', $versionIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $locked = ClinicalReviewInvitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== ReviewInvitationStatus::Submitted) {
                throw ReviewAcceptanceException::notSubmitted($locked->status->value);
            }

            $attestation = ClinicalReviewAttestation::query()->where('invitation_id', $locked->id)->first()
                ?? throw ReviewAcceptanceException::noAttestation();

            $mismatch = $this->identityMismatch($locked->reviewer_id, $attestation);

            $this->assertAcceptable($decision, $crmChecked, $rqeChecked, $note, $mismatch);

            $acceptance = ClinicalReviewAcceptance::query()->create([
                'invitation_id'       => $locked->id,
                'decision'            => $decision->value,
                'accepted_by'         => $actor?->getKey(),
                'accepted_by_label'   => $actor !== null ? (string) $actor->name : 'system',
                'verification_method' => ClinicalReviewAcceptance::METHOD_CFM_PORTAL,
                'crm_checked'         => $crmChecked,
                'rqe_checked'         => $rqeChecked,
                'checked_at'          => now(),
                'note'                => $note,
            ]);

            $to = $decision === AcceptanceDecision::Accepted ? ReviewInvitationStatus::Accepted : ReviewInvitationStatus::Refused;

            $locked->forceFill(['status' => $to->value, 'updated_by' => $actor?->getKey()])->save();

            $applied = $decision === AcceptanceDecision::Accepted ? $this->applyDecisions($locked, $versions->all(), $acceptance, $actor) : [];

            $this->log->record(
                GovernanceLog::SUBJECT_REVIEW_INVITATION,
                (string) $locked->id,
                $decision === AcceptanceDecision::Accepted ? 'accepted' : 'refused',
                ReviewInvitationStatus::Submitted->value,
                $to->value,
                $actor,
                $note,
                [
                    'acceptance_id'             => (string) $acceptance->id,
                    'identity_matches_registry' => $mismatch === [],
                    'identity_mismatch_fields'  => $mismatch,
                    'versions'                  => $applied,
                ],
            );

            return $acceptance;
        });
    }

    /**
     * Campos em que o que o Revisor declarou difere do cadastro (vazio = confere). O Manager vê
     * isto antes de aceitar: o que se confere no CFM é o DECLARADO, e o cadastro pode estar errado.
     *
     * @return list<string>
     */
    public function identityMismatch(string $reviewerId, ClinicalReviewAttestation $attestation): array
    {
        return $this->mismatchWith(ClinicalReviewer::query()->find($reviewerId), $attestation);
    }

    /**
     * Igual a identityMismatch(), com o cadastro já em mãos (a tela do Manager lista vários convites).
     *
     * @return list<string>
     */
    public function mismatchWith(?ClinicalReviewer $registered, ClinicalReviewAttestation $attestation): array
    {
        if ($registered === null) {
            return ['reviewer'];
        }

        $mismatch = [];

        foreach ([
            'crm_number' => [$registered->crm_number, $attestation->declared_crm_number],
            'crm_uf'     => [$registered->crm_uf, $attestation->declared_crm_uf],
            'rqe'        => [$registered->rqe, $attestation->declared_rqe],
            'email'      => [ReviewerIdentity::email($registered->email), ReviewerIdentity::email($attestation->declared_email)],
        ] as $field => [$expected, $declared]) {
            if ((string) $declared !== (string) $expected) {
                $mismatch[] = $field;
            }
        }

        return $mismatch;
    }

    /**
     * @param list<string> $mismatch
     *
     * @throws ReviewAcceptanceException
     */
    private function assertAcceptable(AcceptanceDecision $decision, bool $crmChecked, bool $rqeChecked, ?string $note, array $mismatch): void
    {
        if ($decision === AcceptanceDecision::Accepted && (! $crmChecked || ! $rqeChecked)) {
            throw ReviewAcceptanceException::checksRequired();
        }

        // Recusar e aceitar com divergência entre o declarado e o cadastro deixam a razão por escrito.
        $needsNote = $decision === AcceptanceDecision::Refused || $mismatch !== [];

        if ($needsNote && ($note === null || mb_strlen($note) < 10)) {
            throw ReviewAcceptanceException::noteRequired();
        }
    }

    /**
     * Passa cada versão do convite ao estado que a decisão do Revisor pede.
     *
     * @param array<string, RecallProtocolVersion> $versions
     *
     * @return list<array<string, mixed>> o que foi feito com cada versão (vai para o evento do convite)
     */
    private function applyDecisions(ClinicalReviewInvitation $invitation, array $versions, ClinicalReviewAcceptance $acceptance, ?User $actor): array
    {
        $applied = [];

        foreach ($invitation->items()->get() as $item) {
            $version = $versions[(string) $item->subject_id] ?? null;

            // A versão saiu de revisão depois do envio (retirada, aposentada...) ou o hash mudou: o parecer
            // não vale mais para ela. Fica registrado, mas nada é validado nem recusado em seu nome.
            if ($version === null || $version->state !== ProtocolVersionState::InReview || $version->content_hash !== $item->content_hash) {
                $applied[] = ['version_id' => (string) $item->subject_id, 'result' => 'skipped', 'state' => $version?->state->value];

                continue;
            }

            $approved = $item->decision === ReviewDecision::Approved;
            $target   = $approved ? ProtocolVersionState::Validated : ProtocolVersionState::Declined;

            $version->forceFill(array_filter([
                'state'                   => $target->value,
                'validated_at'            => $approved ? now() : null,
                'validated_acceptance_id' => $approved ? $acceptance->id : null,
                'lock_version'            => $version->lock_version + 1,
                'updated_by'              => $actor?->getKey(),
            ], static fn ($value): bool => $value !== null))->save();

            $this->log->record(
                GovernanceLog::SUBJECT_PROTOCOL_VERSION,
                (string) $version->id,
                $approved ? 'validated' : 'declined',
                ProtocolVersionState::InReview->value,
                $target->value,
                $actor,
                null,
                [
                    'version'       => $version->version,
                    'decision'      => $item->decision?->value,
                    'invitation_id' => (string) $invitation->id,
                    'acceptance_id' => (string) $acceptance->id,
                    'content_hash'  => $version->content_hash,
                ],
            );

            $applied[] = ['version_id' => (string) $version->id, 'result' => $target->value];
        }

        return $applied;
    }
}
