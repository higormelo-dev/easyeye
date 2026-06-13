<?php

namespace Database\Seeders;

use App\Models\Lense;
use Illuminate\Database\Seeder;

class LensesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $lensTypes = [
            ['name' => 'Monofocal', 'away' => true, 'near' => true],
            ['name' => 'Bifocal', 'away' => true, 'near' => true],
            ['name' => 'Multifocal / Progressiva', 'away' => true, 'near' => true],
            ['name' => 'Trifocal', 'away' => true, 'near' => true],
            ['name' => 'Ocupacional (Office)', 'away' => false, 'near' => true],
            ['name' => 'Orgânica (CR-39)', 'away' => true, 'near' => true],
            ['name' => 'Policarbonato', 'away' => true, 'near' => true],
            ['name' => 'Trivex', 'away' => true, 'near' => true],
            ['name' => 'Fotossensível (Photochromic)', 'away' => true, 'near' => true],
            ['name' => 'Polarizada', 'away' => true, 'near' => false],
            ['name' => 'Antirreflexo', 'away' => true, 'near' => true],
            ['name' => 'Filtro de luz azul', 'away' => true, 'near' => true],
        ];

        foreach ($lensTypes as $type) {
            Lense::query()->firstOrCreate(
                ['name' => $type['name']],
                ['away' => (bool) $type['away'], 'near' => (bool) $type['near'], 'active' => true],
            );
        }
    }
}
