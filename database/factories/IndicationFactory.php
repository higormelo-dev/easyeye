<?php

namespace Database\Factories;

use App\Models\Indication;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Indication>
 */
class IndicationFactory extends Factory
{
    protected $model = Indication::class;

    public function definition(): array
    {
        return [
            'entity_id'   => null,
            'description' => fake()->randomElement([
                'Suspeita de glaucoma',
                'Catarata madura',
                'Hipertensão ocular',
                'Diabetes mellitus',
                'Acompanhamento pós-operatório',
                'Avaliação de fundo de olho',
            ]),
            'active' => true,
        ];
    }

    public function inactive(): self
    {
        return $this->state(['active' => false]);
    }
}
