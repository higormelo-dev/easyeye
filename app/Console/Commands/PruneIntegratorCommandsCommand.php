<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\IntegratorCommand;
use Illuminate\Console\Command;

/**
 * Expurgo do canal de comando do backend pro desktop (`integrator_commands`
 * — ver o doc comment da migration `create_integrator_commands_table`).
 * Sem isto, um integrador desativado/desinstalado acumula linhas terminais
 * para sempre, já que nada mais as apaga.
 *
 * Deliberadamente NUNCA apaga `pending` — mesmo além de `--days`: um comando
 * ainda não executado é trabalho real esperando o próximo poll do desktop,
 * não histórico. `acked_at` só é preenchido no ack (`completed`/`failed`),
 * então filtrar por ele já exclui `pending` (`NULL < cutoff` nunca é
 * verdadeiro em SQL) — o filtro explícito por `status` abaixo é só
 * documentação da invariante, não estritamente necessário.
 *
 * 30 dias (mais longo que os 7 dias de `queue-health:prune-history`): aquele
 * é um log de tendência puro; isto é o registro de uma ação real que o SaaS
 * pediu pro desktop fazer — vale mais janela pra suporte/auditoria
 * investigar um comando que falhou.
 */
class PruneIntegratorCommandsCommand extends Command
{
    protected $signature = 'integrator-commands:prune
        {--days=30 : Idade máxima em dias (por acked_at) para manter}
        {--dry-run : Reporta o que seria deletado sem deletar}';

    protected $description = 'Apaga comandos terminais (completed/failed) do canal de comando do integrador com mais de N dias';

    public function handle(): int
    {
        $days   = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);
        $dryRun = (bool) $this->option('dry-run');

        $query = IntegratorCommand::query()
            ->whereIn('status', ['completed', 'failed'])
            ->where('acked_at', '<', $cutoff);
        $count = $query->count();

        $this->info(($dryRun ? '[dry-run] ' : '') . "Comandos terminais > {$days} dias: {$count}");

        if ($count === 0 || $dryRun) {
            return self::SUCCESS;
        }

        $deleted = $query->delete();
        $this->info("Linhas deletadas: {$deleted}");

        return self::SUCCESS;
    }
}
