<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EntityIntegrator>
 */
class EntityIntegratorFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name'   => 'Equipment ' . $this->faker->unique()->country(),
            'token'  => $this->faker->unique()->sha256(),
            'ip'     => $this->faker->unique()->ipv4(),
            'mac'    => $this->faker->unique()->macAddress(),
            'active' => $this->faker->boolean(80),
        ];
    }
}
