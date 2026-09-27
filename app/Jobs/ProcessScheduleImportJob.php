<?php

namespace App\Jobs;

use App\Models\ScheduleImport;
use App\Services\ScheduleImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};

class ProcessScheduleImportJob implements ShouldQueue
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
        public readonly ScheduleImport $import,
    ) {
    }

    public function handle(ScheduleImportService $service): void
    {
        $service->process($this->import);
    }
}
