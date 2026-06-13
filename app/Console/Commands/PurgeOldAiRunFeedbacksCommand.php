<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\AI\Models\AiRunFeedback;
use Illuminate\Console\Command;

/**
 * Onda 4, C6 — Purge LGPD de feedbacks com mais de 90 dias.
 *
 * `ai_run_feedbacks` pode conter PHI nas notas livres. LGPD Art. 16: dados não
 * devem ser conservados além do necessário. 90 dias é janela suficiente para
 * análise agregada de qualidade (futura Onda de admin) antes do descarte.
 *
 * Schedule: semanal aos domingos 03:00. Suporta --dry-run para auditoria.
 */
class PurgeOldAiRunFeedbacksCommand extends Command
{
    protected $signature = 'ai:purge-feedbacks
        {--days=90 : Idade máxima em dias para manter}
        {--dry-run : Reporta o que seria deletado sem deletar}';

    protected $description = 'Apaga feedbacks de IA com mais de N dias (LGPD)';

    public function handle(): int
    {
        $days   = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);
        $dryRun = (bool) $this->option('dry-run');

        $query = AiRunFeedback::query()->where('created_at', '<', $cutoff);
        $count = (int) $query->count();

        $this->info(($dryRun ? '[dry-run] ' : '') . "Feedbacks > {$days} dias: {$count}");

        if ($count === 0 || $dryRun) {
            return self::SUCCESS;
        }

        $deleted = 0;
        $query->chunkById(500, function ($chunk) use (&$deleted): void {
            foreach ($chunk as $feedback) {
                $feedback->delete();
                $deleted++;
            }
        });

        $this->info("Feedbacks deletados: {$deleted}");

        return self::SUCCESS;
    }
}
