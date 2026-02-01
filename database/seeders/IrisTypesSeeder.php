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
            $name = mb_convert_case($irisType, MB_CASE_TITLE, 'UTF-8');

            IrisType::query()->updateOrCreate(
                [
                    'name' => $name,
                ],
                [
                    'name'   => $name,
                    'active' => true,
                ]
            );
        }
    }
}
