<?php

namespace Database\Factories;

use App\Enums\BillingCycle;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Plan>
 */
class PlanFactory extends Factory
{
    public function definition(): array
    {
        $name = $this->faker->unique()->words(2, true);

        return [
            'name'          => ucwords($name),
            'slug'          => Str::slug($name),
            'description'   => $this->faker->sentence(),
            'price'         => $this->faker->randomFloat(2, 49, 999),
            'billing_cycle' => BillingCycle::Monthly,
            'active'        => true,
            'sort_order'    => $this->faker->numberBetween(1, 10),
        ];
    }

    public function inactive(): static
    {
        return $this->state(['active' => false]);
    }

    public function yearly(): static
    {
        return $this->state(['billing_cycle' => BillingCycle::Yearly]);
    }

    public function lifetime(): static
    {
        return $this->state(['billing_cycle' => BillingCycle::Lifetime, 'price' => 0]);
    }
}
