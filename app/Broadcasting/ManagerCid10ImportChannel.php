<?php

declare(strict_types=1);

namespace App\Broadcasting;

use App\Enums\SaasRule;
use App\Models\{Cid10Import, Entity, User};
use Illuminate\Support\Str;

/**
 * Canal privado `manager.imports.cid10.{importId}` — progresso da
 * importação da CID-10 (manager → CID-10). Mesma regra da tela: entity SaaS
 * na sessão (saas.admin) + papel admin ou dono (saas.role:admin).
 */
class ManagerCid10ImportChannel
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

        return Cid10Import::query()->whereKey($importId)->exists();
    }
}
