<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Actions;

use App\Domains\Tiss\Enums\{TissBatchStatus, TissXmlStatus};
use App\Domains\Tiss\Models\TissBatch;
use App\Domains\Tiss\Services\LogTissStatusTransitionService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class RetryTissBatchAction
{
    public function __construct(
        private readonly LogTissStatusTransitionService $logStatusTransitionService,
    ) {
    }

    /**
     * Move o lote de estado 'error' de volta para 'xml_ready' (se houver XML válido)
     * ou para 'closed' (se não houver XML válido, para regerar).
     */
    public function __invoke(TissBatch $batch, ?string $reason = null): TissBatch
    {
        if ($batch->status !== TissBatchStatus::Error) {
            throw new InvalidArgumentException(
                sprintf('Apenas lotes em estado error podem ser retentados. Status atual: %s.', $batch->status->value),
            );
        }

        return DB::transaction(function () use ($batch, $reason): TissBatch {
            $previousStatus = $batch->status->value;

            $batch->loadMissing('xmlDocument');

            $hasValidXml = $batch->xmlDocument
                && in_array($batch->xmlDocument->status, [TissXmlStatus::Validated, TissXmlStatus::Signed], true);

            $newStatus = $hasValidXml ? TissBatchStatus::XmlReady : TissBatchStatus::Closed;

            $batch->update([
                'status'     => $newStatus->value,
                'last_error' => null,
            ]);

            $this->logStatusTransitionService->record(
                entityId: (string) $batch->entity_id,
                contextType: 'batch',
                contextId: (string) $batch->id,
                currentStatus: $newStatus->value,
                previousStatus: $previousStatus,
                reason: $reason ?? ($hasValidXml
                    ? 'Reenvio agendado a partir de XML válido existente.'
                    : 'Lote reaberto para regerar XML.'),
            );

            return $batch->fresh();
        });
    }
}
