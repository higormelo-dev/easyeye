<?php

declare(strict_types=1);

namespace App\Listeners\Surgery;

use App\Events\Surgery\AuthorizationEventRecorded;
use App\Jobs\Surgery\SendSurgeryAuthorizationNoticeJob;
use App\Models\Surgery\SurgeryClinicSetting;
use App\Services\Surgery\SurgeryAuthorizationNotifier;

/**
 * Resposta da operadora registrada → enfileira o aviso neutro ao paciente.
 * Síncrono e barato (só decide se vale enfileirar); o envio e TODA a
 * revalidação acontecem no job. Clínica com o aviso desligado (o padrão) não
 * gera nem job nem linha no livro.
 */
class QueueAuthorizationNotice
{
    public function handle(AuthorizationEventRecorded $recorded): void
    {
        if (! SurgeryAuthorizationNotifier::isNotifiable($recorded->event->type)) {
            return;
        }

        if (! SurgeryClinicSetting::authorizationNoticeEnabled((string) $recorded->event->entity_id)) {
            return;
        }

        SendSurgeryAuthorizationNoticeJob::dispatch((string) $recorded->event->getKey());
    }
}
