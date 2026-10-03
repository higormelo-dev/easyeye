<?php

namespace App\Jobs;

use App\Models\MedicineImport;
use App\Services\Medicines\AnvisaMedicineImportService;
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

    /** A lista CMED completa (~26 mil apresentações) leva ~1 min pra ler. */
    public int $timeout = 900;

    public function __construct(
        public readonly MedicineImport $import,
    ) {
    }

    public function handle(AnvisaMedicineImportService $service): void
    {
        $service->process($this->import);
    }

    /** @return array<string, mixed> */
    protected function failureAttributes(): array
    {
        return ['phase' => null, 'error' => __('manager_medicines.import_failed_generic')];
    }
}
