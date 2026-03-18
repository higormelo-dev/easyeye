<?php

namespace App\Services;

use App\Enums\FeatureKey;
use App\Models\Doctor;
use App\Models\EntityUser;
use App\Models\FeatureUsage;
use App\Models\Patient;

/**
 * Responsável por ler e registrar o consumo de features por empresa.
 *
 * Estratégias de contagem:
 *
 *   max_users, max_patients, max_doctors
 *     → Count direto nas tabelas de domínio (sempre atualizado).
 *       Não usa feature_usages — o DB já é a fonte da verdade.
 *
 *   ai_monthly_credits (e futuras features com isMonthlyReset = true)
 *     → Lê/escreve em feature_usages com period='YYYY-MM'.
 *       Deve ser incrementado explicitamente após cada uso.
 */
class UsageMeterService
{
    /**
     * Retorna o uso atual de uma feature para a empresa no período corrente.
     *
     * @param  string|null  $subscriptionId  Usado apenas para features mensais.
     */
    public function getCurrentUsage(string $entityId, FeatureKey $feature, ?string $subscriptionId = null): int
    {
        return match ($feature) {
            FeatureKey::MaxUsers         => $this->countUsers($entityId),
            FeatureKey::MaxPatients      => $this->countPatients($entityId),
            FeatureKey::MaxDoctors       => $this->countDoctors($entityId),
            FeatureKey::AiMonthlyCredits    => $this->getMonthlyUsage($entityId, $feature),
            FeatureKey::ApiMonthlyExamSends => $this->getMonthlyUsage($entityId, $feature),
            default                         => 0,
        };
    }

    /**
     * Incrementa o uso de uma feature com reset mensal (ex.: ai_monthly_credits).
     *
     * UNIQUE corrigido: (entity_id, feature, period) — subscription_id NÃO é parte
     * da chave única. O campo é atualizado para refletir a assinatura mais recente,
     * garantindo que renovações no mesmo mês não criem registros duplicados.
     */
    public function increment(string $entityId, FeatureKey $feature, string $subscriptionId, int $amount = 1): void
    {
        $period = $this->currentPeriod($feature);

        $usage = FeatureUsage::firstOrNew([
            'entity_id' => $entityId,
            'feature'   => $feature->value,
            'period'    => $period,
        ]);

        $usage->subscription_id = $subscriptionId;
        $usage->used             = ($usage->used ?? 0) + $amount;
        $usage->last_used_at     = now();
        $usage->save();
    }

    /**
     * Decrementa o uso de uma feature mensal. Nunca vai abaixo de zero.
     * Uso típico: reverter um incremento após rollback ou erro.
     */
    public function decrement(string $entityId, FeatureKey $feature, string $subscriptionId, int $amount = 1): void
    {
        $period = $this->currentPeriod($feature);

        FeatureUsage::where('entity_id', $entityId)
            ->where('feature', $feature->value)
            ->where('period', $period)
            ->where('used', '>', 0)
            ->decrement('used', $amount);
    }

    // ── Counters de domínio ───────────────────────────────────────────────────

    private function countUsers(string $entityId): int
    {
        return EntityUser::where('entity_id', $entityId)
            ->whereNull('deleted_at')
            ->count();
    }

    private function countPatients(string $entityId): int
    {
        return Patient::where('entity_id', $entityId)
            ->whereNull('deleted_at')
            ->count();
    }

    private function countDoctors(string $entityId): int
    {
        return Doctor::whereHas('entityUser', fn ($q) => $q->where('entity_id', $entityId))
            ->whereNull('deleted_at')
            ->count();
    }

    // ── Leitura de feature_usages ─────────────────────────────────────────────

    private function getMonthlyUsage(string $entityId, FeatureKey $feature): int
    {
        return (int) FeatureUsage::where('entity_id', $entityId)
            ->where('feature', $feature->value)
            ->where('period', now()->format('Y-m'))
            ->value('used');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Período correto para a feature:
     *   isMonthlyReset → 'YYYY-MM'
     *   permanente     → 'lifetime'
     */
    private function currentPeriod(FeatureKey $feature): string
    {
        return $feature->isMonthlyReset() ? now()->format('Y-m') : 'lifetime';
    }
}
