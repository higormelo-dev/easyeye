<?php

namespace Database\Seeders;

use App\Models\AdditionType;
use Illuminate\Database\Seeder;

class AdditionTypesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $types = [
            '0.25', '0.50', '0.75', '1.00', '1.25', '1.50', '1.75',
            '2.00', '2.25', '2.50', '2.75', '3.00', '3.25', '3.50',
            '3.75', '4.00', '4.25', '4.50', '4.75', '5.00',
        ];

        foreach ($types as $type) {
            AdditionType::query()->firstOrCreate(
                ['name' => $type],
                ['active' => true],
            );
        }
    }
}
