<?php

namespace App\Observers;

use App\Models\Entity;
use App\Services\{ReportSettingService, TrialService};
use Illuminate\Support\Facades\Log;
use Throwable;

class EntityObserver
{
    public function __construct(
        private readonly TrialService $trialService,
        private readonly ReportSettingService $reportSettingService,
    ) {
    }

    /**
     * Ao criar uma empresa cliente, inicia o período de trial automaticamente.
     */
    public function created(Entity $entity): void
    {
        if (! $entity->is_client) {
            return;
        }

        if (! $entity->skipAutoTrial) {
            try {
                $this->trialService->startTrial($entity);
            } catch (Throwable $e) {
                // Não deve impedir a criação da empresa, mas deve ser logado.
                Log::error('Falha ao iniciar trial para empresa.', [
                    'entity_id' => $entity->id,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        try {
            $this->reportSettingService->adoptPublishedGlobalsForEntity((string) $entity->id);
        } catch (Throwable $e) {
            // Não deve impedir a criação da empresa, mas deve ser logado.
            Log::error('Falha ao adotar modelos globais padrão para empresa.', [
                'entity_id' => $entity->id,
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
