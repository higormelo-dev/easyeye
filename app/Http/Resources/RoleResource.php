<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializa uma Role customizada (RBAC granular ADITIVO) para o frontend
 * da matriz de permissões (Panel/AccessControl/Roles/Index).
 *
 * Espera `permissions` eager-loaded (with('permissions')) e o count
 * `entity_users_count` (via withCount('entityUsers')) já carregados pelo
 * controller — evita N+1 ao serializar uma coleção.
 */
class RoleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'name'           => $this->name,
            'description'    => $this->description,
            'permission_ids' => $this->permissions->pluck('id')->values()->all(),
            'permissions'    => $this->permissions->map(fn ($permission) => [
                'key'   => $permission->key,
                'label' => $permission->label,
                'group' => $permission->group,
            ])->values()->all(),
            'users_count' => (int) ($this->entity_users_count ?? $this->entityUsers()->count()),
            'created_at'  => $this->created_at?->format('d/m/Y H:i'),
        ];
    }
}
