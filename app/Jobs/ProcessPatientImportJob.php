<?php

namespace App\Jobs;

use App\Models\PatientImport;
use App\Services\PatientImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};

class ProcessPatientImportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Sem retentativas — o serviço é idempotente, mas re-processar um import
     *  parcialmente concluído geraria duplicatas. */
    public int $tries = 1;

    /** 10 minutos para imports grandes (até ~10 000 linhas). */
    public int $timeout = 600;

    public function __construct(
        public readonly PatientImport $import,
    ) {
    }

    public function handle(PatientImportService $service): void
    {
        $service->process($this->import);
    }
}
