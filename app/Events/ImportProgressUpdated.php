<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\{InteractsWithSockets, PrivateChannel};
use Illuminate\Contracts\Broadcasting\{ShouldBroadcastNow, ShouldRescue};
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Progresso de uma importação (pacientes, médicos, agenda, catálogo de
 * medicamentos) empurrado pro navegador via WebSocket (Reverb) — substitui o
 * polling HTTP de status. Disparado por App\Traits\BroadcastsImportProgress.
 *
 * - ShouldBroadcastNow: o importador já roda em job da fila; enfileirar outro
 *   job só pra transmitir atrasaria a barra.
 * - ShouldDispatchAfterCommit: nunca anuncia um estado que um rollback desfaria.
 * - ShouldRescue: Reverb fora do ar vira log, NUNCA derruba a importação.
 */
class ImportProgressUpdated implements ShouldBroadcastNow, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable;
    use InteractsWithSockets;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $channel,
        public readonly array $payload,
    ) {
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel($this->channel)];
    }

    public function broadcastAs(): string
    {
        return 'import.progress';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
