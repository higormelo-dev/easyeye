<?php

namespace App\Jobs;

use App\Enums\ImportStatus;
use App\Models\Cid10Import;
use App\Services\Cid10\Cid10ImportService;
use App\Traits\FailsUnfinishedImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};

class ProcessCid10ImportJob implements ShouldQueue
{
    use Dispatchable;
    use FailsUnfinishedImport;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Sem retentativa automática: o serviço marca a importação como falha e o
     *  admin reenvia — reimportar é idempotente (chave = código). */
    public int $tries = 1;

    /** A lista completa (~12,5 mil códigos) leva segundos; folga para banco lento. */
    public int $timeout = 600;

    public function __construct(
        public readonly Cid10Import $import,
    ) {
    }

    public function handle(Cid10ImportService $service): void
    {
        // Cancelada pelo admin enquanto esperava na fila (worker parado) ou já
        // processada: não roda de novo.
        $import = $this->import->fresh();

        if ($import === null || $import->status !== ImportStatus::Pending) {
            return;
        }

        $service->run($import);
    }

    /** @return array<string, mixed> */
    protected function failureAttributes(): array
    {
        return ['phase' => null, 'error' => __('manager_cid10.import_failed_generic')];
    }
}
