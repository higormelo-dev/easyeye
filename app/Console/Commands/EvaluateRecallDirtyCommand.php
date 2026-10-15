<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Recall\Services\RecallDirtyDrainer;
use Illuminate\Console\Command;

/**
 * Drena a outbox do recall: reavalia os pacientes que um ato clínico marcou (prontuário, exame, agenda,
 * medicação, cadastro, condição). Roda a cada minuto; a conciliação noturna cobre o que o evento não pega.
 */
class EvaluateRecallDirtyCommand extends Command
{
    protected $signature = 'recall:evaluate-dirty {--limit=1000 : máximo de pacientes por execução}';

    protected $description = 'Reavalia os retornos dos pacientes marcados como alterados (outbox do recall)';

    public function handle(RecallDirtyDrainer $drainer): int
    {
        $stats = $drainer->drain(max(1, (int) $this->option('limit')));

        $this->info("Reivindicados: {$stats['claimed']} · avaliados: {$stats['evaluated']} · ignorados (plano): {$stats['skipped']} · falhas: {$stats['failed']}");

        return self::SUCCESS;
    }
}
