<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\IntegratorQueueHealthHistory;
use Illuminate\Console\Command;

/**
 * Expurgo diário do log de tendência da fila do integrador (pedido do
 * usuário: "prazo de dados poderia ficar 7 dias no saas"). Ver o doc comment
 * da migration `create_integrator_queue_health_history_table` pro cálculo de
 * volume que confirma isso como seguro contra uma frota grande de
 * integradores.
 *
 * Delete em massa (uma única query), não `chunkById`+delete por linha como
 * `ai:purge-feedbacks`: este é um log puro sem observers/efeitos colaterais
 * por linha, então não há motivo pra pagar o custo de hidratar cada model.
 */
class PruneIntegratorQueueHealthHistoryCommand extends Command
{
    protected $signature = 'queue-health:prune-history
        {--days=7 : Idade máxima em dias para manter}
        {--dry-run : Reporta o que seria deletado sem deletar}';

    protected $description = 'Apaga o histórico de queue-health do integrador com mais de N dias';

    public function handle(): int
    {
        $days   = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);
        $dryRun = (bool) $this->option('dry-run');

        $query = IntegratorQueueHealthHistory::query()->where('synced_at', '<', $cutoff);
        $count = $query->count();

        $this->info(($dryRun ? '[dry-run] ' : '') . "Histórico de queue-health > {$days} dias: {$count}");

        if ($count === 0 || $dryRun) {
            return self::SUCCESS;
        }

        $deleted = $query->delete();
        $this->info("Linhas deletadas: {$deleted}");

        return self::SUCCESS;
    }
}
