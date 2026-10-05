<?php

declare(strict_types=1);

namespace App\Jobs\WhatsApp;

use App\Contracts\Notifications\SendsSaasWhatsApp;
use App\Models\User;
use App\Models\WhatsApp\WhatsAppSetting;
use App\Notifications\Channels\SaasWhatsAppChannel;
use App\Services\WhatsApp\{WhatsAppService, ZApiClient};
use App\Support\Billing\NoticeWindow;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Traits\Localizable;

/**
 * Envia pelo WhatsApp (instância GLOBAL do SaaS) um aviso do SaaS para a
 * clínica — ver SaasWhatsAppChannel. Na hora do envio:
 *
 *  - o aviso é reconferido (shouldSend do aviso: pagou, mudou de etapa,
 *    contratou — não envia);
 *  - fora do horário comercial, volta para a fila até o próximo início da
 *    janela (sem gastar tentativa);
 *  - o texto é montado no idioma do aviso, só com o essencial.
 *
 * Falha da Z-API: até 3 tentativas com espera; depois desiste com log (o
 * e-mail já saiu por outro job). Instância global indisponível ou contato
 * sem WhatsApp verificado: desiste na hora, com log — nada a tentar de novo.
 * O telefone nunca vai para o log.
 */
class SendSaasWhatsAppNoticeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Localizable;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> espera entre tentativas (segundos) */
    public array $backoff = [60, 600];

    public function __construct(
        public readonly string $userId,
        public readonly Notification $notice,
        public readonly string $locale,
    ) {
        $this->onQueue((string) config('whatsapp.queue', 'default'));
    }

    public function handle(ZApiClient $client): void
    {
        $user   = User::query()->find($this->userId);
        $notice = $this->notice;
        $kind   = class_basename($notice);

        if ($user === null || ! $notice instanceof SendsSaasWhatsApp || ! SaasWhatsAppChannel::reachable($user)) {
            return;
        }

        if (method_exists($notice, 'shouldSend') && $notice->shouldSend($user, SaasWhatsAppChannel::class) === false) {
            Log::info('[whatsapp:saas-notice] aviso não vale mais — não enviado.', ['user_id' => $user->id, 'notice' => $kind]);

            return;
        }

        // Fila atrasada até fora do horário comercial: espera a janela (na
        // fila síncrona não há como esperar — não envia, com log).
        if (! NoticeWindow::isOpen()) {
            if ($this->job instanceof SyncJob) {
                Log::info('[whatsapp:saas-notice] fora do horário comercial na fila síncrona — não enviado.', ['user_id' => $user->id, 'notice' => $kind]);

                return;
            }

            static::dispatch($this->userId, $notice, $this->locale)->delay(NoticeWindow::nextSendAt());

            return;
        }

        $setting = WhatsAppSetting::globalSetting();

        if ($setting === null || ! $setting->isOperational()) {
            Log::warning('[whatsapp:saas-notice] instância global indisponível — aviso só por e-mail.', ['user_id' => $user->id, 'notice' => $kind]);

            return;
        }

        $message = $this->withLocale($this->locale, fn () => $notice->toSaasWhatsApp($user));
        $phone   = WhatsAppService::normalizePhone($user->phone);

        if (blank($message) || $phone === null) {
            return;
        }

        $result = $client->sendText($setting, $phone, (string) $message);

        if ($result['ok']) {
            return;
        }

        if ($this->attempts() < $this->tries) {
            $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)]);

            return;
        }

        Log::warning('[whatsapp:saas-notice] falha no envio — desistindo (o e-mail já foi).', [
            'user_id' => $user->id,
            'notice'  => $kind,
            'error'   => $result['error_code'] ?? 'unknown',
        ]);
    }
}
