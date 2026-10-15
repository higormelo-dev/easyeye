<?php

declare(strict_types=1);

namespace App\Jobs\Surgery;

use App\Services\Surgery\SurgeryAuthorizationNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};

/**
 * Aviso neutro de autorização por WhatsApp para UM evento. Carrega só o id do
 * evento (nenhum dado do paciente na fila). Uma tentativa: a idempotência é
 * do livro de avisos, e uma segunda tentativa cairia na chave única.
 */
class SendSurgeryAuthorizationNoticeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 150;

    public function __construct(public readonly string $eventId)
    {
        $this->onQueue((string) config('whatsapp.queue', 'default'));
    }

    public function handle(SurgeryAuthorizationNotifier $notifier): void
    {
        $notifier->notify($this->eventId);
    }
}
