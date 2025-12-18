<?php

namespace Database\Factories;

use App\Models\{Entity, People};
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Model>
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
            'covenant_id' => '',
            'skin_id'     => '',
            'iris_id'     => '',
            'code'        => fake()->unique()->numerify('PAC####'),
            'card_number' => fake()->optional(0.6)->creditCardNumber(),
            'active'      => fake()->boolean(90),

        ];
    }
}
