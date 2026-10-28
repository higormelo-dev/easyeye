<?php

declare(strict_types=1);

namespace App\Broadcasting;

use App\Enums\SaasRule;
use App\Models\{Entity, MedicinePosologyBatch, User};
use Illuminate\Support\Str;

/**
 * Canal privado `manager.medicines.posology-batches.{batchId}` — progresso do
 * lote "Gerar posologia com IA" (Manager → Medicamentos). Mesma regra da
 * tela: entity SaaS na sessão + papel admin ou dono (saas.role:admin).
 */
class ManagerMedicinePosologyBatchChannel
{
    public function join(User $user, string $batchId): bool
    {
        if (! Str::isUuid($batchId) || session('selected_entity_is_client')) {
            return false;
        }

        $entity = Entity::query()->find(session('selected_entity_id'));

        if (! $entity || $entity->isClient()) {
            return false;
        }

        if (! $user->isOwnerOfEntity($entity) && ! $user->hasAnyRoleInEntity($entity, [SaasRule::Admin->value])) {
            return false;
        }

        return MedicinePosologyBatch::query()->whereKey($batchId)->exists();
    }
}
