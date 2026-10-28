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
            // Chave em MAIÚSCULO + entity null: o model tem HasUppercaseName
            // (mutator salva uppercase) — buscar por Title Case nunca acha o
            // registro salvo e cada rodada duplicaria o catálogo (mesmo
            // gotcha corrigido no VisitTypesSeeder).
            ColorVisionType::query()->firstOrCreate(
                ['entity_id' => null, 'name' => mb_strtoupper($type, 'UTF-8')],
                ['active' => true],
            );
        }
    }
}
