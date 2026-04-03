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
        $this->call(SubscriptionSettingSeeder::class);
        $this->call(PlanSeeder::class);
        $this->call(CovenantsSeeder::class);
        $this->call(SkinTypesSeeder::class);
        $this->call(IrisTypesSeeder::class);
        $this->call(VisitTypesSeeder::class);
        $this->call(ExamTypesSeeder::class);
        $this->call(AdditionTypesSeeder::class);
        $this->call(SurgeryTypesSeeder::class);
        $this->call(CoverTestTypesSeeder::class);
        $this->call(ColorVisionTypesSeeder::class);
        $this->call(VisualAcuityTypesSeeder::class);
        $this->call(LensesSeeder::class);
        $this->call(NearPointConvergencesSeeder::class);
        $this->call(MedicinePresentationsSeeder::class);
        $this->call(Cid10CodesSeeder::class);
        $this->call(ReportSettingSeeder::class);
        $this->call(ReportSettingContentSeeder::class);
        $this->call(ReportSettingVariableSeeder::class);

        if (app()->environment(['local', 'testing'])) {
            $this->call(DataFakersSeeder::class);
        }

        // Executa por último para considerar clínicas eventualmente criadas
        // por seeders de dados faker.
        $this->call(AdoptGlobalReportSettingsForEntitiesSeeder::class);
    }
}
