<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Jobs;

use App\Domains\Tiss\Actions\RecordTissBatchFailureAction;
use App\Domains\Tiss\Enums\TissBatchStatus;
use App\Domains\Tiss\Exceptions\TissBatchCancelledException;
use App\Domains\Tiss\Models\TissBatch;
use App\Domains\Tiss\Services\GenerateTissXmlService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\{ShouldBeUnique, ShouldQueue};
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Throwable;

class GenerateTissBatchXmlJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $backoff = 45;

    public int $timeout = 120;

    public function __construct(
        public readonly string $batchId,
        public readonly ?string $entityId = null,
    ) {
        $this->tries = (int) config('tiss.queues.max_attempts.xml', 5);
    }

    public function uniqueId(): string
    {
        return $this->batchId;
    }

    public function handle(GenerateTissXmlService $generateTissXmlService): void
    {
        $query = TissBatch::query()->where('id', $this->batchId);

        if ($this->entityId) {
            $query->where('entity_id', $this->entityId);
        }

        $batch = $query->first();

        if (! $batch) {
            return;
        }

        // Cancelado depois de a geração entrar na fila: não há o que gerar, e isso
        // não é falha (nada de retentativas nem de relatório de erro). O mesmo se o
        // cancelamento chegar entre esta leitura e a trava da geração.
        if ($batch->status === TissBatchStatus::Cancelled) {
            return;
        }

        try {
            $generateTissXmlService->generateBatch($batch);
        } catch (TissBatchCancelledException) {
            return;
        }
    }

    public function failed(Throwable $exception): void
    {
        // A decisão e a escrita são atômicas, sob a trava da linha do lote
        // (RecordTissBatchFailureAction). Lote que já saiu — ou pode ter saído:
        // enviando (em voo, worker morto ou entrega EM DÚVIDA), enviado, processado
        // —, ou cancelado, não tem XML a gerar: falha local daqui (deadlock, banco)
        // virar "erro" reabria o lote para edição e o reenvio sairia com outra
        // identidade. O motivo fica nos jobs falhos da fila.
        app(RecordTissBatchFailureAction::class)(
            batchId: $this->batchId,
            entityId: $this->entityId,
            reason: mb_substr('Falha ao gerar XML após todas as tentativas: ' . $exception->getMessage(), 0, 1000),
        );
    }
}
