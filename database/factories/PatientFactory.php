<?php

namespace Database\Factories;

use App\Models\{Entity, IrisType, People, SkinType};
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
            'skin_id'     => SkinType::factory(),
            'iris_id'     => IrisType::factory(),
            'code'        => fake()->unique()->numerify('PAC####'),
            'card_number' => fake()->optional(0.6)->creditCardNumber(),
            'active'      => fake()->boolean(90),

        ];
    }
}
