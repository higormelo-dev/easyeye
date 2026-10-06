<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Contracts\Notifications\SendsSaasWhatsApp;
use App\Jobs\WhatsApp\SendSaasWhatsAppNoticeJob;
use App\Models\User;
use App\Services\WhatsApp\WhatsAppService;
use App\Support\Billing\NoticeWindow;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Canal WhatsApp dos avisos do SaaS para a clínica (régua de cobrança, fim do
 * teste grátis, cobrança enviada pelo manager), por template aprovado. Usa
 * SEMPRE o app GLOBAL do EasyEye na Gupshup (o mesmo do código de
 * verificação do cadastro) — nunca o número da clínica, que pode estar
 * bloqueada. Não passa pelo ClinicServiceGate: o
 * gate barra automações da clínica para pacientes, não estes avisos.
 *
 * Só para o telefone VERIFICADO do contato (users.phone_verified_at); sem
 * ele, nada sai (o e-mail segue) e fica no log. O envio vai para um job
 * próprio (SendSaasWhatsAppNoticeJob): fila, poucas tentativas, horário
 * comercial e reconferência do aviso na hora — uma falha no WhatsApp nunca
 * impede o e-mail nem a régua.
 */
class SaasWhatsAppChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notification instanceof SendsSaasWhatsApp || ! $notifiable instanceof User) {
            return;
        }

        if (! self::reachable($notifiable)) {
            Log::info('[whatsapp:saas-notice] contato sem WhatsApp verificado — só e-mail.', [
                'user_id'      => $notifiable->id,
                'notification' => class_basename($notification),
            ]);

            return;
        }

        SendSaasWhatsAppNoticeJob::dispatch(
            (string) $notifiable->id,
            $notification,
            $notification->locale ?? app()->getLocale(),
        )->delay(NoticeWindow::nextSendAt());
    }

    /**
     * Canais extras do aviso: este, para o contato com WhatsApp verificado;
     * sem ele, nenhum (o aviso vai só por e-mail — fica no log).
     *
     * @return list<class-string>
     */
    public static function channelsFor(object $notifiable): array
    {
        if ($notifiable instanceof User && self::reachable($notifiable)) {
            return [self::class];
        }

        if ($notifiable instanceof User && config('billing.notices.whatsapp_enabled', true)) {
            Log::info('[whatsapp:saas-notice] contato sem WhatsApp verificado — aviso só por e-mail.', ['user_id' => $notifiable->id]);
        }

        return [];
    }

    /** WhatsApp do SaaS ligado e o contato com telefone verificado e válido. */
    public static function reachable(User $user): bool
    {
        return (bool) config('billing.notices.whatsapp_enabled', true)
            && $user->phone_verified_at !== null
            && WhatsAppService::normalizePhone($user->phone) !== null;
    }
}
