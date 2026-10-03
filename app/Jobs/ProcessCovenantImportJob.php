<?php

namespace App\Jobs;

use App\Enums\ImportStatus;
use App\Models\CovenantImport;
use App\Services\Covenants\AnsOperatorImportService;
use App\Traits\FailsUnfinishedImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};

class ProcessCovenantImportJob implements ShouldQueue
{
    use Dispatchable;
    use FailsUnfinishedImport;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Sem retentativa automática: a sincronização é idempotente (chave =
     *  registro ANS) — o admin dispara de novo ou a tarefa semanal repete. */
    public int $tries = 1;

    /** Operadoras (~1,5 MB) em segundos; planos (~75 MB, ~166 mil linhas)
     *  em ~1-2 min — folga para download lento da ANS. */
    public int $timeout = 900;

    public function __construct(
        public readonly CovenantImport $import,
    ) {
    }

    public function handle(AnsOperatorImportService $service): void
    {
        // Cancelada pelo admin enquanto esperava na fila (worker parado) ou já
        // processada: não roda de novo.
        $import = $this->import->fresh();

        if ($import === null || $import->status !== ImportStatus::Pending) {
            return;
        }

        $service->process($import);
    }

    /** @return array<string, mixed> */
    protected function failureAttributes(): array
    {
        return ['phase' => null, 'error' => __('manager_covenants.import_failed_generic')];
    }
}
