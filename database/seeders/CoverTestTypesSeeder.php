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
            // Resultado normal SEMPRE reconhecível e primeiro da lista
            // (ordenação normal-first em buildFormProps).
            ['abbreviation' => 'Ortho', 'name' => 'NORMAL (ORTOFORIA)'],
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
            // Nome em MAIÚSCULO + entity null: o banco guarda uppercase —
            // buscar por Title Case duplicaria o catálogo a cada rodada
            // (mesmo gotcha corrigido no VisitTypesSeeder).
            CoverTestType::query()->firstOrCreate(
                ['entity_id' => null, 'name' => mb_strtoupper($coverTestType['name'], 'UTF-8')],
                ['abbreviation' => $coverTestType['abbreviation'], 'active' => true],
            );
        }
    }
}
