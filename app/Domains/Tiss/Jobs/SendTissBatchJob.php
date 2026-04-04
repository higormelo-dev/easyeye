<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Jobs;

use App\Domains\Tiss\Enums\TissBatchStatus;
use App\Domains\Tiss\Models\TissBatch;
use App\Domains\Tiss\Services\SendTissBatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\{ShouldBeUnique, ShouldQueue};
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Throwable;

class SendTissBatchJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $backoff = 120;

    public int $timeout = 90;

    public function __construct(
        public readonly string $batchId,
        public readonly ?string $entityId = null,
        public readonly bool $force = false,
    ) {
        $this->tries = (int) config('tiss.queues.max_attempts.send', 5);
    }

    public function uniqueId(): string
    {
        return $this->batchId;
    }

    public function handle(SendTissBatchService $sendTissBatchService): void
    {
        $query = TissBatch::query()->where('id', $this->batchId);

        if ($this->entityId) {
            $query->where('entity_id', $this->entityId);
        }

        $batch = $query->first();

        if (! $batch) {
            return;
        }

        $sendTissBatchService->send(
            batch: $batch,
            force: $this->force,
            throwOnError: true,
        );
    }

    public function failed(Throwable $exception): void
    {
        $query = TissBatch::query()->where('id', $this->batchId);

        if ($this->entityId) {
            $query->where('entity_id', $this->entityId);
        }

        $batch = $query->first();

        if (! $batch) {
            return;
        }

        $batch->update([
            'status'     => TissBatchStatus::Error->value,
            'last_error' => mb_substr('Falha ao enviar lote após todas as tentativas: ' . $exception->getMessage(), 0, 1000),
        ]);
    }
}
