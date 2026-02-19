<?php

namespace Database\Seeders;

use App\Models\SurgeryType;
use Illuminate\Database\Seeder;

class SurgeryTypesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $surgeries = [
            1 => [
                'Facoemulsificação com implante de lente intraocular (LIO)',
                'Extração extracapsular de catarata (EECC)',
                'Extração intracapsular de catarata (EICC)',
                'Implante secundário de lente intraocular',
                'Reposicionamento de lente intraocular',
                'Troca de lente intraocular',
                'Fixação escleral de lente intraocular',
                'Capsulotomia posterior com YAG laser',
            ],
        ];

        foreach ($surgeries as $surgery) {
            SurgeryType::query()->firstOrCreate(
                ['name' => $surgery],
                ['active' => true]
            );
        }
    }
}
