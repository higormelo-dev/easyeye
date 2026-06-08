<?php

namespace App\Services;

use App\Enums\SubscriptionStatus;
use App\Models\{Entity, Plan, Subscription, SubscriptionSetting};
use RuntimeException;

class TrialService
{
    /**
     * Inicia o período de trial para uma empresa recém-criada.
     * Deve ser chamado automaticamente via EntityObserver.
     *
     * @throws RuntimeException se não existir nenhum plano ativo.
     */
    public function startTrial(Entity $entity): Subscription
    {
        // Usa o plano de menor tier (sort_order) como base do trial
        $plan = Plan::active()->orderBy('sort_order')->first()
            ?? throw new RuntimeException('Nenhum plano ativo encontrado para iniciar o trial.');

        $trialDays = SubscriptionSetting::trialDays();

        return Subscription::create([
            'entity_id'     => $entity->id,
            'plan_id'       => $plan->id,
            'status'        => SubscriptionStatus::Trial,
            'trial_ends_at' => now()->addDays($trialDays),
            'starts_at'     => now(),
        ]);
    }

    /**
     * Inicia um trial manual (admin), opcionalmente em um plano específico.
     */
    public function startManualTrial(Entity $entity, ?Plan $plan = null, ?int $days = null): Subscription
    {
        $plan ??= Plan::active()->orderBy('sort_order')->first()
            ?? throw new RuntimeException('Nenhum plano ativo encontrado.');

        $days ??= SubscriptionSetting::trialDays();

        $this->cancelCurrent($entity);

        return Subscription::create([
            'entity_id'     => $entity->id,
            'plan_id'       => $plan->id,
            'status'        => SubscriptionStatus::Trial,
            'trial_ends_at' => now()->addDays($days),
            'starts_at'     => now(),
        ]);
    }

    /**
     * Expira trials vencidos. Deve ser rodado via scheduler (diário).
     * Retorna o número de trials expirados.
     */
    public function expireOverdueTrials(): int
    {
        $graceDays = SubscriptionSetting::gracePeriodDays();

        return Subscription::where('status', SubscriptionStatus::Trial)
            ->where('trial_ends_at', '<', now())
            ->update([
                'status'               => SubscriptionStatus::Expired,
                'grace_period_ends_at' => now()->addDays($graceDays),
            ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function cancelCurrent(Entity $entity): void
    {
        Subscription::forEntity($entity->id)
            ->whereIn('status', [SubscriptionStatus::Trial->value, SubscriptionStatus::Active->value])
            ->update([
                'status'       => SubscriptionStatus::Cancelled,
                'cancelled_at' => now(),
            ]);
    }
}
