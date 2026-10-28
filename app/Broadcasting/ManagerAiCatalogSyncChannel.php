<?php

declare(strict_types=1);

namespace App\Broadcasting;

use App\Domains\AI\Models\AiCatalogSync;
use App\Enums\EntityGate;
use App\Models\{Entity, User};
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Canal privado `manager.ai-catalog-syncs.{syncId}` — progresso da
 * sincronização do catálogo de modelos/preços de IA. Mesma regra da tela
 * (Manager → Provedores de IA): entity SaaS na sessão + gate SaasAdminPanel.
 */
class ManagerAiCatalogSyncChannel
{
    public function join(User $user, string $syncId): bool
    {
        if (! Str::isUuid($syncId) || session('selected_entity_is_client')) {
            return false;
        }

        $entity = Entity::query()->find(session('selected_entity_id'));

        if (! $entity || ! Gate::forUser($user)->allows(EntityGate::SaasAdminPanel->value, $entity)) {
            return false;
        }

        return AiCatalogSync::query()->whereKey($syncId)->exists();
    }
}
