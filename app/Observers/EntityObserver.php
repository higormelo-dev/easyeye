<?php

namespace App\Observers;

use App\Models\Entity;
use App\Services\TrialService;
use Illuminate\Support\Facades\Log;

class EntityObserver
{
    public function __construct(
        private readonly TrialService $trialService,
    ) {
    }

    /**
     * Ao criar uma empresa cliente, inicia o período de trial automaticamente.
     */
    public function created(Entity $entity): void
    {
        if (!$entity->is_client || $entity->skipAutoTrial) {
            return;
        }

        try {
            $this->trialService->startTrial($entity);
        } catch (\Throwable $e) {
            // Não deve impedir a criação da empresa, mas deve ser logado.
            Log::error('Falha ao iniciar trial para empresa.', [
                'entity_id' => $entity->id,
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
