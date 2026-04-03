<?php

namespace Database\Factories;

use App\Enums\SubscriptionStatus;
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
            'status'    => SubscriptionStatus::Active,
            'starts_at' => now(),
            'ends_at'   => now()->addMonth(),
        ];
    }

    // ── States ───────────────────────────────────────────────────────────────

    public function trial(int $days = 7): static
    {
        return $this->state([
            'status'        => SubscriptionStatus::Trial,
            'trial_ends_at' => now()->addDays($days),
            'ends_at'       => null,
        ]);
    }

    public function expiredTrial(): static
    {
        return $this->state([
            'status'        => SubscriptionStatus::Expired,
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

    public function inGracePeriod(): static
    {
        return $this->state([
            'status'               => SubscriptionStatus::Expired,
            'ends_at'              => now()->subDay(),
            'grace_period_ends_at' => now()->addDays(2),
        ]);
    }

    public function lifetime(): static
    {
        return $this->state([
            'status'  => SubscriptionStatus::Active,
            'ends_at' => null,
        ]);
    }
}
