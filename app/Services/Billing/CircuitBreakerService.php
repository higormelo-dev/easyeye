<?php

namespace App\Services\Billing;

use App\Models\Billing\GatewayCircuitBreaker;
use Illuminate\Support\Facades\Log;

class CircuitBreakerService
{
    /** Número de falhas antes de abrir o circuito. */
    private int $threshold;

    /** Cooldown em segundos quando o circuito abre. */
    private int $cooldownSeconds;

    public function __construct(int $threshold = 5, int $cooldownSeconds = 120)
    {
        $this->threshold       = $threshold;
        $this->cooldownSeconds = $cooldownSeconds;
    }

    /**
     * Verifica se o circuito está aberto para o gateway (e opcionalmente para o tenant).
     * Circuito aberto → não tente o gateway, vá direto para o fallback.
     */
    public function isOpen(string $gatewayCode, ?string $entityId = null): bool
    {
        $breaker = $this->findBreaker($gatewayCode, $entityId)
            ?? $this->findBreaker($gatewayCode, null);

        return $breaker?->isOpen() ?? false;
    }

    /**
     * Registra sucesso: zera contador de falhas e fecha o circuito.
     */
    public function recordSuccess(string $gatewayCode, ?string $entityId = null): void
    {
        $breaker = $this->findBreaker($gatewayCode, $entityId);

        if ($breaker === null) {
            return;
        }

        $breaker->update([
            'state'             => 'closed',
            'failure_count'     => 0,
            'open_until'        => null,
            'last_trigger_type' => null,
        ]);
    }

    /**
     * Registra falha: incrementa contador e abre o circuito se limiar atingido.
     */
    public function recordFailure(string $gatewayCode, string $triggerType, ?string $entityId = null): void
    {
        $breaker = GatewayCircuitBreaker::firstOrCreate(
            ['gateway_code' => $gatewayCode, 'entity_id' => $entityId],
            ['state' => 'closed', 'failure_count' => 0, 'failure_threshold' => $this->threshold],
        );

        $newCount   = $breaker->failure_count + 1;
        $shouldOpen = $newCount >= ($breaker->failure_threshold ?: $this->threshold);

        $breaker->update([
            'failure_count'     => $newCount,
            'last_trigger_type' => $triggerType,
            'last_failure_at'   => now(),
            'state'             => $shouldOpen ? 'open' : $breaker->state,
            'open_until'        => $shouldOpen ? now()->addSeconds($this->cooldownSeconds) : $breaker->open_until,
        ]);

        if ($shouldOpen) {
            Log::warning('CircuitBreaker: circuito aberto.', [
                'gateway'    => $gatewayCode,
                'entity_id'  => $entityId,
                'failures'   => $newCount,
                'open_until' => now()->addSeconds($this->cooldownSeconds)->toIso8601String(),
                'trigger'    => $triggerType,
            ]);
        }
    }

    /**
     * Força a abertura do circuito manualmente (para manutenção).
     */
    public function forceOpen(string $gatewayCode, int $cooldownSeconds, ?string $entityId = null): void
    {
        GatewayCircuitBreaker::updateOrCreate(
            ['gateway_code' => $gatewayCode, 'entity_id' => $entityId],
            [
                'state'      => 'open',
                'open_until' => now()->addSeconds($cooldownSeconds),
            ],
        );
    }

    /**
     * Fecha o circuito manualmente e zera os contadores.
     */
    public function reset(string $gatewayCode, ?string $entityId = null): void
    {
        GatewayCircuitBreaker::where('gateway_code', $gatewayCode)
            ->where('entity_id', $entityId)
            ->update([
                'state'         => 'closed',
                'failure_count' => 0,
                'open_until'    => null,
            ]);
    }

    private function findBreaker(string $gatewayCode, ?string $entityId): ?GatewayCircuitBreaker
    {
        return GatewayCircuitBreaker::where('gateway_code', $gatewayCode)
            ->where('entity_id', $entityId)
            ->first();
    }
}
