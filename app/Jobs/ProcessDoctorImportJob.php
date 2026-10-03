<?php

namespace App\Jobs;

use App\Models\DoctorImport;
use App\Services\DoctorImportService;
use App\Traits\FailsUnfinishedImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};

class ProcessDoctorImportJob implements ShouldQueue
{
    use Dispatchable;
    use FailsUnfinishedImport;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Sem retentativas — o serviço é idempotente, mas re-processar um import
     *  parcialmente concluído geraria duplicatas. */
    public int $tries = 1;

    /** 10 minutos para imports grandes (até ~10 000 linhas). */
    public int $timeout = 600;

    public function __construct(
        public readonly DoctorImport $import,
    ) {
    }

    public function handle(DoctorImportService $service): void
    {
        $service->process($this->import);
    }

    /** @return array<string, mixed> */
    protected function failureAttributes(): array
    {
        return ['abort_reason' => __('shared_identity.import.failed')];
    }
}
