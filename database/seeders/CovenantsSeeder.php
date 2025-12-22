<?php

namespace Database\Seeders;

use App\Models\Covenant;
use Illuminate\Database\Seeder;

class CovenantsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Covenant::create([
            'name'   => 'Particular',
            'color'  => '#000000',
            'table'  => true,
            'active' => true,
        ]);
        Covenant::create([
            'name'   => 'Alice',
            'color'  => '#9b01a4',
            'table'  => false,
            'active' => true,
        ]);
        Covenant::create([
            'name'   => 'Allianz Saúde',
            'color'  => '#16537e',
            'table'  => false,
            'active' => true,
        ]);
        Covenant::create([
            'name'   => 'Amil',
            'color'  => '#0b5394',
            'table'  => false,
            'active' => true,
        ]);
        Covenant::create([
            'name'   => 'SulAmérica Saúde',
            'color'  => '#0a365e',
            'table'  => false,
            'active' => true,
        ]);
        Covenant::create([
            'name'   => 'Bradesco Saúde',
            'color'  => '#cc0000',
            'table'  => false,
            'active' => true,
        ]);
        Covenant::create([
            'name'   => 'Golden Cross',
            'color'  => '#f1c232',
            'table'  => false,
            'active' => true,
        ]);
        Covenant::create([
            'name'   => 'NotreDame Intermédica',
            'color'  => '#e69138',
            'table'  => false,
            'active' => true,
        ]);
        Covenant::create([
            'name'   => 'Porto Seguro Saúde',
            'color'  => '#3d85c6',
            'table'  => false,
            'active' => true,
        ]);
        Covenant::create([
            'name'   => 'Hapvida',
            'color'  => '#6fa8dc',
            'table'  => false,
            'active' => true,
        ]);
        Covenant::create([
            'name'   => 'Prevent Senior',
            'color'  => '#031526',
            'table'  => false,
            'active' => true,
        ]);
    }
}
