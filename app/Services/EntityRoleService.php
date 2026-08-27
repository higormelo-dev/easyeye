<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\{ClientRule, SaasRule};
use App\Models\{Entity, SystemProfile};

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
     * Labels vêm do catálogo system_profiles (pré-definido pelo dono do
     * SaaS, editável na Fase 4); as KEYS continuam ancoradas nos enums —
     * validação/autorização nunca lê a tabela (ver validRuleValues()).
     * SystemProfile::labelMap() já faz fallback para o catálogo hardcoded
     * quando a tabela está vazia.
     *
     * @return array<string, string>
     */
    public function validRuleOptions(Entity $entity): array
    {
        $context = $entity->isSaas()
            ? SystemProfile::CONTEXT_SAAS
            : SystemProfile::CONTEXT_CLIENT;

        $labels = SystemProfile::labelMap($context);

        // Interseção com as keys válidas do enum: uma linha extra/órfã na
        // tabela nunca vira opção de rule inexistente no select.
        $options = [];

        foreach ($this->validRuleValues($entity) as $value) {
            $options[$value] = $labels[$value] ?? $value;
        }

        return $options;
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
     * Labels vêm de system_profiles (com fallback hardcoded) — ver
     * validRuleOptions() para o racional.
     */
    public function labelFor(Entity $entity, string $rule): string
    {
        if ($this->parse($entity, $rule) === null) {
            return $rule;
        }

        $context = $entity->isSaas()
            ? SystemProfile::CONTEXT_SAAS
            : SystemProfile::CONTEXT_CLIENT;

        return SystemProfile::labelFor($context, $rule);
    }
}
