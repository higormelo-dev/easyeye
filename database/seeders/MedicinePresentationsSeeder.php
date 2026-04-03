<?php

namespace Database\Seeders;

use App\Models\MedicinePresentation;
use Illuminate\Database\Seeder;

class MedicinePresentationsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $presentations = [
            'Colírio',
            'Pomada Oftálmica',
            'Comprimido',
            'Cápsula',
            'Injeção Intravítrea',
            'Gel Oftálmico',
            'Solução Oral',
            'Suspensão Oftálmica',
        ];

        foreach ($presentations as $name) {
            MedicinePresentation::query()->firstOrCreate(
                ['name' => $name, 'entity_id' => null],
                ['active' => true]
            );
        }
    }
}
