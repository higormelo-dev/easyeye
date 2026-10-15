<?php

declare(strict_types=1);

namespace App\Listeners\Surgery;

use App\Events\Surgery\SurgeryPerformed;
use App\Services\Surgery\PostOp\PostOpReturnService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Cirurgia realizada → planeja e agenda os retornos D1/D7/D30 do pós-operatório.
 *
 * Roda na fila, DEPOIS do commit e fora de qualquer trava do caso (a Agenda
 * tem a própria ordem de locks). Idempotente: a reentrega do evento (worker
 * morto, duplo despacho) não duplica retorno — ver PostOpReturnService.
 */
class CreatePostOpReturns implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(SurgeryPerformed $event): void
    {
        app(PostOpReturnService::class)->plan($event->caseId);
    }
}
