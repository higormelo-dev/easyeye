<?php

namespace Database\Seeders;

use App\Models\{Entity, ReportSetting};
use App\Services\ReportSettingService;
use Illuminate\Database\Seeder;

class AdoptGlobalReportSettingsForEntitiesSeeder extends Seeder
{
    public function run(): void
    {
        $clients = Entity::query()
            ->where('is_client', true)
            ->count();

        $globalTemplates = ReportSetting::query()
            ->whereNull('entity_id')
            ->where('status', 'published')
            ->where('active', true)
            ->count();

        $service = app(ReportSettingService::class);
        $result  = $service->adoptPublishedGlobalsForAllClientEntities();

        // F4d — Backfill idempotente: novos contents no global propagam para
        // cópias adoptadas anteriormente (sem afetar customizações da clínica).
        $syncStats = $service->syncAdoptedContentsWithGlobal();

        $adoptedAfter = ReportSetting::query()
            ->whereNotNull('entity_id')
            ->whereNotNull('source_setting_id')
            ->count();

        $expected = $clients * $globalTemplates;
        $pending  = max(0, $expected - $adoptedAfter);

        if ($this->command) {
            $this->command->info(sprintf(
                'Modelos globais adotados por padrão: +%d nesta execução | clínicas=%d | globais=%d | cópias=%d/%d | pendentes=%d',
                $result['adopted'],
                $result['entities'],
                $globalTemplates,
                $adoptedAfter,
                $expected,
                $pending,
            ));

            if ($result['adopted'] === 0 && $clients > 0 && $globalTemplates > 0 && $pending === 0) {
                $this->command->info('Sem adoções novas porque todas as clínicas já estão sincronizadas.');
            }

            if ($clients === 0) {
                $this->command->warn('Nenhuma clínica cliente encontrada no momento da execução.');
            }

            if ($globalTemplates === 0) {
                $this->command->warn('Nenhum modelo global publicado/ativo encontrado para adoção.');
            }

            $this->command->info(sprintf(
                'Backfill F4d: %d settings com mudanças | %d contents criados | %d contents atualizados',
                $syncStats['settings_synced'],
                $syncStats['contents_created'],
                $syncStats['contents_updated'],
            ));
        }
    }
}
