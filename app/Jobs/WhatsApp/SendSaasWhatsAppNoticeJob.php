<?php

declare(strict_types=1);

namespace App\Jobs\WhatsApp;

use App\Contracts\Notifications\SendsSaasWhatsApp;
use App\Models\User;
use App\Models\WhatsApp\{WhatsAppMessage, WhatsAppSetting};
use App\Notifications\Channels\SaasWhatsAppChannel;
use App\Services\WhatsApp\Contracts\WhatsAppProvider;
use App\Services\WhatsApp\{WhatsAppService, WhatsAppTemplates};
use App\Support\Billing\NoticeWindow;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Localizable;
use Throwable;

/**
 * Envia pelo WhatsApp (app GLOBAL do EasyEye) um aviso do SaaS para a
 * clínica — ver SaasWhatsAppChannel — como template aprovado (categoria
 * Utilidade), com o link para dentro do sistema no botão URL de sufixo
 * dinâmico (https://developers.facebook.com/docs/whatsapp/business-management-api/message-templates/components).
 * Na hora do envio:
 *
 *  - o aviso é reconferido (shouldSend do aviso: pagou, mudou de etapa,
 *    contratou — não envia);
 *  - fora do horário comercial, volta para a fila até o próximo início da
 *    janela (sem gastar tentativa);
 *  - o template vai no idioma do aviso (en só se aprovado em inglês);
 *  - quem respondeu SAIR ao número do EasyEye não recebe (o e-mail segue).
 *
 * Falha transitória: até 3 tentativas com espera; permanente ou esgotada:
 * desiste com log (o e-mail já saiu por outro job). Fica em whatsapp_messages
 * (sem clínica — é comunicação do SaaS). O telefone nunca vai para o log.
 *
 * Sem duplicidade: a linha (chave do job em payload.send_key) vira
 * `sending` ANTES da chamada de envio — e DEPOIS da autenticação do app, que
 * pode demorar (lock do token) sem que nada tenha saído; nova
 * tentativa/reentrega do mesmo job que encontra `sending` não reenvia (vira
 * failed unknown_delivery), e timeout de leitura não é retentado. failed()
 * (worker morto, timeout do job) fecha a linha do mesmo jeito.
 */
class SendSaasWhatsAppNoticeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Localizable;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** Pior caso de uma tentativa: lock do token (120 s) + envio. */
    public int $timeout = 150;

    /** @var list<int> espera entre tentativas (segundos) */
    public array $backoff = [60, 600];

    /** Identifica as tentativas deste job (linha em whatsapp_messages). */
    public readonly string $sendKey;

    public function __construct(
        public readonly string $userId,
        public readonly Notification $notice,
        public readonly string $locale,
    ) {
        $this->sendKey = (string) Str::uuid();
        $this->onQueue((string) config('whatsapp.queue', 'default'));
    }

    public function handle(WhatsAppProvider $provider, WhatsAppService $service, WhatsAppTemplates $templates): void
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
            Log::warning('[whatsapp:saas-notice] app global indisponível — aviso só por e-mail.', ['user_id' => $user->id, 'notice' => $kind]);

            return;
        }

        $message = $this->withLocale($this->locale, fn () => $notice->toSaasWhatsApp($user));
        $phone   = WhatsAppService::normalizePhone($user->phone);

        if (! is_array($message) || $phone === null) {
            return;
        }

        if ($service->isSuppressed($setting, $phone)) {
            Log::info('[whatsapp:saas-notice] contato pediu para não receber (SAIR) — aviso só por e-mail.', ['user_id' => $user->id, 'notice' => $kind]);

            return;
        }

        $url      = (string) $message['url'];
        $template = $templates->build($message['template'], $message['values'], $this->locale, ['url_suffix' => self::urlSuffix($url)]);
        $row      = $this->claim($setting, $template->name, $phone, $templates->render($message['template'], $message['values'], $this->locale) . "\n" . $url, $kind, (string) $user->id);

        if ($row === null) {
            return;
        }

        // Autentica antes de marcar `sending` (nada sai se falhar aqui).
        $auth   = $provider->authorize($setting);
        $result = $auth['ok'] ? null : $auth;

        if ($result === null) {
            $row->update(['status' => WhatsAppMessage::STATUS_SENDING]);

            $result = $provider->sendTemplate($setting, $phone, $template);
        }

        if (! $result['ok'] && ($result['retryable'] ?? false) && $this->attempts() < $this->tries) {
            // Com certeza não saiu: a linha volta para pending e a fila tenta de novo.
            $row->update(['status' => WhatsAppMessage::STATUS_PENDING, 'error_code' => $result['error_code'] ?? null]);
            $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)]);

            return;
        }

        $row->forceFill([
            'status'              => $result['ok'] ? WhatsAppMessage::STATUS_SENT : WhatsAppMessage::STATUS_FAILED,
            'provider_message_id' => $result['message_id'] ?? null,
            'sent_at'             => $result['ok'] ? now() : null,
            'failed_at'           => $result['ok'] ? null : now(),
            'error'               => $result['ok'] ? null : mb_substr((string) ($result['error'] ?? ''), 0, 1000),
            'error_code'          => $result['error_code'] ?? null,
        ])->save();

        if (! $result['ok']) {
            Log::warning('[whatsapp:saas-notice] falha no envio — desistindo (o e-mail já foi).', [
                'user_id' => $user->id,
                'notice'  => $kind,
                'error'   => $result['error_code'] ?? 'unknown',
            ]);
        }
    }

    /**
     * Linha da trilha deste job, `pending` (vira `sending` depois da
     * autenticação). Null = não enviar: uma tentativa anterior já
     * enviou/falhou, ou foi interrompida no meio da chamada (vira failed
     * unknown_delivery — pode ter saído).
     */
    private function claim(WhatsAppSetting $setting, string $template, string $phone, string $body, string $kind, string $userId): ?WhatsAppMessage
    {
        $row = WhatsAppMessage::query()
            ->where('kind', WhatsAppMessage::KIND_SAAS_NOTICE)
            ->where('payload->send_key', $this->sendKey)
            ->first();

        if ($row === null) {
            return WhatsAppMessage::create([
                'entity_id'           => null,
                'whatsapp_setting_id' => $setting->id,
                'sender_app_id'       => $setting->app_id,
                'direction'           => 'out',
                'kind'                => WhatsAppMessage::KIND_SAAS_NOTICE,
                'template'            => $template,
                'phone'               => $phone,
                'body'                => $body,
                'status'              => WhatsAppMessage::STATUS_PENDING,
                'payload'             => ['notice' => $kind, 'user_id' => $userId, 'send_key' => $this->sendKey],
            ]);
        }

        if ($row->status === WhatsAppMessage::STATUS_SENDING) {
            $row->update([
                'status'     => WhatsAppMessage::STATUS_FAILED,
                'failed_at'  => now(),
                'error_code' => 'unknown_delivery',
                'error'      => 'Envio interrompido no meio da chamada à Gupshup: o aviso pode ter saído — não reenviado.',
            ]);

            return null;
        }

        return $row->status === WhatsAppMessage::STATUS_PENDING ? $row : null;
    }

    /**
     * Worker morreu / job estourou o timeout: a linha não fica `sending`
     * (pode ter saído → unknown_delivery) nem `pending` para sempre.
     */
    public function failed(Throwable $exception): void
    {
        $row = WhatsAppMessage::query()
            ->where('kind', WhatsAppMessage::KIND_SAAS_NOTICE)
            ->where('payload->send_key', $this->sendKey)
            ->whereIn('status', [WhatsAppMessage::STATUS_PENDING, WhatsAppMessage::STATUS_SENDING])
            ->first();

        if ($row === null) {
            return;
        }

        $row->update([
            'status'     => WhatsAppMessage::STATUS_FAILED,
            'failed_at'  => now(),
            'error_code' => $row->status === WhatsAppMessage::STATUS_SENDING ? 'unknown_delivery' : ($row->error_code ?? 'job_failed'),
            'error'      => mb_substr($exception->getMessage(), 0, 1000),
        ]);
    }

    /**
     * Sufixo do botão URL do template, cadastrado na Meta como
     * "{APP_URL}/{{1}}": o caminho (com a query) depois do endereço do app.
     */
    public static function urlSuffix(string $url): string
    {
        $base = rtrim((string) config('app.url'), '/') . '/';

        if (str_starts_with($url, $base)) {
            return Str::after($url, $base);
        }

        $path  = ltrim((string) parse_url($url, PHP_URL_PATH), '/');
        $query = parse_url($url, PHP_URL_QUERY);

        return $path . ($query ? '?' . $query : '');
    }
}
