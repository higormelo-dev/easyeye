<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\{ClientRule, SaasRule};
use App\Models\Entity;

/**
 * Centralizes all rule-validation logic for the entity context.
 *
 * "Rules" are the values stored in entity_users.rule.
 * Allowed rules depend on whether the entity is the SaaS owner or a client.
 */
final class EntityRoleService
{
    /**
     * Return the enum cases that are valid for the given entity.
     *
     * @return SaasRule[]|ClientRule[]
     */
    public function validRuleCases(Entity $entity): array
    {
        return $entity->isSaas()
            ? SaasRule::cases()
            : ClientRule::cases();
    }

    /**
     * Return the string values that are valid for the given entity.
     *
     * @return string[]
     */
    public function validRuleValues(Entity $entity): array
    {
        return $entity->isSaas()
            ? SaasRule::values()
            : ClientRule::values();
    }

    /**
     * Return key-value options (value => label) for the given entity,
     * ready to be used in select inputs.
     *
     * @return array<string, string>
     */
    public function validRuleOptions(Entity $entity): array
    {
        return $entity->isSaas()
            ? SaasRule::options()
            : ClientRule::options();
    }

    /**
     * Check whether a given rule string is valid for the entity.
     */
    public function isValidRule(Entity $entity, string $rule): bool
    {
        return in_array($rule, $this->validRuleValues($entity), strict: true);
    }

    /**
     * Parse a raw rule string into its typed enum for the entity context.
     * Returns null when the value is not valid for that entity.
     */
    public function parse(Entity $entity, string $rule): SaasRule|ClientRule|null
    {
        return $entity->isSaas()
            ? SaasRule::tryFrom($rule)
            : ClientRule::tryFrom($rule);
    }

    /**
     * Return the label for a rule value within the entity context.
     * Returns the raw value when it is not a recognised rule.
     */
    public function labelFor(Entity $entity, string $rule): string
    {
        return $this->parse($entity, $rule)?->label() ?? $rule;
    }
}
