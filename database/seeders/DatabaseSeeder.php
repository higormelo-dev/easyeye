<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

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
        $this->call(VisitTypesSeeder::class);
        $this->call(ExamTypesSeeder::class);
        $this->call(AdditionTypesSeeder::class);
        $this->call(SurgeryTypesSeeder::class);
        $this->call(CoverTestTypesSeeder::class);

        if (app()->environment(['local', 'testing'])) {
            $this->call(DataFakersSeeder::class);
        }
    }
}
