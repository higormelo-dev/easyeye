<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Actions;

use App\Domains\Tiss\Enums\{TissAppealStatus, TissGlosaStatus};
use App\Domains\Tiss\Models\TissGlosaAppeal;
use App\Domains\Tiss\Services\LogTissStatusTransitionService;
use Illuminate\Support\Facades\DB;

class ResolveGlosaAppealAction
{
    public function __construct(
        private readonly LogTissStatusTransitionService $logStatusTransitionService,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function __invoke(TissGlosaAppeal $appeal, TissAppealStatus $decision, array $payload = []): TissGlosaAppeal
    {
        return DB::transaction(function () use ($appeal, $decision, $payload): TissGlosaAppeal {
            $previousAppealStatus = $appeal->status;
            $acceptedAmount       = (float) ($payload['accepted_amount'] ?? 0);
            $requestedAmount      = (float) $appeal->requested_amount;

            $glosaStatus = match (true) {
                $decision === TissAppealStatus::Rejected => TissGlosaStatus::Maintained,
                $acceptedAmount <= 0                     => TissGlosaStatus::Maintained,
                $acceptedAmount >= $requestedAmount      => TissGlosaStatus::Reversed,
                default                                  => TissGlosaStatus::PartialReversed,
            };

            $appeal->update([
                'status'          => $decision->value,
                'accepted_amount' => $acceptedAmount,
                'resolved_at'     => now(),
                'result_notes'    => $payload['result_notes'] ?? null,
            ]);

            $glosa               = $appeal->glosa;
            $previousGlosaStatus = $glosa->status;

            $glosa->update([
                'status'           => $glosaStatus->value,
                'resolved_at'      => now()->toDateString(),
                'resolution_notes' => $payload['result_notes'] ?? $glosa->resolution_notes,
            ]);

            $this->logStatusTransitionService->record(
                entityId: (string) $appeal->entity_id,
                contextType: 'glosa_appeal',
                contextId: (string) $appeal->id,
                currentStatus: $decision->value,
                previousStatus: $previousAppealStatus->value,
                reason: sprintf('Recurso %s resolvido: %s.', $appeal->appeal_number, $decision->label()),
                payload: ['accepted_amount' => $acceptedAmount, 'glosa_status' => $glosaStatus->value],
            );

            $this->logStatusTransitionService->record(
                entityId: (string) $glosa->entity_id,
                contextType: 'glosa',
                contextId: (string) $glosa->id,
                currentStatus: $glosaStatus->value,
                previousStatus: $previousGlosaStatus->value,
                reason: sprintf('Glosa %s após decisão do recurso %s.', $glosaStatus->label(), $appeal->appeal_number),
            );

            return $appeal->fresh();
        });
    }
}
