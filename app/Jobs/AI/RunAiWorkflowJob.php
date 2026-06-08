<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Domains\AI\Contracts\AiRunRepositoryInterface;
use App\Domains\AI\Services\AiRunExecutionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\{ShouldBeUnique, ShouldQueue};
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Illuminate\Support\Facades\Log;
use Throwable;

class RunAiWorkflowJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries;

    public int $backoff;

    public int $timeout;

    public function __construct(
        public readonly string $aiRunId,
    ) {
        $this->tries   = (int) config('ai.jobs.tries', 3);
        $this->backoff = (int) config('ai.jobs.backoff_seconds', 45);
        $this->timeout = (int) config('ai.jobs.timeout_seconds', 180);
        $this->onQueue((string) config('ai.jobs.queue', 'default'));
    }

    public function uniqueId(): string
    {
        return $this->aiRunId;
    }

    public function handle(
        AiRunRepositoryInterface $runRepository,
        AiRunExecutionService $executionService,
    ): void {
        $run = $runRepository->find($this->aiRunId);

        if (! $run) {
            return;
        }

        $executionService->execute($run);
    }

    /**
     * Disparado pelo queue worker quando todas as retries falham OU quando ocorre
     * uma falha catastrófica (timeout, OOM, kill) que impede execute() de terminar.
     *
     * Libera a reserva de créditos de forma idempotente para garantir o critério
     * de aceite "Nenhum crédito pode ser perdido em falha". O compensateFailedRun
     * já faz o markFailed internamente.
     */
    public function failed(Throwable $exception): void
    {
        $run = app(AiRunRepositoryInterface::class)->find($this->aiRunId);

        if (! $run) {
            return;
        }

        try {
            app(AiRunExecutionService::class)
                ->compensateFailedRun($run, $exception->getMessage());
        } catch (Throwable $compensationError) {
            Log::error('RunAiWorkflowJob::failed: falha ao compensar reserva pendente.', [
                'ai_run_id'          => $this->aiRunId,
                'original_error'     => $exception->getMessage(),
                'compensation_error' => $compensationError->getMessage(),
            ]);
        }
    }
}
