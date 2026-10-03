<?php

namespace App\Jobs;

use App\Domains\AI\Models\AiCatalogSync;
use App\Domains\AI\Services\Catalog\AiModelCatalogSyncService;
use App\Enums\ImportStatus;
use App\Traits\FailsUnfinishedImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};

/** Sincronização do catálogo de modelos/preços de IA (Manager → Provedores de IA). */
class SyncAiModelCatalogJob implements ShouldQueue
{
    use Dispatchable;
    use FailsUnfinishedImport;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Sem retentativa automática: sincronizar de novo é idempotente (o admin clica). */
    public int $tries = 1;

    /** Catálogo de preços (~3 MB) + uma listagem por provedor com chave: segundos. */
    public int $timeout = 300;

    /** Nome exigido pelo FailsUnfinishedImport. */
    public function __construct(
        public readonly AiCatalogSync $import,
    ) {
    }

    public function handle(AiModelCatalogSyncService $service): void
    {
        // Cancelada enquanto esperava na fila (worker parado) ou já processada.
        $sync = $this->import->fresh();

        if ($sync === null || $sync->status !== ImportStatus::Pending) {
            return;
        }

        $service->run($sync);
    }

    /** @return array<string, mixed> */
    protected function failureAttributes(): array
    {
        return ['phase' => null, 'error' => __('manager_ai.sync_failed_generic')];
    }
}
