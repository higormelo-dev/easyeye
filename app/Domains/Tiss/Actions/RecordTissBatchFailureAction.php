<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Actions;

use App\Domains\Tiss\Enums\TissBatchStatus;
use App\Domains\Tiss\Models\TissBatch;
use App\Domains\Tiss\Services\GenerateTissXmlService;
use Illuminate\Support\Facades\DB;

/**
 * Registra a falha DEFINITIVA de um job do lote TISS (geração do XML, envio) — o
 * que o handler failed() faz depois de esgotar as tentativas.
 *
 * A leitura do status e a escrita ficam na MESMA transação, com a linha do lote
 * travada (FOR UPDATE — a trava que a reivindicação e o fechamento do envio, a
 * geração do XML e as edições do faturamento tomam, sempre como a 1ª entre as
 * linhas do TISS). Ler sem trava, decidir pelo retrato lido e gravar "erro"
 * depois deixava uma sessão que comitasse "enviando"/"enviado" no intervalo ter o
 * status sobrescrito: o lote voltava a ser editável e a regeneração saía com XML e
 * chave de idempotência novos, enquanto a entrega original podia já ter chegado à
 * operadora (achado via revisão adversarial Codex, 19ª rodada). Aqui o handler
 * espera quem já está na linha e decide pelo status COMITADO por ela. Esta é a
 * única trava que ele toma — não há ciclo possível com as ordens documentadas.
 *
 * Só um lote que ainda não saiu vai a "erro". Nunca vão:
 *  - "enviando": entrega em voo, interrompida ou EM DÚVIDA — o lote segue travado
 *    e o reenvio retoma a mesma transmissão e a mesma chave (a razão só é gravada
 *    se $recordWhileSending);
 *  - enviado, confirmado, processado, parcialmente pago, pago: a operadora já
 *    confirmou (inclui o reenvio forçado que esgotou as tentativas);
 *  - cancelado: o usuário o cancelou; uma falha de fundo não o reabre.
 */
class RecordTissBatchFailureAction
{
    /**
     * @return bool true quando o lote foi levado a "erro"
     */
    public function __invoke(string $batchId, ?string $entityId, string $reason, bool $recordWhileSending = false): bool
    {
        return DB::transaction(function () use ($batchId, $entityId, $reason, $recordWhileSending): bool {
            $query = TissBatch::query()->whereKey($batchId);

            if ($entityId) {
                $query->where('entity_id', $entityId);
            }

            $batch = $query->lockForUpdate()->first();

            if (! $batch) {
                return false;
            }

            $protected = in_array($batch->status, GenerateTissXmlService::SENT_STATUSES, true)
                || $batch->status === TissBatchStatus::Cancelled;

            if ($protected) {
                // "Enviando" (entrega em voo, interrompida ou em dúvida): o job de
                // envio registra o motivo; o resto não é tocado.
                if ($batch->status === TissBatchStatus::Sending && $recordWhileSending) {
                    $batch->update(['last_error' => $reason]);
                }

                return false;
            }

            $batch->update([
                'status'     => TissBatchStatus::Error->value,
                'last_error' => $reason,
            ]);

            return true;
        });
    }
}
