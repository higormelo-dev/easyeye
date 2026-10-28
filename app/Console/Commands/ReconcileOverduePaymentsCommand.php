<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Billing\GatewayPaymentReconciler;
use Illuminate\Console\Command;

/**
 * Conciliação diária leve: faturas vencidas com cobrança no gateway são
 * conferidas na API (Asaas: GET /v3/payments/{id}); pagamento encontrado
 * sem webhook processado (webhook atrasado, fila pausada) é aplicado pelo
 * mesmo caminho do webhook. Teto por execução (BILLING_RECONCILE_MAX_PER_RUN),
 * pausa entre consultas (BILLING_RECONCILE_PAUSE_MS) e parada no 429.
 */
class ReconcileOverduePaymentsCommand extends Command
{
    protected $signature = 'billing:reconcile-overdue
        {--limit= : Máximo de faturas conferidas nesta execução}
        {--dry-run : Só conta as faturas que seriam conferidas, sem chamar o gateway}';

    protected $description = 'Confere no gateway as faturas vencidas e aplica pagamentos cujo webhook não chegou';

    public function handle(GatewayPaymentReconciler $reconciler): int
    {
        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;
        $stats = $reconciler->run($limit, null, (bool) $this->option('dry-run'));

        if ($this->option('dry-run')) {
            $this->info(__('billing_console.reconcile_overdue.dry_run', ['count' => $stats['checked']]));

            return self::SUCCESS;
        }

        $this->info(__('billing_console.reconcile_overdue.done', ['checked' => $stats['checked'], 'applied' => $stats['applied']]));

        if ($stats['rate_limited']) {
            $this->warn(__('billing_console.reconcile_overdue.rate_limited'));
        }

        return self::SUCCESS;
    }
}
