<?php

namespace Database\Seeders;

use App\Models\VisualAcuityType;
use Illuminate\Database\Seeder;

class VisualAcuityTypesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $types = [
            ['scale' => 0, 'name' => 'NO TEST'],
            ['scale' => 1, 'name' => '20/20'],
            ['scale' => 2, 'name' => '20/25'],
            ['scale' => 3, 'name' => '20/30'],
            ['scale' => 4, 'name' => '20/40'],
            ['scale' => 5, 'name' => '20/50'],
            ['scale' => 6, 'name' => '20/60'],
            ['scale' => 7, 'name' => '20/70'],
            ['scale' => 8, 'name' => '20/80'],
            ['scale' => 9, 'name' => '20/100'],
            ['scale' => 10, 'name' => '20/125'],
            ['scale' => 11, 'name' => '20/150'],
            ['scale' => 12, 'name' => '20/175'],
            ['scale' => 13, 'name' => '20/200'],
            ['scale' => 14, 'name' => '20/225'],
            ['scale' => 15, 'name' => '20/250'],
            ['scale' => 16, 'name' => '20/275'],
            ['scale' => 17, 'name' => '20/300'],
            ['scale' => 18, 'name' => '20/325'],
            ['scale' => 19, 'name' => '20/350'],
            ['scale' => 20, 'name' => '20/375'],
            ['scale' => 21, 'name' => '20/400'],
            ['scale' => 22, 'name' => 'CONTA DEDOS'],
            ['scale' => 23, 'name' => 'VULTOS'],
            ['scale' => 24, 'name' => 'PL'],
            ['scale' => 25, 'name' => 'SPL'],
        ];

        foreach ($types as $type) {
            VisualAcuityType::query()->firstOrCreate(
                ['name' => $type['name'], 'scale' => $type['scale']],
                ['active' => true],
            );
        }
    }
}
