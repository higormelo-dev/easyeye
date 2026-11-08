<?php

declare(strict_types=1);

namespace Tests\Support\Fakes;

use App\Domains\AI\Contracts\AiCircuitBreakerInterface;
use App\Enums\AI\AiProvider;

/**
 * Circuit breaker in-memory para testes do orchestrator (Unit, sem RefreshDatabase).
 * Evita dependência da tabela ai_circuit_breakers em testes que rodam fora do contexto
 * de Feature/DB. Implementa AiCircuitBreakerInterface (mesmo contract do serviço real).
 */
class InMemoryAiCircuitBreaker implements AiCircuitBreakerInterface
{
    /** @var array<string, int> */
    public array $failures = [];

    /** @var array<string, bool> */
    public array $openCircuits = [];

    public function __construct(private readonly int $testThreshold = 5)
    {
    }

    /** @var array<string, string> último motivo por chave */
    public array $triggers = [];

    /** @var list<array{provider: string, trigger: string, seconds: int}> */
    public array $trips = [];

    public function isOpen(AiProvider $provider, ?string $entityId = null): bool
    {
        return ($this->openCircuits[$this->key($provider, $entityId)] ?? false)
            || ($this->openCircuits[$this->key($provider, null)] ?? false);
    }

    public function trip(AiProvider $provider, string $triggerType, int $seconds): void
    {
        $key                      = $this->key($provider, null);
        $this->openCircuits[$key] = true;
        $this->triggers[$key]     = $triggerType;
        $this->trips[]            = ['provider' => $provider->value, 'trigger' => $triggerType, 'seconds' => $seconds];
    }

    public function openState(AiProvider $provider, ?string $entityId = null): ?array
    {
        foreach (array_unique([$entityId, null], SORT_REGULAR) as $scope) {
            $key = $this->key($provider, $scope);

            if ($this->openCircuits[$key] ?? false) {
                return ['trigger_type' => $this->triggers[$key] ?? null, 'open_until' => null];
            }
        }

        return null;
    }

    public function recordSuccess(AiProvider $provider, ?string $entityId = null): void
    {
        $key = $this->key($provider, $entityId);
        unset($this->failures[$key], $this->openCircuits[$key]);
    }

    public function recordFailure(AiProvider $provider, string $triggerType, ?string $entityId = null): void
    {
        $key                  = $this->key($provider, $entityId);
        $this->failures[$key] = ($this->failures[$key] ?? 0) + 1;
        $this->triggers[$key] = $triggerType;

        if ($this->failures[$key] >= $this->testThreshold) {
            $this->openCircuits[$key] = true;
        }
    }

    public function reset(AiProvider $provider, ?string $entityId = null): void
    {
        $key = $this->key($provider, $entityId);
        unset($this->failures[$key], $this->openCircuits[$key]);
    }

    private function key(AiProvider $provider, ?string $entityId): string
    {
        return $provider->value . '|' . ($entityId ?? 'global');
    }
}
