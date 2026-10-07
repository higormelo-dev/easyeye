<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\MedicalRecords\MedicalRecordDraftService;
use Illuminate\Console\Command;

/**
 * Limpeza dos rascunhos do prontuário (autosave) parados há mais de N dias
 * (padrão 7). Rascunho é dado de saúde que o médico não salvou — não fica
 * guardado além do necessário (LGPD art. 16). Agendado diariamente.
 */
class PruneMedicalRecordDraftsCommand extends Command
{
    protected $signature = 'medical-records:prune-drafts
        {--days=' . MedicalRecordDraftService::RETENTION_DAYS . ' : Idade máxima (dias desde a última gravação)}';

    protected $description = 'Apaga rascunhos de prontuário (autosave) antigos';

    public function handle(MedicalRecordDraftService $drafts): int
    {
        $days    = max(1, (int) $this->option('days'));
        $deleted = $drafts->prune($days);

        $this->info("Rascunhos de prontuário com mais de {$days} dias apagados: {$deleted}");

        return self::SUCCESS;
    }
}
