<?php

namespace Database\Factories;

use App\Models\Procedure;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Procedure>
 */
class ProcedureFactory extends Factory
{
    protected $model = Procedure::class;

    public function definition(): array
    {
        return [
            'entity_id' => null,
            'code'      => (string) fake()->unique()->numberBetween(10000000, 99999999),
            'name'      => fake()->randomElement([
                'Facoemulsificação',
                'Capsulotomia YAG',
                'Trabeculoplastia',
                'Vitrectomia',
                'Mapeamento de retina',
                'Tonometria de aplanação',
                'Paquimetria ultrassônica',
            ]),
            'nomo_binocular' => 0,
            'treatment'      => 0,
            'active'         => true,
        ];
    }

    public function inactive(): self
    {
        return $this->state(['active' => false]);
    }
}
