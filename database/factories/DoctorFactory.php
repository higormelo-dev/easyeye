<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Doctor>
 */
class DoctorFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'record'           => fake()->unique()->numberBetween(1, 1000000),
            'record_specialty' => fake()->unique()->numberBetween(1, 1000000),
            'color'            => fake()->unique()->hexColor(),
            'partner'          => fake()->boolean(20),
            'active'           => fake()->boolean(80),
            'observation'      => fake()->optional(0.3)->text(),
        ];
    }
}
