<?php

namespace App\DTOs;

use App\Enums\FeatureKey;

/**
 * Estado atual de uma feature para uma empresa.
 * Retornado por FeatureGateService::status().
 */
final class FeatureStatus
{
    public function __construct(
        public readonly FeatureKey $feature,

        /** Feature está acessível (booleana ativa ou limite não esgotado). */
        public readonly bool $allowed,

        /** true = feature é booleana (habilitada/desabilitada). */
        public readonly bool $isBoolean,

        /** true = sem limite numérico (limit === 0). */
        public readonly bool $isUnlimited,

        /** Limite configurado no plano. 0 = ilimitado. */
        public readonly int $limit,

        /** Quantidade consumida no período atual. */
        public readonly int $used,

        /**
         * Quanto ainda pode ser consumido.
         * PHP_INT_MAX quando ilimitado; -1 quando feature é booleana.
         */
        public readonly int $remaining,
    ) {}

    /** Converte para array (uso em respostas de API). */
    public function toArray(): array
    {
        return [
            'feature'      => $this->feature->value,
            'label'        => $this->feature->label(),
            'allowed'      => $this->allowed,
            'is_boolean'   => $this->isBoolean,
            'is_unlimited' => $this->isUnlimited,
            'limit'        => $this->isUnlimited ? null : $this->limit,
            'used'         => $this->isBoolean ? null : $this->used,
            'remaining'    => $this->isBoolean ? null : ($this->isUnlimited ? null : $this->remaining),
        ];
    }
}
