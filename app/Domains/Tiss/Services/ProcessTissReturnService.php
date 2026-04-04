<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Services;

use App\Domains\Tiss\Enums\{TissBatchStatus, TissGuideStatus, TissProtocolStatus, TissReturnStatus};
use App\Domains\Tiss\Models\TissReturn;
use Illuminate\Support\Facades\DB;

class ProcessTissReturnService
{
    public function __construct(
        private readonly ProcessGlosaReturnService $processGlosaReturnService,
        private readonly LogTissStatusTransitionService $logStatusTransitionService,
    ) {
    }

    public function process(TissReturn $return): TissReturn
    {
        return DB::transaction(function () use ($return): TissReturn {
            $return->loadMissing([
                'protocol.batch.guides.items',
                'protocol.batch',
            ]);

            $summary = (array) ($return->summary ?? []);
            $status  = (string) ($summary['status'] ?? 'received');
            $glosas  = (array) ($summary['glosas'] ?? []);

            $glosaSummary = $this->processGlosaReturnService->process($return, $glosas);

            $protocol = $return->protocol;
            $batch    = $protocol?->batch;

            if ($protocol) {
                $protocolStatus = $this->mapProtocolStatus($status);
                $previousStatus = $protocol->status->value;

                $protocol->update([
                    'status'       => $protocolStatus->value,
                    'processed_at' => now(),
                    'details'      => array_merge((array) ($protocol->details ?? []), [
                        'return_id'   => (string) $return->id,
                        'glosa_count' => $glosaSummary['count'],
                    ]),
                ]);

                $this->logStatusTransitionService->record(
                    entityId: (string) $return->entity_id,
                    contextType: 'protocol',
                    contextId: (string) $protocol->id,
                    currentStatus: $protocolStatus->value,
                    previousStatus: $previousStatus,
                    reason: 'Protocolo atualizado por processamento de retorno TISS.',
                );
            }

            if ($batch) {
                $batchStatus         = $this->mapBatchStatus($status, (int) $glosaSummary['count']);
                $previousBatchStatus = $batch->status->value;

                $batch->update([
                    'status'       => $batchStatus->value,
                    'processed_at' => now(),
                    'last_error'   => $status === 'rejected' ? 'Lote rejeitado em retorno da operadora.' : null,
                ]);

                $affectedGuideIds   = $glosaSummary['affected_guides'];
                $defaultGuideStatus = $this->mapGuideStatus($status, 0);

                if ($affectedGuideIds !== []) {
                    // Guias com glosa recebem status individually mapeado
                    $affectedGuideStatus = $this->mapGuideStatus($status, count($affectedGuideIds));
                    $batch->guides()
                        ->whereIn('tiss_guides.id', $affectedGuideIds)
                        ->update(['status' => $affectedGuideStatus->value]);

                    // Demais guias do lote recebem o status sem glosa (paid quando accepted)
                    $batch->guides()
                        ->whereNotIn('tiss_guides.id', $affectedGuideIds)
                        ->update(['status' => $defaultGuideStatus->value]);
                } else {
                    $batch->guides()->update(['status' => $defaultGuideStatus->value]);
                }

                $this->logStatusTransitionService->record(
                    entityId: (string) $return->entity_id,
                    contextType: 'batch',
                    contextId: (string) $batch->id,
                    currentStatus: $batchStatus->value,
                    previousStatus: $previousBatchStatus,
                    reason: 'Lote atualizado por retorno da operadora.',
                    payload: [
                        'return_id'       => (string) $return->id,
                        'glosa_count'     => $glosaSummary['count'],
                        'affected_guides' => $affectedGuideIds,
                    ],
                );
            }

            $previousReturnStatus = $return->status->value;
            $return->update([
                'status'       => TissReturnStatus::Processed->value,
                'processed_at' => now(),
                'summary'      => array_merge($summary, [
                    'glosa_count'     => $glosaSummary['count'],
                    'total_denied'    => $glosaSummary['total_denied'],
                    'affected_guides' => $glosaSummary['affected_guides'],
                ]),
            ]);

            $this->logStatusTransitionService->record(
                entityId: (string) $return->entity_id,
                contextType: 'return',
                contextId: (string) $return->id,
                currentStatus: TissReturnStatus::Processed->value,
                previousStatus: $previousReturnStatus,
                reason: 'Retorno TISS processado com sucesso.',
            );

            return $return->fresh();
        });
    }

    private function mapProtocolStatus(string $status): TissProtocolStatus
    {
        return match ($status) {
            'accepted'   => TissProtocolStatus::Accepted,
            'rejected'   => TissProtocolStatus::Rejected,
            'processing' => TissProtocolStatus::Processing,
            'error'      => TissProtocolStatus::Error,
            default      => TissProtocolStatus::Received,
        };
    }

    private function mapBatchStatus(string $status, int $glosaCount): TissBatchStatus
    {
        return match ($status) {
            'accepted'   => $glosaCount > 0 ? TissBatchStatus::PartiallyPaid : TissBatchStatus::Processed,
            'rejected'   => TissBatchStatus::Error,
            'error'      => TissBatchStatus::Error,
            'processing' => TissBatchStatus::Acknowledged,
            default      => TissBatchStatus::Acknowledged,
        };
    }

    private function mapGuideStatus(string $status, int $glosaCount): TissGuideStatus
    {
        return match ($status) {
            'accepted'   => $glosaCount > 0 ? TissGuideStatus::PartiallyDenied : TissGuideStatus::Paid,
            'rejected'   => TissGuideStatus::Denied,
            'error'      => TissGuideStatus::Error,
            'processing' => TissGuideStatus::Acknowledged,
            default      => TissGuideStatus::Acknowledged,
        };
    }
}
