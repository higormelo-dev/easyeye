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
        $run->update([
            'status'        => AiRunStatus::Running->value,
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
            'status'        => AiRunStatus::Failed->value,
            'error_message' => mb_substr($errorMessage, 0, 2000),
        ]);
    }
}
