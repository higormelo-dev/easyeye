<?php

namespace Database\Factories;

use App\Models\{Covenant, Procedure, ProcedurePrice};
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProcedurePrice>
 */
class ProcedurePriceFactory extends Factory
{
    protected $model = ProcedurePrice::class;

    public function definition(): array
    {
        return [
            'entity_id'    => null,
            'covenant_id'  => Covenant::factory(),
            'procedure_id' => Procedure::factory(),
            'price'        => fake()->randomFloat(2, 50, 500),
            'charging'     => true,
            'active'       => true,
        ];
    }
}
