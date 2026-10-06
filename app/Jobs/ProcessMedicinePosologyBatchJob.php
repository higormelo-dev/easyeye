<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\MedicinePosologyBatch;
use App\Services\Medicines\MedicinePosologyBatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Lote de posologia por IA (Manager → Medicamentos). Cada execução processa
 * grupos por um tempo limitado (medicines.posology_batch.job_time_budget_seconds)
 * e agenda a própria continuação — um lote de 200 chamadas nunca fica preso
 * num job só (retry_after da fila, deploy, worker reiniciado).
 *
 * Trava por lote: entrega duplicada da fila nunca processa em paralelo; o
 * plano gravado (medicine_posology_batch_groups) faz cada execução retomar
 * de onde a anterior parou.
 */
class ProcessMedicinePosologyBatchJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Erro inesperado (banco, rede) tenta de novo — retomar é idempotente. */
    public int $tries = 3;

    public int $backoff = 10;

    /** Orçamento de tempo + uma chamada lenta com as novas tentativas. */
    public int $timeout = 300;

    public function __construct(
        public readonly MedicinePosologyBatch $batch,
    ) {
    }

    public function handle(MedicinePosologyBatchService $service): void
    {
        $batch = $this->batch->fresh();

        // Cancelado na fila, já terminado ou removido: não roda.
        if ($batch === null || ! $batch->isRunning()) {
            return;
        }

        $lock = Cache::lock('medicine-posology-batch:run:' . $batch->id, $this->timeout + 30);

        if (! $lock->get()) {
            return; // outra entrega deste lote já está processando
        }

        try {
            $more = $service->process($batch, max(0, (int) config('medicines.posology_batch.job_time_budget_seconds', 45)));
        } finally {
            $lock->release();
        }

        if ($more) {
            self::dispatch($batch);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(MedicinePosologyBatchService::class)->markFailed($this->batch);
    }
}
