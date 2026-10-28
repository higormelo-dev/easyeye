<?php

declare(strict_types=1);

namespace App\Domains\ClinicalReview\Services;

use App\Domains\ClinicalReview\Models\ClinicalGovernanceEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Grava o log de governança. Exigência de desenho (ADR 0002): a prova é gravada
 * DENTRO da transação da decisão e falha alto — se o log não grava, a decisão não
 * vale. Por isso, ao contrário do AuditLogger (que engole exceção para não derrubar
 * a requisição), aqui qualquer erro sobe e uma chamada fora de transação é recusada.
 */
class GovernanceLog
{
    public const SUBJECT_PROTOCOL_VERSION = 'recall_protocol_version';

    public const SUBJECT_REVIEW_INVITATION = 'clinical_review_invitation';

    public const SUBJECT_REVIEWER = 'clinical_reviewer';

    /**
     * @param array<string, mixed> $meta
     */
    public function record(
        string $subjectType,
        string $subjectId,
        string $event,
        ?string $fromState = null,
        ?string $toState = null,
        ?User $actor = null,
        ?string $reason = null,
        array $meta = [],
        ?string $actorLabel = null,
    ): ClinicalGovernanceEvent {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('O log de governança é gravado dentro da transação da decisão.');
        }

        return ClinicalGovernanceEvent::query()->create([
            'subject_type'  => $subjectType,
            'subject_id'    => $subjectId,
            'event'         => $event,
            'from_state'    => $fromState,
            'to_state'      => $toState,
            'actor_user_id' => $actor?->getKey(),
            'actor_label'   => $actorLabel ?? ($actor !== null ? (string) $actor->name : 'system'),
            'reason'        => $reason,
            'meta'          => $meta === [] ? null : $meta,
        ]);
    }
}
