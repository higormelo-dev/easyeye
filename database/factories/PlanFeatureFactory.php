<?php

namespace Database\Factories;

use App\Enums\FeatureKey;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PlanFeature>
 */
class PlanFeatureFactory extends Factory
{
    public function definition(): array
    {
        $feature = $this->faker->randomElement(FeatureKey::cases());

        return [
            'plan_id' => Plan::factory(),
            'feature' => $feature->value,
            'value'   => $feature->isBoolean()
                ? $this->faker->randomElement(['0', '1'])
                : (string) $this->faker->numberBetween(0, 100),
        ];
    }

    /** Feature booleana habilitada. */
    public function enabled(FeatureKey $feature): static
    {
        return $this->state(['feature' => $feature->value, 'value' => '1']);
    }

    /** Feature booleana desabilitada. */
    public function disabled(FeatureKey $feature): static
    {
        return $this->state(['feature' => $feature->value, 'value' => '0']);
    }

    /** Limite numérico específico (0 = ilimitado). */
    public function limit(FeatureKey $feature, int $value): static
    {
        return $this->state(['feature' => $feature->value, 'value' => (string) $value]);
    }
}
