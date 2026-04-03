<?php

namespace Database\Factories;

use App\Enums\FeatureKey;
use App\Models\{Entity, Subscription};
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\FeatureUsage>
 */
class FeatureUsageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'entity_id'       => Entity::factory(),
            'subscription_id' => Subscription::factory(),
            'feature'         => FeatureKey::AiMonthlyCredits->value,
            'period'          => now()->format('Y-m'),
            'used'            => $this->faker->numberBetween(0, 50),
        ];
    }

    public function forFeature(FeatureKey $feature): static
    {
        return $this->state(['feature' => $feature->value]);
    }

    public function forPeriod(string $period): static
    {
        return $this->state(['period' => $period]);
    }

    public function currentMonth(): static
    {
        return $this->state(['period' => now()->format('Y-m')]);
    }

    public function previousMonth(): static
    {
        return $this->state(['period' => now()->subMonth()->format('Y-m')]);
    }
}
