<?php

namespace Database\Seeders;

use App\Models\NearPointConvergence;
use Illuminate\Database\Seeder;

class NearPointConvergencesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $ppcTypes = ['Normal', 'Próximo', 'Remoto', 'Afastado', 'Reduzido', 'Ausente'];

        foreach ($ppcTypes as $ppcType) {
            // Chave em MAIÚSCULO + entity null — ver nota no
            // ColorVisionTypesSeeder (gotcha do HasUppercaseName).
            NearPointConvergence::query()->firstOrCreate(
                ['entity_id' => null, 'name' => mb_strtoupper($ppcType, 'UTF-8')],
                ['active' => true],
            );
        }
    }
}
