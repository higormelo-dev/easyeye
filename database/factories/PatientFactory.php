<?php

namespace Database\Factories;

use App\Models\{Covenant, Entity, People};
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Patient>
 */
class PatientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'entity_id'   => Entity::factory(),
            'person_id'   => People::factory(),
            'covenant_id' => Covenant::factory(),
            'skin_id'     => null,
            'iris_id'     => null,
            'card_number' => fake()->optional(0.6)->creditCardNumber(),
            'active'      => fake()->boolean(90),
        ];
    }
}
