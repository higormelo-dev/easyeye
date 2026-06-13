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

        foreach ($skinTypes as $skinType) {
            SkinType::query()->firstOrCreate(
                ['name' => $skinType],
                ['active' => true],
            );
        }
    }
}
