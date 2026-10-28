<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Contexto de tenant (entity) da requisição/processo atual.
 *
 * Fonte ÚNICA da entity ativa para o escopo multi-tenant. Vinculado por
 * middleware nas rotas de painel-cliente (ver BindTenantContext). NÃO lê
 * session() diretamente no scope — em job/CLI/webhook não há sessão, então o
 * contexto fica "não vinculado" e o EntityScope se torna inerte (sem filtro),
 * preservando o comportamento atual desses fluxos.
 *
 * Para escopar explicitamente um job/comando, use TenantContext::runAs().
 */
class TenantContext
{
    private ?string $entityId = null;

    private bool $bound = false;

    /** Vincula a entity ativa (idempotente). Null limpa o vínculo. */
    public function set(?string $entityId): void
    {
        $this->entityId = ($entityId === null || $entityId === '') ? null : $entityId;
        $this->bound    = $this->entityId !== null;
    }

    /** Entity ativa, ou null quando não vinculado (scope fica inerte). */
    public function id(): ?string
    {
        return $this->entityId;
    }

    public function isBound(): bool
    {
        return $this->bound;
    }

    public function clear(): void
    {
        $this->entityId = null;
        $this->bound    = false;
    }

    /**
     * Executa $callback com a entity informada vinculada, restaurando o
     * contexto anterior ao fim. Use em jobs/comandos que precisam de escopo.
     *
     * @template T
     *
     * @param callable():T $callback
     *
     * @return T
     */
    public function runAs(?string $entityId, callable $callback): mixed
    {
        $prevId    = $this->entityId;
        $prevBound = $this->bound;

        $this->set($entityId);

        try {
            return $callback();
        } finally {
            $this->entityId = $prevId;
            $this->bound    = $prevBound;
        }
    }

    /**
     * Executa $callback SEM vínculo de tenant (EntityScope inerte) —
     * leitura cross-entidade explícita e auditável.
     *
     * @template T
     *
     * @param callable():T $callback
     *
     * @return T
     */
    public function withoutScope(callable $callback): mixed
    {
        return $this->runAs(null, $callback);
    }
}
