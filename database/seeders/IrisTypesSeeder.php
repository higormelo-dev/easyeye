<?php

namespace Database\Seeders;

use App\Models\IrisType;
use Illuminate\Database\Seeder;

class IrisTypesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $irisTypes = [
            ['name' => 'Azul', 'active' => true],
            ['name' => 'Verde', 'active' => true],
            ['name' => 'Castanho', 'active' => true],
        ];

        foreach ($irisTypes as $irisType) {
            IrisType::query()->create($irisType);
        }
    }
}
