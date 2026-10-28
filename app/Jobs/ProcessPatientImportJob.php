<?php

namespace App\Jobs;

use App\Models\PatientImport;
use App\Services\PatientImportService;
use App\Traits\FailsUnfinishedImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};

class ProcessPatientImportJob implements ShouldQueue
{
    use Dispatchable;
    use FailsUnfinishedImport;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Sem retentativas do job — re-executar um import parcialmente concluído geraria duplicatas. Reprocessar as linhas
     *  que falharam por convênio NÃO é retentar o pai: cria um import FILHO (`reprocess_of_id`, `reprocess_lines`) que
     *  processa só essas linhas, contando com a deduplicação do serviço (CPF, nome+telefone, import_code). */
    public int $tries = 1;

    /** 10 minutos para imports grandes (até ~10 000 linhas). */
    public int $timeout = 600;

    public function __construct(
        public readonly PatientImport $import,
    ) {
        $this->onQueue((string) config('queue.bulk_queue', 'bulk'));
    }

    public function handle(PatientImportService $service): void
    {
        $service->process($this->import);
    }

    /** @return array<string, mixed> */
    protected function failureAttributes(): array
    {
        return ['abort_reason' => __('shared_identity.import.failed')];
    }
}
