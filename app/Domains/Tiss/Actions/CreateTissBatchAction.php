<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Actions;

use App\Domains\Tiss\Enums\TissBatchStatus;
use App\Domains\Tiss\Models\{TissBatch, TissEntityOperatorContract};
use App\Domains\Tiss\Services\{LogTissStatusTransitionService, ResolveTissVersionService};
use Illuminate\Support\Facades\DB;

class CreateTissBatchAction
{
    public function __construct(
        private readonly ResolveTissVersionService $resolveVersionService,
        private readonly LogTissStatusTransitionService $logStatusTransitionService,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function __invoke(array $payload): TissBatch
    {
        return DB::transaction(function () use ($payload): TissBatch {
            $entityId = (string) ($payload['entity_id'] ?? session('selected_entity_id'));

            $contract = null;

            if (! empty($payload['contract_id'])) {
                $contract = TissEntityOperatorContract::query()
                    ->forEntity($entityId)
                    ->where('id', $payload['contract_id'])
                    ->first();
            }

            $version = $this->resolveVersionService->resolve(
                explicitVersionCode: $payload['version_code'] ?? null,
                contract: $contract,
            );

            $referenceMonth = (string) ($payload['reference_month'] ?? now()->format('Y-m'));

            $batch = TissBatch::query()->create([
                'entity_id'       => $entityId,
                'operator_id'     => $payload['operator_id'],
                'contract_id'     => $contract?->id,
                'version_id'      => $version->id,
                'batch_number'    => $payload['batch_number'] ?? $this->nextBatchNumber($entityId, (string) $payload['operator_id'], $referenceMonth),
                'reference_month' => $referenceMonth,
                'status'          => TissBatchStatus::Open->value,
                'guides_count'    => 0,
                'total_amount'    => 0,
                'metadata'        => $payload['metadata'] ?? null,
            ]);

            $this->logStatusTransitionService->record(
                entityId: (string) $batch->entity_id,
                contextType: 'batch',
                contextId: (string) $batch->id,
                currentStatus: (string) $batch->status->value,
                reason: 'Lote TISS criado.',
            );

            return $batch;
        });
    }

    private function nextBatchNumber(string $entityId, string $operatorId, string $referenceMonth): string
    {
        $prefix = 'LOT-' . str_replace('-', '', $referenceMonth);

        $lastBatch = TissBatch::query()
            ->forEntity($entityId)
            ->where('operator_id', $operatorId)
            ->where('batch_number', 'like', $prefix . '-%')
            ->orderByDesc('batch_number')
            ->first();

        $next = $lastBatch
            ? ((int) substr((string) $lastBatch->batch_number, strlen($prefix) + 1)) + 1
            : 1;

        return sprintf('%s-%04d', $prefix, $next);
    }
}
