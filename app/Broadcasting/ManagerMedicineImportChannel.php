<?php

declare(strict_types=1);

namespace App\Broadcasting;

use App\Enums\SaasRule;
use App\Models\{Entity, MedicineImport, User};
use Illuminate\Support\Str;

/**
 * Canal privado `manager.imports.medicines.{importId}` — progresso da
 * importação do catálogo global de medicamentos (manager → Medicamentos).
 * Mesma regra da tela: entity SaaS na sessão (saas.admin) + papel admin ou
 * dono (saas.role:admin).
 */
class ManagerMedicineImportChannel
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

        return MedicineImport::query()->whereKey($importId)->exists();
    }
}
