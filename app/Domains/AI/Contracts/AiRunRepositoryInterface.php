<?php

declare(strict_types=1);

namespace App\Domains\AI\Contracts;

use App\Domains\AI\Models\AiRun;

interface AiRunRepositoryInterface
{
    public function find(string $id): ?AiRun;

    public function markRunning(AiRun $run): void;

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
}
