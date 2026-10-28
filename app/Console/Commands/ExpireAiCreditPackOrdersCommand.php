<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Billing\AiCreditPackCheckoutService;
use Illuminate\Console\Command;

/**
 * Pedidos de pacote de créditos de IA abertos pelo checkout e nunca pagos
 * (sem pagamento nem nova cobrança há mais de
 * ai.credit_purchases.pending_expiry_days dias — AI_CREDIT_PACK_PENDING_EXPIRY_DAYS,
 * padrão 7): pedido e fatura cancelados, cobranças canceladas no gateway
 * quando possível. Não ficam mais "em aberto" em Minha assinatura. Pagamento
 * que chegar depois ainda credita (o cliente pagou). Agendado diariamente.
 */
class ExpireAiCreditPackOrdersCommand extends Command
{
    protected $signature = 'ai:expire-credit-pack-orders
        {--dry-run : Só conta, sem cancelar}';

    protected $description = 'Descarta pedidos de pacote de créditos de IA pendentes sem pagamento há mais de N dias';

    public function handle(AiCreditPackCheckoutService $packs): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $packs->expireStaleOrders($dryRun);
        $days   = max(1, (int) config('ai.credit_purchases.pending_expiry_days', 7));

        $this->info($dryRun
            ? "[dry-run] Pedidos de créditos sem pagamento há mais de {$days} dias que seriam descartados: {$result['found']}"
            : "Pedidos de créditos sem pagamento há mais de {$days} dias descartados: {$result['expired']} de {$result['found']}");

        return self::SUCCESS;
    }
}
