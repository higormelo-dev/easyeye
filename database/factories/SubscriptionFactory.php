<?php

namespace Database\Factories;

use App\Enums\{SubscriptionBillingMode, SubscriptionStatus};
use App\Models\{Entity, Plan};
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Subscription>
 */
class SubscriptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'entity_id' => Entity::factory(),
            'plan_id'   => Plan::factory(),
            // Assinatura paga comum (cobrança automática); `gateway()` liga a
            // recorrência no gateway, `complimentary()` a cortesia.
            'billing_mode' => SubscriptionBillingMode::Gateway,
            'status'       => SubscriptionStatus::Active,
            'starts_at'    => now(),
            'ends_at'      => now()->addMonth(),
        ];
    }

    // ── States ───────────────────────────────────────────────────────────────

    public function trial(int $days = 7): static
    {
        return $this->state([
            'status'        => SubscriptionStatus::Trial,
            'billing_mode'  => null,
            'trial_ends_at' => now()->addDays($days),
            'ends_at'       => null,
        ]);
    }

    public function expiredTrial(): static
    {
        return $this->state([
            'status'        => SubscriptionStatus::Expired,
            'billing_mode'  => null,
            'trial_ends_at' => now()->subDays(2),
            'ends_at'       => null,
        ]);
    }

    public function active(): static
    {
        return $this->state([
            'status'  => SubscriptionStatus::Active,
            'ends_at' => now()->addMonth(),
        ]);
    }

    public function expired(): static
    {
        return $this->state([
            'status'  => SubscriptionStatus::Expired,
            'ends_at' => now()->subDay(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state([
            'status'       => SubscriptionStatus::Cancelled,
            'cancelled_at' => now()->subHour(),
            'ends_at'      => now()->subHour(),
        ]);
    }

    /** Assinatura antiga liberada sem data de término. */
    public function lifetime(): static
    {
        return $this->state([
            'status'  => SubscriptionStatus::Active,
            'ends_at' => null,
        ]);
    }

    /** Cobrança automática por gateway com recorrência criada lá. */
    public function gateway(string $gateway = 'asaas'): static
    {
        return $this->state([
            'billing_mode'            => SubscriptionBillingMode::Gateway,
            'gateway'                 => $gateway,
            'gateway_subscription_id' => 'sub_' . $this->faker->unique()->numerify('########'),
            'gateway_customer_id'     => 'cus_' . $this->faker->numerify('########'),
            'billing_state'           => 'paid',
        ]);
    }

    public function complimentary(): static
    {
        return $this->state(['billing_mode' => SubscriptionBillingMode::Complimentary]);
    }
}
