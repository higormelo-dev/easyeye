<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Actions;

use App\Domains\Tiss\Enums\{TissAppealStatus, TissGlosaStatus};
use App\Domains\Tiss\Models\{TissGlosa, TissGlosaAppeal};
use App\Domains\Tiss\Services\LogTissStatusTransitionService;
use Illuminate\Support\Facades\DB;

class OpenGlosaAppealAction
{
    public function __construct(
        private readonly LogTissStatusTransitionService $logStatusTransitionService,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function __invoke(TissGlosa $glosa, array $payload = []): TissGlosaAppeal
    {
        return DB::transaction(function () use ($glosa, $payload): TissGlosaAppeal {
            $appeal = TissGlosaAppeal::query()->create([
                'entity_id'        => $glosa->entity_id,
                'glosa_id'         => $glosa->id,
                'protocol_id'      => $payload['protocol_id'] ?? null,
                'xml_document_id'  => $payload['xml_document_id'] ?? null,
                'appeal_number'    => $payload['appeal_number'] ?? $this->nextAppealNumber((string) $glosa->entity_id),
                'status'           => TissAppealStatus::Opened->value,
                'reason'           => $payload['reason'] ?? null,
                'requested_amount' => $payload['requested_amount'] ?? $glosa->amount,
                'accepted_amount'  => 0,
                'metadata'         => $payload['metadata'] ?? null,
            ]);

            $glosa->update(['status' => TissGlosaStatus::Appealed->value]);

            $this->logStatusTransitionService->record(
                entityId: (string) $glosa->entity_id,
                contextType: 'glosa',
                contextId: (string) $glosa->id,
                currentStatus: TissGlosaStatus::Appealed->value,
                previousStatus: TissGlosaStatus::Open->value,
                reason: sprintf('Recurso de glosa %s aberto.', $appeal->appeal_number),
                payload: ['appeal_id' => (string) $appeal->id],
            );

            return $appeal;
        });
    }

    private function nextAppealNumber(string $entityId): string
    {
        $prefix = 'REC-' . now()->format('Ym');

        $lastAppeal = TissGlosaAppeal::query()
            ->forEntity($entityId)
            ->where('appeal_number', 'like', $prefix . '-%')
            ->orderByDesc('appeal_number')
            ->first();

        $next = $lastAppeal
            ? ((int) substr((string) $lastAppeal->appeal_number, strlen($prefix) + 1)) + 1
            : 1;

        return sprintf('%s-%05d', $prefix, $next);
    }
}
