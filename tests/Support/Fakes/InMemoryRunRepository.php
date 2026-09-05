<?php

declare(strict_types=1);

namespace Tests\Support\Fakes;

use App\Domains\AI\Contracts\AiRunRepositoryInterface;
use App\Domains\AI\Models\AiRun;
use App\Enums\AI\AiRunStatus;

/**
 * Repositório em memória para testes de AiRunExecutionService (Unit, sem DB).
 */
class InMemoryRunRepository implements AiRunRepositoryInterface
{
    public function find(string $id): ?AiRun
    {
        return null;
    }

    public function markRunning(AiRun $run): void
    {
        $run->forceFill([
            'status'        => AiRunStatus::Running,
            'error_message' => null,
        ]);
    }

    public function markWaitingApproval(
        AiRun $run,
        string $finalOutput,
        array $safetyNotes,
        int $consumedCredits,
        ?string $errorMessage = null,
    ): void {
        $run->forceFill([
            'status'           => AiRunStatus::WaitingApproval,
            'final_output'     => $finalOutput,
            'safety_notes'     => $safetyNotes,
            'consumed_credits' => $consumedCredits,
            'error_message'    => $errorMessage,
        ]);
    }

    public function markFailed(AiRun $run, string $errorMessage): void
    {
        $run->forceFill([
            'status'        => AiRunStatus::Failed,
            'error_message' => $errorMessage,
        ]);
    }

    public function markCancelled(AiRun $run): void
    {
        $run->forceFill([
            'status'       => AiRunStatus::Cancelled,
            'cancelled_at' => $run->cancelled_at ?? now(),
        ]);
    }
}
