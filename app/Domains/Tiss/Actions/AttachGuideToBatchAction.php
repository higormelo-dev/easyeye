<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Actions;

use App\Domains\Tiss\Enums\TissGuideStatus;
use App\Domains\Tiss\Models\{TissBatch, TissBatchGuide, TissGuide};
use App\Domains\Tiss\Services\{LogTissStatusTransitionService, PreValidateTissGuideService};
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AttachGuideToBatchAction
{
    public function __construct(
        private readonly LogTissStatusTransitionService $logStatusTransitionService,
        private readonly PreValidateTissGuideService $preValidator,
    ) {
    }

    public function __invoke(TissBatch $batch, TissGuide $guide): TissBatchGuide
    {
        if ((string) $batch->entity_id !== (string) $guide->entity_id) {
            throw new InvalidArgumentException('Guia de outra clínica não pode ser anexada ao lote.');
        }

        if ((string) $batch->operator_id !== (string) $guide->operator_id) {
            throw new InvalidArgumentException('Guia e lote devem pertencer à mesma operadora.');
        }

        $validation = $this->preValidator->validate($guide);

        $guide->update(['errors' => $validation->isEmpty() ? null : $validation->toArray()]);

        if ($validation->hasErrors()) {
            throw new InvalidArgumentException(
                "A guia possui pendências que causarão glosa:\n" . $validation->errorMessages(),
            );
        }

        return DB::transaction(function () use ($batch, $guide): TissBatchGuide {
            $link = TissBatchGuide::query()->firstOrCreate(
                ['batch_id' => $batch->id, 'guide_id' => $guide->id],
                ['entity_id' => $batch->entity_id, 'attached_at' => now()],
            );

            $previousGuideStatus = $guide->status->value;
            $guide->update(['status' => TissGuideStatus::Batched->value]);

            $batch->update([
                'guides_count' => $batch->guides()->count(),
                'total_amount' => (float) $batch->guides()->sum('total_amount'),
            ]);

            $this->logStatusTransitionService->record(
                entityId: (string) $guide->entity_id,
                contextType: 'guide',
                contextId: (string) $guide->id,
                currentStatus: TissGuideStatus::Batched->value,
                previousStatus: $previousGuideStatus,
                reason: sprintf('Guia anexada ao lote %s.', $batch->batch_number),
            );

            return $link;
        });
    }
}
