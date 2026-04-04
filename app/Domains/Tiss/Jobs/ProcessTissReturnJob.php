<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Jobs;

use App\Domains\Tiss\Enums\TissReturnStatus;
use App\Domains\Tiss\Models\TissReturn;
use App\Domains\Tiss\Services\ProcessTissReturnService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\{ShouldBeUnique, ShouldQueue};
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Throwable;

class ProcessTissReturnJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 7;

    public int $backoff = 90;

    public int $timeout = 120;

    public function __construct(
        public readonly string $returnId,
        public readonly ?string $entityId = null,
    ) {
        $this->tries = (int) config('tiss.queues.max_attempts.returns', 7);
    }

    public function uniqueId(): string
    {
        return $this->returnId;
    }

    public function handle(ProcessTissReturnService $processTissReturnService): void
    {
        $query = TissReturn::query()->where('id', $this->returnId);

        if ($this->entityId) {
            $query->where('entity_id', $this->entityId);
        }

        $return = $query->first();

        if (! $return) {
            return;
        }

        $processTissReturnService->process($return);
    }

    public function failed(Throwable $exception): void
    {
        $query = TissReturn::query()->where('id', $this->returnId);

        if ($this->entityId) {
            $query->where('entity_id', $this->entityId);
        }

        $return = $query->first();

        if (! $return) {
            return;
        }

        $return->update([
            'status'        => TissReturnStatus::Error->value,
            'error_message' => mb_substr('Falha ao processar retorno após todas as tentativas: ' . $exception->getMessage(), 0, 1000),
        ]);
    }
}
