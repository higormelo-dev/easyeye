<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(EntityAndUserAdministratorSeeder::class);
        $this->call(CovenantsSeeder::class);
        $this->call(SkinTypesSeeder::class);
        $this->call(IrisTypesSeeder::class);
        $this->call(DataFakersSeeder::class);
    }
}
