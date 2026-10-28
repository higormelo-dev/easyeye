<?php

declare(strict_types=1);

namespace App\Broadcasting;

use App\Enums\SaasRule;
use App\Models\{CovenantImport, Entity, User};
use Illuminate\Support\Str;

/**
 * Canal privado `manager.imports.covenants.{importId}` — progresso da
 * sincronização do catálogo global de convênios com a ANS (manager →
 * Convênios). Mesma regra da tela: entity SaaS na sessão e papel admin ou
 * dono (saas.role:admin).
 */
class ManagerCovenantImportChannel
{
    public function join(User $user, string $importId): bool
    {
        if (! Str::isUuid($importId) || session('selected_entity_is_client')) {
            return false;
        }

        $entity = Entity::query()->find(session('selected_entity_id'));

        if (! $entity || $entity->isClient()) {
            return false;
        }

        if (! $user->isOwnerOfEntity($entity) && ! $user->hasAnyRoleInEntity($entity, [SaasRule::Admin->value])) {
            return false;
        }

        return CovenantImport::query()->whereKey($importId)->exists();
    }
}
