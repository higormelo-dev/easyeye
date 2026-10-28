<?php

declare(strict_types=1);

namespace App\Jobs\Fiscal;

use App\Exceptions\Fiscal\FocusNfeException;
use App\Services\Fiscal\NfseSendService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Envia à Focus a NFS-e `pending_send` (ver NfseSendService). Até 5 tentativas com backoff 1 min → 6 h para queda de
 * conexão, 5xx e 429; erro de conteúdo ou de token NÃO repete (a nota muda de estado e a tela mostra o motivo). Esgotadas
 * as tentativas, `failed()` marca `send_failed`. Idempotente: nota que já saiu de `pending_send`/`send_failed` é ignorada,
 * então a varredura pode reenfileirar sem medo.
 *
 * Recebe só o id: nada de dado fiscal ou token serializado na fila.
 */
class SendNfseJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 1800, 3600, 21600];
    }

    public function __construct(public readonly string $documentId)
    {
        $this->onQueue((string) config('fiscal.queue', 'default'));
    }

    public function handle(NfseSendService $sender): void
    {
        try {
            $sender->send($this->documentId);
        } catch (FocusNfeException $e) {
            // 429: a Focus pediu espera — volta à fila depois dela, não no backoff padrão.
            if ($e->errorCode === FocusNfeException::RATE_LIMITED && $e->retryAfter !== null) {
                $this->release($e->retryAfter);

                return;
            }

            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        app(NfseSendService::class)->markSendFailed($this->documentId, $exception);
    }
}
