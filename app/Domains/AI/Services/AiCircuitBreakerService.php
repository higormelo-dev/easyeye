<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\AI\Contracts\AiCircuitBreakerInterface;
use App\Domains\AI\Models\AiCircuitBreaker;
use App\Enums\AI\AiProvider;
use Illuminate\Support\Facades\Log;

/**
 * Circuit breaker simples para providers LLM.
 *
 * Estados:
 *   closed → opera normalmente.
 *   open   → bloqueia chamadas até open_until expirar.
 *
 * Após failure_threshold falhas consecutivas, abre o circuito por cooldown_seconds.
 * Operações de sucesso resetam o contador. Espelha o padrão de
 * App\Services\Billing\CircuitBreakerService para consistência arquitetural.
 *
 * Granularidade dupla: pode existir breaker global (entity_id=null) para falhas
 * sistêmicas (chave expirada, provider fora do ar) e breaker por entity (entity_id
 * preenchido) para falhas localizadas. A verificação tenta entity primeiro, depois global.
 */
class AiCircuitBreakerService implements AiCircuitBreakerInterface
{
    public function __construct(
        private readonly int $threshold = 5,
        private readonly int $cooldownSeconds = 120,
    ) {
    }

    public function isOpen(AiProvider $provider, ?string $entityId = null): bool
    {
        // Entity OU global: o global (trip — sem crédito, chave recusada, limite) vale para todos, mesmo para a
        // entity que já tem a própria linha fechada.
        return $this->openState($provider, $entityId) !== null;
    }

    public function recordSuccess(AiProvider $provider, ?string $entityId = null): void
    {
        $breaker = $this->findBreaker($provider, $entityId);

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

    public function recordFailure(AiProvider $provider, string $triggerType, ?string $entityId = null): void
    {
        $breaker = AiCircuitBreaker::firstOrCreate(
            ['provider_code' => $provider->value, 'entity_id' => $entityId],
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
            Log::warning('AiCircuitBreaker: circuito aberto.', [
                'provider'   => $provider->value,
                'entity_id'  => $entityId,
                'failures'   => $newCount,
                'open_until' => now()->addSeconds($this->cooldownSeconds)->toIso8601String(),
                'trigger'    => $triggerType,
            ]);
        }
    }

    public function reset(AiProvider $provider, ?string $entityId = null): void
    {
        AiCircuitBreaker::query()
            ->where('provider_code', $provider->value)
            ->where('entity_id', $entityId)
            ->update([
                'state'         => 'closed',
                'failure_count' => 0,
                'open_until'    => null,
            ]);
    }

    public function trip(AiProvider $provider, string $triggerType, int $seconds): void
    {
        $breaker = AiCircuitBreaker::firstOrCreate(
            ['provider_code' => $provider->value, 'entity_id' => null],
            ['state' => 'closed', 'failure_count' => 0, 'failure_threshold' => $this->threshold],
        );
        $until = now()->addSeconds(max(1, $seconds));

        // Nunca encurta um circuito já aberto por mais tempo (ex.: cota de 15 min e, no meio, um limite de 20 s).
        if ($breaker->isOpen() && $breaker->open_until !== null && $breaker->open_until->greaterThan($until)) {
            return;
        }

        $breaker->update([
            'state'             => 'open',
            'failure_count'     => $breaker->failure_count + 1,
            'last_trigger_type' => $triggerType,
            'last_failure_at'   => now(),
            'open_until'        => $until,
        ]);

        Log::warning('AiCircuitBreaker: circuito aberto direto.', [
            'provider'   => $provider->value,
            'trigger'    => $triggerType,
            'open_until' => $until->toIso8601String(),
        ]);
    }

    public function openState(AiProvider $provider, ?string $entityId = null): ?array
    {
        foreach (array_unique([$entityId, null], SORT_REGULAR) as $scope) {
            $breaker = $this->findBreaker($provider, $scope);

            if ($breaker?->isOpen()) {
                return ['trigger_type' => $breaker->last_trigger_type, 'open_until' => $breaker->open_until];
            }
        }

        return null;
    }

    private function findBreaker(AiProvider $provider, ?string $entityId): ?AiCircuitBreaker
    {
        // O índice único não cobre entity_id NULL no PostgreSQL: dois workers podem criar
        // a linha global ao mesmo tempo. Vale a última escrita (a do trip/falha mais recente).
        return AiCircuitBreaker::query()
            ->where('provider_code', $provider->value)
            ->where('entity_id', $entityId)
            ->latest('updated_at')
            ->first();
    }
}
