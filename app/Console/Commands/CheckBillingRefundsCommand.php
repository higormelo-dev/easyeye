<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Billing\RefundService;
use Illuminate\Console\Command;

/**
 * Estornos pedidos pelo manager parados em "solicitado": conferidos no
 * gateway (Asaas: refunds[] de GET /v3/payments/{id}; Mercado Pago: GET
 * /v1/payments/{id}/refunds). Os sem resposta definitiva (timeout/5xx) em
 * minutos; os demais depois de BILLING_REFUND_EXPIRE_DAYS (padrão 30). Só
 * libera um novo pedido quando o gateway diz que o estorno não existe ou foi
 * negado; boleto aguardando a conta do pagador segue solicitado.
 */
class CheckBillingRefundsCommand extends Command
{
    protected $signature = 'billing:check-refunds
        {--limit= : Máximo de pedidos conferidos nesta execução}';

    protected $description = 'Confere no gateway os estornos parados em "solicitado" e libera os que não existem lá';

    public function handle(RefundService $refunds): int
    {
        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;
        $stats = $refunds->checkStale($limit);

        $this->info(__('billing_console.check_refunds.done', [
            'checked'   => array_sum($stats),
            'done'      => $stats['done'] ?? 0,
            'released'  => ($stats['cancelled'] ?? 0) + ($stats['failed'] ?? 0),
            'pending'   => $stats['pending'] ?? 0,
            'unchecked' => ($stats['unavailable'] ?? 0) + ($stats['unverifiable'] ?? 0),
        ]));

        return self::SUCCESS;
    }
}
