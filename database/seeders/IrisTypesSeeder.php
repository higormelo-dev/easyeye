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

        $i = 1;

        foreach ($irisTypes as $irisType) {
            $name = mb_convert_case($irisType, MB_CASE_TITLE, 'UTF-8');

            IrisType::query()->updateOrCreate(
                [
                    'name' => $name,
                ],
                [
                    'code'   => 'ITP-' . str_pad($i++, 10, '0', STR_PAD_LEFT),
                    'name'   => $name,
                    'active' => true,
                ]
            );
        }
    }
}
