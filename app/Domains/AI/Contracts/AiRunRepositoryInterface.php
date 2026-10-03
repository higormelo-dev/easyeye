<?php

declare(strict_types=1);

namespace App\Domains\AI\Contracts;

use App\Domains\AI\Models\AiRun;

interface AiRunRepositoryInterface
{
    public function find(string $id): ?AiRun;

    public function markRunning(AiRun $run): void;

    /**
     * Registra o resumo auditável do que foi enviado ao provedor (sem conteúdo).
     *
     * @param array<string, mixed> $dispatch AiDispatchAudit::summarize()
     */
    public function recordDispatch(AiRun $run, array $dispatch): void;

    /**
     * @param list<string> $safetyNotes
     */
    public function markWaitingApproval(
        AiRun $run,
        string $finalOutput,
        array $safetyNotes,
        int $consumedCredits,
        ?string $errorMessage = null,
    ): void;

    public function markFailed(AiRun $run, string $errorMessage): void;

    public function markCancelled(AiRun $run): void;
}
