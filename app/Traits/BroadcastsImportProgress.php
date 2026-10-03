<?php

declare(strict_types=1);

namespace App\Traits;

use App\Events\ImportProgressUpdated;

/**
 * Importação que transmite o próprio progresso via WebSocket a cada
 * atualização (os serviços já gravam processed_rows/contadores no model).
 *
 * Mudança de estado (status, fase, motivo de interrupção, erro, arquivo de
 * erros) sempre transmite; avanço de linhas no máximo 2x/segundo por
 * importação — a tela não precisa de mais e o importador não fica preso em
 * chamadas ao servidor WebSocket.
 *
 * O model define progressPayload() (mesmo formato da tela) e
 * broadcastChannelName() (canal privado — ver routes/channels.php).
 */
trait BroadcastsImportProgress
{
    private const STATE_FIELDS = ['status', 'phase', 'abort_reason', 'error', 'errors_file_path', 'finished_at'];

    private const MIN_INTERVAL_SECONDS = 0.5;

    /** @var array<string, float> última transmissão por importação (no processo do worker) */
    private static array $lastBroadcastAt = [];

    /** @return array<string, mixed> */
    abstract public function progressPayload(): array;

    abstract public function broadcastChannelName(): string;

    protected static function bootBroadcastsImportProgress(): void
    {
        static::updated(function (self $import): void {
            $stateChanged = $import->wasChanged(array_values(array_intersect(
                self::STATE_FIELDS,
                array_keys($import->getAttributes()),
            )));

            $key = (string) $import->getKey();
            $now = microtime(true);

            if (! $stateChanged && $now - (self::$lastBroadcastAt[$key] ?? 0.0) < self::MIN_INTERVAL_SECONDS) {
                return;
            }

            self::$lastBroadcastAt[$key] = $now;

            ImportProgressUpdated::dispatch($import->broadcastChannelName(), $import->progressPayload());
        });
    }
}
