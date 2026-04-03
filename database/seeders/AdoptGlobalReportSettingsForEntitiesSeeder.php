<?php

namespace Database\Seeders;

use App\Services\ReportSettingService;
use Illuminate\Database\Seeder;

class AdoptGlobalReportSettingsForEntitiesSeeder extends Seeder
{
    public function run(): void
    {
        $result = app(ReportSettingService::class)
            ->adoptPublishedGlobalsForAllClientEntities();

        if ($this->command) {
            $this->command->info(sprintf(
                'Modelos globais adotados por padrão: %d templates em %d clínicas.',
                $result['adopted'],
                $result['entities'],
            ));
        }
    }
}

