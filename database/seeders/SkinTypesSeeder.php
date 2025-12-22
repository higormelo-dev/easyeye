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
        $skinTypes = [
            ['name' => 'Extremamente branca', 'active' => true],
            ['name' => 'Branca', 'active' => true],
            ['name' => 'Morena clara', 'active' => true],
            ['name' => 'Morena média', 'active' => true],
            ['name' => 'Morena escura', 'active' => true],
            ['name' => 'Negra', 'active' => true],
        ];

        foreach ($skinTypes as $skinType) {
            SkinType::query()->create($skinType);
        }
    }
}
