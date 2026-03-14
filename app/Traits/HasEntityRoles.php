<?php

declare(strict_types=1);

namespace App\Traits;

use App\Enums\{ClientRule, SaasRule};
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
            $this->entityRuleCache[$entity->id] = EntityUser::query()
                ->where('user_id', $this->id)
                ->where('entity_id', $entity->id)
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
     * @param  array<string|SaasRule|ClientRule>  $roles
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
     * @param  array<string|SaasRule|ClientRule>  $roles
     */
    public function hasNoneOfRolesInEntity(Entity $entity, array $roles): bool
    {
        return ! $this->hasAnyRoleInEntity($entity, $roles);
    }

    /**
     * Return true when the user is the owner of the entity
     * (entity_users.is_owner = true).
     */
    public function isOwnerOfEntity(Entity $entity): bool
    {
        return EntityUser::query()
            ->where('user_id', $this->id)
            ->where('entity_id', $entity->id)
            ->where('is_owner', true)
            ->whereNull('deleted_at')
            ->exists();
    }
}
