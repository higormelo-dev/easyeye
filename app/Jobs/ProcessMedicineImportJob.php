<?php

namespace App\Jobs;

use App\Enums\ImportStatus;
use App\Models\MedicineImport;
use App\Services\Medicines\MedicineCatalogSyncService;
use App\Traits\FailsUnfinishedImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};

class ProcessMedicineImportJob implements ShouldQueue
{
    use Dispatchable;
    use FailsUnfinishedImport;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Sem retentativa automática: o serviço marca o import como falho e o
     *  admin reenvia — reimportar é idempotente (chave = código GGREM). */
    public int $tries = 1;

    /** Download das fontes oficiais (~21 MB) + a lista completa (~26 mil
     *  apresentações) em ~1 min. */
    public int $timeout = 900;

    public function __construct(
        public readonly MedicineImport $import,
    ) {
    }

    public function handle(MedicineCatalogSyncService $service): void
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
        return ['phase' => null, 'error' => __('manager_medicines.import_failed_generic')];
    }
}
