<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Jobs;

use App\Domains\Tiss\Actions\RecordTissBatchFailureAction;
use App\Domains\Tiss\Enums\TissBatchStatus;
use App\Domains\Tiss\Exceptions\TissBatchCancelledException;
use App\Domains\Tiss\Models\TissBatch;
use App\Domains\Tiss\Services\SendTissBatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\{ShouldBeUnique, ShouldQueue};
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Throwable;

class SendTissBatchJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $backoff = 120;

    public int $timeout = 90;

    public function __construct(
        public readonly string $batchId,
        public readonly ?string $entityId = null,
        public readonly bool $force = false,
    ) {
        $this->tries = (int) config('tiss.queues.max_attempts.send', 5);
    }

    public function uniqueId(): string
    {
        return $this->batchId;
    }

    public function handle(SendTissBatchService $sendTissBatchService): void
    {
        $query = TissBatch::query()->where('id', $this->batchId);

        if ($this->entityId) {
            $query->where('entity_id', $this->entityId);
        }

        $batch = $query->first();

        if (! $batch) {
            return;
        }

        // Cancelado depois de o envio entrar na fila: não há o que enviar, e isso não
        // é falha (nada de retentativas nem de relatório de erro). O mesmo se o
        // cancelamento chegar entre esta leitura e a reivindicação da transmissão.
        if ($batch->status === TissBatchStatus::Cancelled) {
            return;
        }

        try {
            $sendTissBatchService->send(
                batch: $batch,
                force: $this->force,
                throwOnError: true,
            );
        } catch (TissBatchCancelledException) {
            return;
        }
    }

    public function failed(Throwable $exception): void
    {
        // A decisão e a escrita são atômicas, sob a trava da linha do lote
        // (RecordTissBatchFailureAction): "enviando" é entrega em dúvida ou em voo
        // — só registra o motivo, o reenvio retoma a MESMA transmissão/chave —, e
        // lote já entregue (reenvio forçado que esgotou as tentativas) ou cancelado
        // nunca vira "erro" (achado via revisão adversarial Codex, 18ª e 19ª rodadas).
        app(RecordTissBatchFailureAction::class)(
            batchId: $this->batchId,
            entityId: $this->entityId,
            reason: mb_substr('Falha ao enviar lote após todas as tentativas: ' . $exception->getMessage(), 0, 1000),
            recordWhileSending: true,
        );
    }
}
