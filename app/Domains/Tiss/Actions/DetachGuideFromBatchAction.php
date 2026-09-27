<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Actions;

use App\Domains\Tiss\Enums\TissBatchStatus;
use App\Domains\Tiss\Models\{TissBatch, TissBatchGuide, TissGuide};
use App\Domains\Tiss\Services\LogTissStatusTransitionService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Tira uma guia de um lote TISS que ainda não foi enviado (inverso de
 * AttachGuideToBatchAction) — usado quando a guia de faturamento é cancelada.
 *
 * O vínculo é removido de fato (force delete, com o "deleted" na auditoria):
 *  - TissBatch::guides() (belongsToMany) não enxerga o soft delete do pivô —
 *    a guia continuaria no XML e o `guides()->update(...)` da geração/envio
 *    voltaria a mexer no status dela;
 *  - o índice único (batch_id, guide_id) não é parcial: um pivô "excluído"
 *    impediria anexar a mesma guia de novo.
 *
 * Os totais do lote são recalculados como no attach. O XML já gerado fica
 * velho: quem envia pelo faturamento sempre regera o XML antes de enviar
 * (BillingService::submitBatch) e o download volta a gerar (xml_path limpo).
 */
class DetachGuideFromBatchAction
{
    /** Lote que ainda não saiu para a operadora (nem está saindo). */
    public const EDITABLE_STATUSES = [
        TissBatchStatus::Open,
        TissBatchStatus::Closed,
        TissBatchStatus::XmlReady,
        TissBatchStatus::Error,
    ];

    public function __construct(
        private readonly LogTissStatusTransitionService $logStatusTransitionService,
    ) {
    }

    public function __invoke(TissBatch $batch, TissGuide $guide, ?string $reason = null): TissBatch
    {
        if ((string) $batch->entity_id !== (string) $guide->entity_id) {
            throw new InvalidArgumentException('Guia de outra clínica não pode ser retirada deste lote.');
        }

        if (! in_array($batch->status, self::EDITABLE_STATUSES, true)) {
            throw new InvalidArgumentException(
                sprintf('Guia não pode ser retirada do lote. Status atual do lote: %s.', $batch->status->value),
            );
        }

        return DB::transaction(function () use ($batch, $guide, $reason): TissBatch {
            // Sem escopos globais: inclui vínculos excluídos (soft delete), que o
            // índice único continua vendo; a clínica é filtrada aqui mesmo.
            $links = TissBatchGuide::query()
                ->withoutGlobalScopes()
                ->where('entity_id', $batch->entity_id)
                ->where('batch_id', $batch->id)
                ->where('guide_id', $guide->id)
                ->get();

            if ($links->isEmpty()) {
                return $batch;
            }

            $links->each(fn (TissBatchGuide $link) => $link->forceDelete());

            $batch->update([
                'guides_count' => $batch->guides()->count(),
                'total_amount' => (float) $batch->guides()->sum('total_amount'),
            ]);

            $this->logStatusTransitionService->record(
                entityId: (string) $guide->entity_id,
                contextType: 'guide',
                contextId: (string) $guide->id,
                currentStatus: (string) $guide->status->value,
                previousStatus: (string) $guide->status->value,
                reason: $reason ?? sprintf('Guia retirada do lote %s.', $batch->batch_number),
                payload: ['batch_id' => (string) $batch->id],
            );

            return $batch;
        });
    }
}
