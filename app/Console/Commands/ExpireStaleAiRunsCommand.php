<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\AiRunExecutionService;
use App\Enums\AI\AiRunStatus;
use Illuminate\Console\Command;

/**
 * Runs de IA presos em Pending/Reserved/Running (worker parado, fila errada,
 * job perdido no Redis) mantêm créditos RESERVADOS indefinidamente — nada os
 * liberava além do cancel manual do usuário. Marca como Failed e devolve a
 * reserva via compensateFailedRun (idempotente, mesma rotina do job).
 *
 * Schedule: horário. --hours define a idade mínima; --dry-run audita.
 */
class ExpireStaleAiRunsCommand extends Command
{
    protected $signature = 'ai:expire-stale-runs
        {--hours=6 : Idade mínima (horas) desde a última atualização}
        {--dry-run : Reporta o que seria expirado sem alterar nada}';

    protected $description = 'Expira runs de IA presos em pending/reserved/running e libera a reserva de créditos';

    public function handle(AiRunExecutionService $executionService): int
    {
        $hours  = max(1, (int) $this->option('hours'));
        $cutoff = now()->subHours($hours);
        $dryRun = (bool) $this->option('dry-run');

        $query = AiRun::query()
            ->withoutGlobalScopes()
            ->whereIn('status', [
                AiRunStatus::Pending->value,
                AiRunStatus::Reserved->value,
                AiRunStatus::Running->value,
            ])
            ->where('updated_at', '<', $cutoff);

        $count = (int) $query->count();
        $this->info(($dryRun ? '[dry-run] ' : '') . "Runs presos > {$hours}h: {$count}");

        if ($count === 0 || $dryRun) {
            return self::SUCCESS;
        }

        $expired = 0;
        $query->orderBy('updated_at')->chunkById(100, function ($chunk) use ($executionService, &$expired): void {
            foreach ($chunk as $run) {
                $executionService->compensateFailedRun(
                    $run,
                    'Expirado automaticamente: execução não concluída dentro do prazo.',
                );
                $expired++;
            }
        });

        $this->info("Runs expirados (reserva liberada): {$expired}");

        return self::SUCCESS;
    }
}
