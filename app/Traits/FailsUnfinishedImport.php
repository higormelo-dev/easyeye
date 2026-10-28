<?php

declare(strict_types=1);

namespace App\Traits;

use App\Enums\ImportStatus;
use Throwable;

/**
 * Job de importação que encerra o import quando o próprio job falha fora do
 * serviço — worker morto no meio (OOM, timeout, deploy) não passa pelo catch
 * do serviço, e a fila só registra MaxAttemptsExceededException na próxima
 * entrega. Sem isto o import ficava "processando" pra sempre: a barra de
 * progresso nunca terminava e, nos medicamentos, novos envios eram recusados.
 *
 * O job expõe $import e define failureAttributes() (campo de motivo de cada
 * model). O update transmite o estado final pelo BroadcastsImportProgress.
 */
trait FailsUnfinishedImport
{
    /** @return array<string, mixed> */
    abstract protected function failureAttributes(): array;

    public function failed(?Throwable $exception): void
    {
        if (! in_array($this->import->status, [ImportStatus::Pending, ImportStatus::Processing], true)) {
            return; // o serviço já registrou o desfecho
        }

        $this->import->update([
            ...$this->failureAttributes(),
            'status'      => ImportStatus::Failed,
            'finished_at' => now(),
        ]);
    }
}
