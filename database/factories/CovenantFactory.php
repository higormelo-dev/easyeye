<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Covenant>
 */
class CovenantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'entity_id' => null,
            'name'      => fake()->words(2, true),
            'color'     => fake()->hexColor(),
            'table'     => false,
            'active'    => true,
        ];
    }
}
