<?php

namespace App\Services;

use App\Enums\{BillingCycle, SubscriptionStatus};
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
     * Inicia um trial manual (admin/cadastro), opcionalmente em um plano
     * específico. O ciclo escolhido no site (ex.: anual) e o valor dele hoje
     * ficam guardados para a contratação seguir a mesma modalidade; ciclo que
     * o plano não oferece cai no ciclo padrão do plano.
     */
    public function startManualTrial(Entity $entity, ?Plan $plan = null, ?int $days = null, ?BillingCycle $cycle = null): Subscription
    {
        $plan ??= Plan::active()->orderBy('sort_order')->first()
            ?? throw new RuntimeException('Nenhum plano ativo encontrado.');

        $days ??= SubscriptionSetting::trialDays();
        $cycle = $cycle && $plan->offersCycle($cycle) ? $cycle : $plan->defaultCycle();

        $this->cancelCurrent($entity);

        return Subscription::create([
            'entity_id'     => $entity->id,
            'plan_id'       => $plan->id,
            'billing_cycle' => $cycle,
            'amount'        => $cycle ? $plan->priceFor($cycle) : null,
            'status'        => SubscriptionStatus::Trial,
            'trial_ends_at' => now()->addDays($days),
            'starts_at'     => now(),
        ]);
    }

    /**
     * Marca como expirados os trials vencidos (scheduler, diário). O acesso
     * já terminou em `trial_ends_at` — não há período de graça; aqui só o
     * status acompanha. Retorna o número de trials expirados.
     */
    public function expireOverdueTrials(): int
    {
        return Subscription::where('status', SubscriptionStatus::Trial)
            ->where('trial_ends_at', '<', now())
            ->update(['status' => SubscriptionStatus::Expired]);
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
