<?php

namespace Database\Seeders;

use App\Models\SkinType;
use Illuminate\Database\Seeder;

class SkinTypesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $skinTypes = ['Extremamente branca', 'Branca', 'Morena clara', 'Morena média', 'Morena escura', 'Negra'];

        $i = 1;

        foreach ($skinTypes as $skinType) {
            $name = mb_convert_case($skinType, MB_CASE_TITLE, 'UTF-8');

            SkinType::query()->updateOrCreate(
                [
                    'name' => $name,
                ],
                [
                    'code'   => 'STP-' . str_pad($i++, 10, '0', STR_PAD_LEFT),
                    'name'   => $name,
                    'active' => true,
                ]
            );
        }
    }
}
