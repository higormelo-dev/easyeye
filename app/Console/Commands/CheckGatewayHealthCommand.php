<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Billing\GatewayHealthService;
use Illuminate\Console\Command;

/**
 * Health check real dos gateways ativos (diário): uma chamada leve e
 * autenticada à API — no Asaas GET /v3/myAccount/status/, que também evita a
 * desativação da chave por 3 meses sem uso. Chave recusada (401/403), de
 * outro ambiente ou ausente: alerta ao time. O resultado aparece em
 * Manager → Gateways.
 */
class CheckGatewayHealthCommand extends Command
{
    protected $signature = 'billing:gateway-health {--gateway= : Só este gateway (código)}';

    protected $description = 'Confere a credencial dos gateways na API (e mantém a chave do Asaas em uso)';

    public function handle(GatewayHealthService $health): int
    {
        $results = $health->checkAll($this->option('gateway') ? (string) $this->option('gateway') : null);

        if ($results === []) {
            $this->warn(__('billing_console.gateway_health.none'));

            return self::SUCCESS;
        }

        $this->table(
            [__('billing_console.gateway_health.col_gateway'), __('billing_console.gateway_health.col_status'), __('billing_console.gateway_health.col_message')],
            collect($results)->map(fn ($dto, $code) => [$code, $dto->toArray()['status'], (string) $dto->message])->values()->all(),
        );

        return collect($results)->every(fn ($dto) => $dto->healthy) ? self::SUCCESS : self::FAILURE;
    }
}
