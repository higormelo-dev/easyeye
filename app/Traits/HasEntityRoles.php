<?php

declare(strict_types=1);

namespace App\Traits;

use App\Enums\{ClientRule, SaasRule};
use App\Enums\Permission as PermissionEnum;
use App\Models\{Entity, EntityUser};
use BackedEnum;

/**
 * Adds contextual role-checking methods to the User model.
 *
 * All checks are scoped to a specific Entity instance, because the same user
 * can have different rules in different entities.
 *
 * The rule value is read from entity_users.rule.
 * Only non-deleted, active entity_user records are considered for access checks.
 */
trait HasEntityRoles
{
    /**
     * Per-request in-memory cache keyed by entity ID.
     * Avoids redundant queries when the same entity is checked multiple times.
     *
     * @var array<string, string|null>
     */
    private array $entityRuleCache = [];

    /**
     * Per-request in-memory cache keyed by "{entityId}:{permissionValue}".
     * Same rationale as $entityRuleCache — avoids redundant queries when the
     * same permission is checked multiple times for the same entity (e.g.
     * once in middleware, again in a Vue prop/gate check within the request).
     *
     * @var array<string, bool>
     */
    private array $entityPermissionCache = [];

    // -------------------------------------------------------------------------
    // Core lookup
    // -------------------------------------------------------------------------

    /**
     * Return the raw rule string stored in entity_users.rule for this user
     * in the given entity, or null when no active membership exists.
     *
     * The result is cached in memory for the duration of the request.
     */
    public function getRuleInEntity(Entity $entity): ?string
    {
        if (! array_key_exists($entity->id, $this->entityRuleCache)) {
            // Achado de segurança (auditoria manager.php — ROLE_BYPASS
            // CRITICAL): faltava active=true aqui, diferente de
            // canAccessEntity() (linha ~134) que já filtra por isso. Sem essa
            // checagem, um vínculo entity_users desativado (active=false, sem
            // soft-delete — forma comum de "revogar acesso") continuava
            // resolvendo role normalmente em EnsureSaasRole e em todo Gate
            // Saas*, retendo acesso admin total indefinidamente mesmo após
            // desativação.
            $this->entityRuleCache[$entity->id] = EntityUser::query()
                ->where('user_id', $this->id)
                ->where('entity_id', $entity->id)
                ->where('active', true)
                ->whereNull('deleted_at')
                ->value('rule');
        }

        return $this->entityRuleCache[$entity->id];
    }

    /**
     * Alias for getRuleInEntity() — returns the raw rule string or null.
     */
    public function roleInEntity(Entity $entity): ?string
    {
        return $this->getRuleInEntity($entity);
    }

    /**
     * Return the EntityUser pivot record for this user in the given entity,
     * including inactive records (for display purposes).
     * Returns null when no membership exists.
     */
    public function entityUserFor(Entity $entity): ?EntityUser
    {
        return EntityUser::query()
            ->where('user_id', $this->id)
            ->where('entity_id', $entity->id)
            ->whereNull('deleted_at')
            ->first();
    }

    /**
     * Flush the in-memory rule cache for a specific entity (or all entities).
     * Useful after updating an entity_user record in the same request cycle.
     */
    public function flushEntityRuleCache(?Entity $entity = null): void
    {
        if ($entity !== null) {
            unset($this->entityRuleCache[$entity->id]);
        } else {
            $this->entityRuleCache = [];
        }

        $this->flushEntityPermissionCache($entity);
    }

    /**
     * Flush the in-memory permission cache for a specific entity (or all
     * entities). Useful after updating a user's custom Role assignments in
     * the same request cycle.
     */
    public function flushEntityPermissionCache(?Entity $entity = null): void
    {
        if ($entity === null) {
            $this->entityPermissionCache = [];

            return;
        }

        foreach (array_keys($this->entityPermissionCache) as $cacheKey) {
            if (str_starts_with($cacheKey, $entity->id . ':')) {
                unset($this->entityPermissionCache[$cacheKey]);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Access checks
    // -------------------------------------------------------------------------

    /**
     * Return true when the user has an active, non-deleted membership
     * in the given entity, regardless of their rule.
     */
    public function canAccessEntity(Entity $entity): bool
    {
        return EntityUser::query()
            ->where('user_id', $this->id)
            ->where('entity_id', $entity->id)
            ->where('active', true)
            ->whereNull('deleted_at')
            ->exists();
    }

    // -------------------------------------------------------------------------
    // Role checks
    // -------------------------------------------------------------------------

    /**
     * Return true when the user's rule in the given entity matches exactly.
     *
     * Accepts a raw string or a typed enum (SaasRule | ClientRule).
     */
    public function hasRoleInEntity(Entity $entity, string|BackedEnum $role): bool
    {
        $value = $role instanceof BackedEnum ? $role->value : $role;

        return $this->getRuleInEntity($entity) === $value;
    }

    /**
     * Return true when the user's rule in the given entity matches any of the
     * provided roles.
     *
     * Each element of $roles may be a raw string or a typed enum.
     *
     * @param array<string|SaasRule|ClientRule> $roles
     */
    public function hasAnyRoleInEntity(Entity $entity, array $roles): bool
    {
        $currentRule = $this->getRuleInEntity($entity);

        if ($currentRule === null) {
            return false;
        }

        foreach ($roles as $role) {
            $value = $role instanceof BackedEnum ? $role->value : $role;

            if ($currentRule === $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return true when the user has none of the provided roles in the entity.
     *
     * @param array<string|SaasRule|ClientRule> $roles
     */
    public function hasNoneOfRolesInEntity(Entity $entity, array $roles): bool
    {
        return ! $this->hasAnyRoleInEntity($entity, $roles);
    }

    // -------------------------------------------------------------------------
    // Granular permission checks (RBAC customizado ADITIVO)
    // -------------------------------------------------------------------------

    /**
     * Return true when the user has the given granular Permission in the
     * entity — seja por bypass de admin (ClientRule::Admin), seja por
     * possuir uma Role customizada (app/Models/Role) atribuída ao seu
     * EntityUser nessa entity que contenha essa permission.
     *
     * COMPLIANCE — REGRA INEGOCIÁVEL:
     * Este método é para permissões administrativas/operacionais
     * NÃO-CLÍNICAS (ver App\Enums\Permission). NUNCA usar para gates
     * clínicos/regulatórios travados — ex: emissão/assinatura de laudo
     * (EntityGate::IssueReport), prescrição, ou qualquer outra ação hoje
     * gated diretamente por ClientRule::Doctor. Esses continuam e devem
     * continuar usando hasRoleInEntity($entity, ClientRule::Doctor) direto,
     * fora do alcance de qualquer Role customizada — mesmo que uma clínica
     * crie uma Role customizada com todas as permissions existentes
     * atribuídas, ela JAMAIS destrava essas ações. O sistema de Role é uma
     * camada aditiva puramente administrativa por cima da ClientRule fixa.
     *
     * O resultado é cacheado em memória por request (chave entity+permission).
     */
    public function hasPermissionInEntity(Entity $entity, PermissionEnum $permission): bool
    {
        $cacheKey = $entity->id . ':' . $permission->value;

        if (array_key_exists($cacheKey, $this->entityPermissionCache)) {
            return $this->entityPermissionCache[$cacheKey];
        }

        // Bypass: admin da ClientRule fixa sempre tem acesso administrativo
        // total — mesmo padrão já usado no resto do sistema (ManageUsers,
        // ManageSettings, etc. em AuthServiceProvider).
        if ($this->hasRoleInEntity($entity, ClientRule::Admin)) {
            return $this->entityPermissionCache[$cacheKey] = true;
        }

        $entityUser = $this->entityUserFor($entity);

        if ($entityUser === null) {
            return $this->entityPermissionCache[$cacheKey] = false;
        }

        $hasPermission = $entityUser->roles()
            ->whereHas('permissions', function ($query) use ($permission): void {
                $query->where('key', $permission->value);
            })
            ->exists();

        return $this->entityPermissionCache[$cacheKey] = $hasPermission;
    }

    /**
     * Return true when the user is the owner of the entity
     * (entity_users.is_owner = true).
     */
    public function isOwnerOfEntity(Entity $entity): bool
    {
        // Achado de segurança (auditoria manager.php — ROLE_BYPASS CRITICAL):
        // mesmo motivo de getRuleInEntity() acima — sem active=true, um owner
        // desativado continuava passando por EnsureSaasRole via este check.
        return EntityUser::query()
            ->where('user_id', $this->id)
            ->where('entity_id', $entity->id)
            ->where('is_owner', true)
            ->where('active', true)
            ->whereNull('deleted_at')
            ->exists();
    }
}
