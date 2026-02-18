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
        $irisTypes = ['Azul', 'Verde', 'Castanho'];

        foreach ($irisTypes as $irisType) {
            IrisType::query()->firstOrCreate(
                ['name' => $irisType],
                ['active' => true]
            );
        }
    }
}
