<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Stock\StockAlertService;
use Illuminate\Console\Command;

/**
 * Estoque baixo/lote vencendo — GAP fechado nesta revisão. Roda diária
 * (ver routes/console.php), avisa TODAS as clínicas com o módulo de
 * estoque habilitado via App\Models\Notice (mural do painel) —
 * ver docblock de App\Services\Stock\StockAlertService.
 */
class CheckStockAlertsCommand extends Command
{
    protected $signature = 'stock:check-alerts';

    protected $description = 'Verifica estoque baixo e lotes vencendo em todas as clínicas e gera avisos no painel';

    public function handle(StockAlertService $stockAlertService): int
    {
        $created = $stockAlertService->checkAllEntities();

        $this->info("Avisos de estoque (re)criados: {$created}");

        return self::SUCCESS;
    }
}
