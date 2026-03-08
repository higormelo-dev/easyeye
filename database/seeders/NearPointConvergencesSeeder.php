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
            NearPointConvergence::query()->firstOrCreate(
                ['name' => $ppcType],
                ['active' => true]
            );
        }
    }
}
