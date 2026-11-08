<?php

declare(strict_types=1);

namespace App\Domains\AI\Contracts;

use App\Enums\AI\AiProvider;
use Carbon\CarbonInterface;

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

    /**
     * Abre o circuito GLOBAL do provedor na hora, por $seconds, sem esperar o
     * limiar de falhas: falta de crédito ou chave recusada (vale para a conta
     * toda) e limite por minuto (até onde o provedor pediu).
     */
    public function trip(AiProvider $provider, string $triggerType, int $seconds): void;

    /**
     * Motivo e prazo do circuito aberto (da entity ou o global), ou null se fechado.
     *
     * @return array{trigger_type: ?string, open_until: ?CarbonInterface}|null
     */
    public function openState(AiProvider $provider, ?string $entityId = null): ?array;
}
