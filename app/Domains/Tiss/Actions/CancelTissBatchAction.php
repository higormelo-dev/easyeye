<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Actions;

use App\Domains\Tiss\Enums\{TissBatchStatus, TissGuideStatus};
use App\Domains\Tiss\Models\TissBatch;
use App\Domains\Tiss\Services\LogTissStatusTransitionService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CancelTissBatchAction
{
    /** @var TissBatchStatus[] Statuses que não permitem cancelamento */
    private const IMMUTABLE_STATUSES = [
        TissBatchStatus::Sending,
        TissBatchStatus::Sent,
        TissBatchStatus::Acknowledged,
        TissBatchStatus::Processed,
        TissBatchStatus::PartiallyPaid,
        TissBatchStatus::Paid,
    ];

    public function __construct(
        private readonly LogTissStatusTransitionService $logStatusTransitionService,
    ) {
    }

    public function __invoke(TissBatch $batch, ?string $reason = null): TissBatch
    {
        if (in_array($batch->status, self::IMMUTABLE_STATUSES, true)) {
            throw new InvalidArgumentException(
                sprintf('Lote não pode ser cancelado. Status atual: %s.', $batch->status->value),
            );
        }

        if ($batch->status === TissBatchStatus::Cancelled) {
            return $batch;
        }

        return DB::transaction(function () use ($batch, $reason): TissBatch {
            $previousStatus = $batch->status->value;

            $batch->update(['status' => TissBatchStatus::Cancelled->value]);

            // Cancelar também as guias que ainda estão em aberto ou em lote
            $batch->guides()
                ->whereIn('tiss_guides.status', [
                    TissGuideStatus::Draft->value,
                    TissGuideStatus::Batched->value,
                    TissGuideStatus::XmlGenerated->value,
                ])
                ->update(['status' => TissGuideStatus::Cancelled->value]);

            $this->logStatusTransitionService->record(
                entityId: (string) $batch->entity_id,
                contextType: 'batch',
                contextId: (string) $batch->id,
                currentStatus: TissBatchStatus::Cancelled->value,
                previousStatus: $previousStatus,
                reason: $reason ?? 'Lote cancelado.',
            );

            return $batch->fresh();
        });
    }
}
