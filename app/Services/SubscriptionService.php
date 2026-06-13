<?php

namespace App\Services;

use App\Enums\{BillingCycle, SubscriptionStatus};
use App\Models\{Entity, Plan, Subscription, SubscriptionSetting};
use DateInterval;

class SubscriptionService
{
    /**
     * Ativa uma assinatura paga para a empresa.
     * Cancela qualquer assinatura anterior (trial ou paga).
     */
    public function activate(Entity $entity, Plan $plan, BillingCycle $cycle = BillingCycle::Monthly): Subscription
    {
        $this->cancelCurrent($entity);

        return Subscription::create([
            'entity_id' => $entity->id,
            'plan_id'   => $plan->id,
            'status'    => SubscriptionStatus::Active,
            'starts_at' => now(),
            'ends_at'   => $cycle->addToNow(),
        ]);
    }

    /**
     * Cancela a assinatura ativa da empresa.
     * O acesso é mantido até o fim do período de graça.
     */
    public function cancel(Entity $entity): ?Subscription
    {
        $subscription = $this->getCurrent($entity);

        if (! $subscription) {
            return null;
        }

        $graceDays = SubscriptionSetting::gracePeriodDays();

        $subscription->update([
            'status'               => SubscriptionStatus::Cancelled,
            'cancelled_at'         => now(),
            'grace_period_ends_at' => now()->addDays($graceDays),
        ]);

        return $subscription->fresh();
    }

    /**
     * Renova a assinatura ativa por mais um ciclo.
     */
    public function renew(Subscription $subscription): Subscription
    {
        $cycle = $subscription->plan->billing_cycle;

        $newEndsAt = $cycle === BillingCycle::Lifetime
            ? null
            : ($subscription->ends_at ?? now())->add(
                match ($cycle) {
                    BillingCycle::Monthly => new DateInterval('P1M'),
                    BillingCycle::Yearly  => new DateInterval('P1Y'),
                    default               => new DateInterval('P1M'),
                },
            );

        $subscription->update([
            'status'  => SubscriptionStatus::Active,
            'ends_at' => $newEndsAt,
        ]);

        return $subscription->fresh();
    }

    /**
     * Expira assinaturas pagas vencidas. Para uso no scheduler.
     */
    public function expireOverdue(): int
    {
        $graceDays = SubscriptionSetting::gracePeriodDays();
        $count     = 0;

        // Iterate individually so Eloquent observers fire for each record.
        Subscription::where('status', SubscriptionStatus::Active)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<', now())
            ->each(function (Subscription $subscription) use ($graceDays, &$count): void {
                $subscription->update([
                    'status'               => SubscriptionStatus::Expired,
                    'grace_period_ends_at' => now()->addDays($graceDays),
                ]);

                $count++;
            });

        return $count;
    }

    /**
     * Retorna a assinatura acessível atual (trial ou ativa) da empresa.
     */
    public function getCurrent(Entity $entity): ?Subscription
    {
        return Subscription::forEntity($entity->id)
            ->accessible()
            ->with('plan.features')
            ->first();
    }

    /**
     * Verifica se a empresa tem acesso ao sistema.
     */
    public function hasAccess(Entity $entity): bool
    {
        return Subscription::forEntity($entity->id)
            ->accessible()
            ->exists();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function cancelCurrent(Entity $entity): void
    {
        // Iterate individually so Eloquent observers (SubscriptionObserver) fire for each record.
        // Mass update via ::update() bypasses observers entirely.
        Subscription::forEntity($entity->id)
            ->whereIn('status', [SubscriptionStatus::Trial->value, SubscriptionStatus::Active->value])
            ->each(function (Subscription $subscription): void {
                $subscription->update([
                    'status'       => SubscriptionStatus::Cancelled,
                    'cancelled_at' => now(),
                ]);
            });
    }
}
