<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Actions;

use App\Domains\Tiss\Enums\TissAppealStatus;
use App\Domains\Tiss\Models\TissGlosaAppeal;
use App\Domains\Tiss\Services\LogTissStatusTransitionService;
use Illuminate\Support\Facades\DB;

class SubmitGlosaAppealAction
{
    public function __construct(
        private readonly LogTissStatusTransitionService $logStatusTransitionService,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function __invoke(TissGlosaAppeal $appeal, array $payload = []): TissGlosaAppeal
    {
        return DB::transaction(function () use ($appeal, $payload): TissGlosaAppeal {
            $previousStatus = $appeal->status;

            $appeal->update([
                'status'       => TissAppealStatus::Submitted->value,
                'submitted_at' => $payload['submitted_at'] ?? now(),
                'deadline'     => now()->addDays((int) config('tiss.appeal_response_deadline_days', 60))->toDateString(),
            ]);

            $this->logStatusTransitionService->record(
                entityId: (string) $appeal->entity_id,
                contextType: 'glosa_appeal',
                contextId: (string) $appeal->id,
                currentStatus: TissAppealStatus::Submitted->value,
                previousStatus: $previousStatus->value,
                reason: sprintf('Recurso %s enviado à operadora.', $appeal->appeal_number),
            );

            return $appeal->fresh();
        });
    }
}
