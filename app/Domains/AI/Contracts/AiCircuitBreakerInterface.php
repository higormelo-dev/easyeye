<?php

declare(strict_types=1);

namespace App\Domains\AI\Contracts;

use App\Enums\AI\AiProvider;

/**
 * Contrato do circuit breaker para providers LLM. Permite injeção polimórfica
 * de implementações persistentes (Eloquent) e in-memory (testes Unit).
 */
interface AiCircuitBreakerInterface
{
    public function isOpen(AiProvider $provider, ?string $entityId = null): bool;

    public function recordSuccess(AiProvider $provider, ?string $entityId = null): void;

    public function recordFailure(AiProvider $provider, string $triggerType, ?string $entityId = null): void;

    public function reset(AiProvider $provider, ?string $entityId = null): void;
}
