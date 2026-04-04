<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Actions;

use App\Domains\Tiss\Enums\TissBatchStatus;
use App\Domains\Tiss\Models\TissBatch;
use App\Domains\Tiss\Services\LogTissStatusTransitionService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CloseTissBatchAction
{
    public function __construct(
        private readonly LogTissStatusTransitionService $logStatusTransitionService,
    ) {
    }

    public function __invoke(TissBatch $batch): TissBatch
    {
        if ($batch->status !== TissBatchStatus::Open) {
            throw new InvalidArgumentException(
                sprintf('Lote não pode ser fechado. Status atual: %s.', $batch->status->value),
            );
        }

        if ((int) $batch->guides_count === 0) {
            throw new InvalidArgumentException('Não é possível fechar um lote sem guias.');
        }

        return DB::transaction(function () use ($batch): TissBatch {
            $previousStatus = $batch->status->value;

            $batch->update([
                'status'    => TissBatchStatus::Closed->value,
                'closed_at' => now(),
            ]);

            $this->logStatusTransitionService->record(
                entityId: (string) $batch->entity_id,
                contextType: 'batch',
                contextId: (string) $batch->id,
                currentStatus: TissBatchStatus::Closed->value,
                previousStatus: $previousStatus,
                reason: 'Lote fechado para geração de XML.',
            );

            return $batch->fresh();
        });
    }
}
