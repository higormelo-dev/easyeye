<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\DTOs\Billing\GatewayHealthDTO;
use App\Exceptions\Billing\GatewayIntegrationException;
use App\Models\Billing\Gateway;
use Throwable;

/**
 * Health check dos gateways ativos (billing:gateway-health, diário): grava o
 * resultado em gateways.health (Manager → Gateways mostra) e alerta o time
 * quando a credencial precisa de atenção — recusada (401/403: inválida,
 * expirada, desabilitada por falta de uso), de outro ambiente ou ausente.
 * No Asaas a chamada também mantém a chave em uso (3 meses sem uso =
 * desabilitada — https://docs.asaas.com/docs/chaves-de-api).
 */
class GatewayHealthService
{
    public function __construct(
        private readonly GatewayRegistry $registry,
        private readonly GatewayAlertService $alerts,
    ) {
    }

    /** @return array<string, GatewayHealthDTO> */
    public function checkAll(?string $only = null): array
    {
        $results = [];

        Gateway::query()
            ->where('active', true)
            ->when($only, fn ($q) => $q->where('code', $only))
            ->orderBy('priority')
            ->get()
            ->filter(fn (Gateway $gateway) => $this->registry->has((string) $gateway->code))
            ->each(function (Gateway $gateway) use (&$results): void {
                $results[(string) $gateway->code] = $this->check($gateway);
            });

        return $results;
    }

    public function check(Gateway $gateway): GatewayHealthDTO
    {
        try {
            $health = $this->registry->get((string) $gateway->code)->healthCheck();
        } catch (GatewayIntegrationException $e) {
            $health = new GatewayHealthDTO(healthy: false, message: $e->getMessage(), status: $e->isRateLimit() ? GatewayHealthDTO::STATUS_RATE_LIMITED : GatewayHealthDTO::STATUS_UNREACHABLE);
        } catch (Throwable $e) {
            report($e);
            $health = new GatewayHealthDTO(healthy: false, message: mb_substr($e->getMessage(), 0, 300), status: GatewayHealthDTO::STATUS_UNREACHABLE);
        }

        $gateway->forceFill(['health' => $health->toArray(), 'health_checked_at' => now()])->saveQuietly();

        // 401/403 já viram alerta na própria chamada (credential_rejected).
        if ($health->needsAttention() && $health->status !== GatewayHealthDTO::STATUS_AUTH_ERROR) {
            $this->alerts->alert(
                gateway: (string) $gateway->code,
                kind: $health->status === GatewayHealthDTO::STATUS_ENVIRONMENT_MISMATCH ? 'environment_mismatch' : 'health_failed',
                params: ['status' => (string) $health->status, 'http' => (string) $health->httpStatus, 'detail' => mb_substr((string) $health->message, 0, 300)],
                message: "Health check do gateway {$gateway->code} falhou ({$health->status}): " . mb_substr((string) $health->message, 0, 300),
                level: 'critical',
                throttleKey: (string) $health->status,
                throttleMinutes: 60 * 20,
            );
        }

        return $health;
    }
}
