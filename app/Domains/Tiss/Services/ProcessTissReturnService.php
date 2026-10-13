<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Services;

use App\Domains\Tiss\Enums\{TissBatchStatus, TissGuideStatus, TissProtocolStatus, TissReturnStatus};
use App\Domains\Tiss\Models\{TissBatch, TissReturn};
use Illuminate\Support\Facades\DB;

class ProcessTissReturnService
{
    public function __construct(
        private readonly ProcessGlosaReturnService $processGlosaReturnService,
        private readonly LogTissStatusTransitionService $logStatusTransitionService,
    ) {
    }

    /**
     * Processa o retorno UMA vez. O job da fila é entregue "pelo menos uma vez":
     * se o worker morre depois do commit e antes de confirmar, ele volta e chega
     * aqui de novo — e, sem a marca `processed` conferida SOB A TRAVA, soma a
     * glosa outra vez em guide.denied_amount (50 virava 100 e o pago caía de 100
     * para 50) e reescreve protocolo, lote e guias com o desfecho de um retorno
     * velho por cima de um mais novo (achado via revisão adversarial Codex, 25ª
     * rodada). Quem já foi processado volta como está, sem escrever nada.
     */
    public function process(TissReturn $return): TissReturn
    {
        return DB::transaction(function () use ($return): TissReturn {
            // 1ª trava: a linha do lote — a MESMA que o envio (reivindicação e
            // fechamento, inclusive o reenvio forçado de transmissão confirmada, que
            // atualiza o protocolo já existente), a geração do XML e as edições do
            // faturamento tomam antes de qualquer protocolo/guia. Atualizar o
            // protocolo (e as guias, via glosas) ANTES do lote invertia essa ordem:
            // com o reenvio segurando o lote e esperando o protocolo, e este
            // processamento segurando o protocolo e esperando o lote, o PostgreSQL
            // abortava uma das duas — o reenvio podia falhar com a operadora já tendo
            // aceitado (achado via revisão adversarial Codex, 21ª rodada).
            $return->loadMissing('protocol');

            $batchId = $return->protocol?->batch_id;

            if ($batchId !== null) {
                TissBatch::query()
                    ->forEntity((string) $return->entity_id)
                    ->whereKey($batchId)
                    ->lockForUpdate()
                    ->first();
            }

            // 2ª trava: a linha do retorno, DEPOIS do lote (lote → retorno → protocolo
            // → guias). O lote já serializa os retornos dele; esta trava vale também
            // para o retorno sem lote e deixa o `failed()` do job decidir pelo mesmo
            // estado comitado. Um retorno apagado nesse intervalo não é processado.
            $locked = TissReturn::query()
                ->forEntity((string) $return->entity_id)
                ->whereKey($return->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return $return;
            }

            // A instância recebida pode ser de ANTES do commit de outra execução
            // (reentrega do job, duplo despacho): o que vale é o estado comitado, lido
            // sob a trava. Já processado = as escritas daquela execução ficam; esta
            // não repete nenhuma (efeito financeiro e marca `processed` andam juntos,
            // na mesma transação, no fim deste método).
            $return->refresh();

            if ($return->status === TissReturnStatus::Processed) {
                return $return;
            }

            // Relê DEPOIS da trava: protocolo, lote e guias lidos antes dela podem
            // estar velhos (o reenvio que acabou de comitar atualizou o protocolo —
            // mesclar o `details` de um retrato antigo apagaria essa atualização).
            $return->load([
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
