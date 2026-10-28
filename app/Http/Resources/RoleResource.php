<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\Permission as PermissionEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializa uma Role customizada (RBAC granular ADITIVO) para o frontend
 * da matriz de permissões (Panel/AccessControl/Roles/Index).
 *
 * Espera `permissions` eager-loaded (with('permissions')) e o count
 * `entity_users_count` (via withCount('entityUsers')) já carregados pelo
 * controller — evita N+1 ao serializar uma coleção.
 *
 * `created_at` sai em ISO 8601: a tela formata no idioma do usuário
 * (useLocaleFormat), em vez de um d/m/Y fixo em português.
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
            // Rótulo/grupo no idioma do usuário quando a chave existe no enum;
            // senão, o texto gravado no catálogo `permissions`.
            'permissions' => $this->permissions->map(function ($permission) {
                $case = PermissionEnum::tryFrom((string) $permission->key);

                return [
                    'key'   => $permission->key,
                    'label' => $case?->localizedLabel() ?? $permission->label,
                    'group' => $case?->localizedGroup() ?? $permission->group,
                ];
            })->values()->all(),
            'users_count' => (int) ($this->entity_users_count ?? $this->entityUsers()->count()),
            'created_at'  => $this->created_at?->toIso8601String(),
        ];
    }
}
