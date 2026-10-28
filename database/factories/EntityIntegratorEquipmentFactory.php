<?php

namespace Database\Factories;

use App\Models\EntityIntegrator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EntityIntegratorEquipment>
 */
class EntityIntegratorEquipmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'integrator_id' => EntityIntegrator::factory(),
            'name'          => fake()->words(2, true),
            'ip'            => fake()->unique()->ipv4(),
            'mac'           => fake()->unique()->macAddress(),
            'serial_number' => fake()->unique()->bothify('SN-####-???'),
            'active'        => true,
        ];
    }
}
