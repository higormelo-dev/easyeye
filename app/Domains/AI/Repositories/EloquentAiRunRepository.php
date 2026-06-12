<?php

declare(strict_types=1);

namespace App\Domains\AI\Repositories;

use App\Domains\AI\Contracts\AiRunRepositoryInterface;
use App\Domains\AI\Models\AiRun;
use App\Enums\AI\AiRunStatus;

class EloquentAiRunRepository implements AiRunRepositoryInterface
{
    public function find(string $id): ?AiRun
    {
        return AiRun::query()->find($id);
    }

    public function markRunning(AiRun $run): void
    {
        $payload = [
            'status'        => AiRunStatus::Running->value,
            'error_message' => null,
        ];

        // started_at é setado apenas no primeiro markRunning para preservar o
        // tempo real de execução em caso de retry do job.
        if ($run->started_at === null) {
            $payload['started_at'] = now();
        }

        $run->update($payload);
    }

    public function markWaitingApproval(
        AiRun $run,
        string $finalOutput,
        array $safetyNotes,
        int $consumedCredits,
        ?string $errorMessage = null,
    ): void {
        $run->update([
            'status'           => AiRunStatus::WaitingApproval->value,
            'final_output'     => $finalOutput,
            'safety_notes'     => $safetyNotes,
            'consumed_credits' => $consumedCredits,
            'error_message'    => $errorMessage,
        ]);
    }

    public function markFailed(AiRun $run, string $errorMessage): void
    {
        $run->update([
            'status'           => AiRunStatus::Failed->value,
            'current_role'     => null,
            'current_provider' => null,
            'error_message'    => mb_substr($errorMessage, 0, 2000),
        ]);
    }

    public function markCancelled(AiRun $run): void
    {
        $payload = [
            'status'           => AiRunStatus::Cancelled->value,
            'current_role'     => null,
            'current_provider' => null,
        ];

        if ($run->cancelled_at === null) {
            $payload['cancelled_at'] = now();
        }

        $run->update($payload);
    }
}
