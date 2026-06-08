<?php

namespace Database\Seeders;

use App\Models\CoverTestType;
use Illuminate\Database\Seeder;

class CoverTestTypesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $coverTestTypes = [
            ['abbreviation' => 'Orto', 'name' => 'Ortotropia'],
            ['abbreviation' => 'Ortho', 'name' => 'Ortoforia'],
            ['abbreviation' => 'Et', 'name' => 'Esotropia'],
            ['abbreviation' => 'Xt', 'name' => 'Exotropia'],
            ['abbreviation' => 'Ht', 'name' => 'Hipertropia'],
            ['abbreviation' => 'Hot', 'name' => 'Hipotropia'],
            ['abbreviation' => 'Ep', 'name' => 'Esoforia'],
            ['abbreviation' => 'Xp', 'name' => 'Exoforia'],
            ['abbreviation' => 'Hp', 'name' => 'Hiperforia'],
            ['abbreviation' => 'Hop', 'name' => 'Hipoforia'],
            ['abbreviation' => 'Ict', 'name' => 'Incyclotropia'],
            ['abbreviation' => 'Ect', 'name' => 'Excyclotropia'],
        ];

        foreach ($coverTestTypes as $coverTestType) {
            CoverTestType::query()->firstOrCreate(
                ['abbreviation' => $coverTestType['abbreviation'], 'name' => $coverTestType['name']],
                ['active' => true],
            );
        }
    }
}
