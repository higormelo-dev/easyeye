<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Services;

use App\Domains\Tiss\Models\TissStatusHistory;

class LogTissStatusTransitionService
{
    public function record(
        string $entityId,
        string $contextType,
        string $contextId,
        string $currentStatus,
        ?string $previousStatus = null,
        ?string $reason = null,
        array $payload = [],
    ): void {
        TissStatusHistory::query()->create([
            'entity_id'       => $entityId,
            'context_type'    => $contextType,
            'context_id'      => $contextId,
            'previous_status' => $previousStatus,
            'current_status'  => $currentStatus,
            'reason'          => $reason,
            'payload'         => $payload,
            'changed_at'      => now(),
        ]);
    }
}
