<?php

declare(strict_types=1);

namespace App\Jobs\Fiscal;

use App\Enums\Fiscal\NfseEnvironment;
use App\Services\Fiscal\NfseSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Aplica à nota o aviso que a Focus mandou pelo webhook (ver FocusNfeWebhookController e NfseSyncService::apply). O
 * controller só valida e responde 2xx; a gravação roda aqui, na fila, e é idempotente: entrega repetida não muda nada.
 * Falha transitória (banco, trava) repete com backoff; a Focus ainda retenta o webhook por 48 h e a varredura
 * `fiscal:nfse-sync` consulta o que ficar para trás.
 */
class ProcessNfseWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 1800];
    }

    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly string $ref,
        public readonly string $environment,
        public readonly array $payload,
    ) {
        $this->onQueue((string) config('fiscal.queue', 'default'));
    }

    public function handle(NfseSyncService $sync): void
    {
        $environment = NfseEnvironment::tryFrom($this->environment);

        if ($environment === null) {
            return;
        }

        $sync->apply($this->ref, $environment, $this->payload, 'webhook');
    }
}
