<?php

namespace Database\Seeders;

use App\Models\ColorVisionType;
use Illuminate\Database\Seeder;

class ColorVisionTypesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $types = [
            'Normal', 'Protanopia', 'Deuteranopia', 'Tritanopia',
            'Protanomalia', 'Deuteranomalia', 'Tritanomalia', 'Acromatopsia',
            'Discromatopsia', 'Anopsia',
        ];

        foreach ($types as $type) {
            ColorVisionType::query()->firstOrCreate(
                ['name' => $type],
                ['active' => true],
            );
        }
    }
}
