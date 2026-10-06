<?php

declare(strict_types=1);

namespace App\Jobs\WhatsApp;

use App\Models\WhatsApp\{WhatsAppMessage, WhatsAppSetting};
use App\Services\Auth\PhoneVerificationService;
use App\Services\WhatsApp\Contracts\WhatsAppProvider;
use App\Services\WhatsApp\{WhatsAppAlerts, WhatsAppTemplates};
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\{ShouldBeEncrypted, ShouldQueue};
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Envia o código de verificação do WhatsApp do cadastro (/register) pelo app
 * GLOBAL do EasyEye, como template de AUTENTICAÇÃO (botão "copiar código")
 * — https://partner-docs.gupshup.io/reference/post_partner-app-appid-v3-message-9.
 *
 * O código viaja no payload do job CIFRADO (ShouldBeEncrypted; TTL do código
 * 10 min) — o hash no banco continua sendo a única fonte de validação. O
 * envio fica em whatsapp_messages (trilha/custo) com o código mascarado.
 * Não passa pelo descadastro: é o próprio usuário que pede o código.
 *
 * Nova tentativa (falha transitória ou timeout de leitura — um código
 * repetido não faz mal) não reenvia se a anterior já tiver o id da Gupshup
 * (linha da trilha por payload.send_key). Falha permanente por configuração
 * (template, app, credencial) alerta o time e libera o gate do cadastro
 * (EnsurePhoneVerified).
 *
 * A linha nasce `pending`; a autenticação do app acontece ANTES de ela virar
 * `sending` (demora/falha no token não a deixa presa). failed() (worker
 * morto, timeout do job) fecha a linha: `sending` → failed unknown_delivery;
 * `pending` → failed com o último erro (o gate lê esse estado).
 */
class SendPhoneVerificationCodeJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** Pior caso de uma tentativa: lock do token (120 s) + envio. */
    public int $timeout = 150;

    /** @var array<int, int> backoff progressivo entre tentativas (segundos) */
    public array $backoff = [10, 60];

    /** Identifica as tentativas deste job (linha em whatsapp_messages). */
    public readonly string $sendKey;

    public function __construct(
        public readonly string $userId,
        public readonly string $phone,
        public readonly string $code,
        public readonly string $locale = 'pt_BR',
    ) {
        $this->sendKey = (string) Str::uuid();
        $this->onQueue((string) config('whatsapp.queue', 'default'));
    }

    public function handle(WhatsAppProvider $provider, WhatsAppTemplates $templates): void
    {
        $setting = WhatsAppSetting::globalSetting();

        if (! $setting || ! $setting->isOperational()) {
            Log::warning('[whatsapp:verification] app global indisponível — código não enviado.', [
                'user_id' => $this->userId,
            ]);

            return;
        }

        $template = $templates->build('verification_code', ['code' => $this->code], $this->locale);

        $row = WhatsAppMessage::query()
            ->where('kind', WhatsAppMessage::KIND_VERIFICATION)
            ->where('payload->send_key', $this->sendKey)
            ->first();

        // Tentativa anterior deste job já entregou à Gupshup: não duplica.
        if ($row !== null && ($row->status === WhatsAppMessage::STATUS_SENT || filled($row->provider_message_id))) {
            return;
        }

        $row ??= WhatsAppMessage::create([
            'entity_id'           => null,
            'whatsapp_setting_id' => $setting->id,
            'sender_app_id'       => $setting->app_id,
            'direction'           => 'out',
            'kind'                => WhatsAppMessage::KIND_VERIFICATION,
            'template'            => $template->name,
            'phone'               => $this->phone,
            'body'                => $templates->render('verification_code', ['code' => $this->code], $this->locale),
            'status'              => WhatsAppMessage::STATUS_PENDING,
            'payload'             => ['user_id' => $this->userId, 'send_key' => $this->sendKey],
        ]);

        // Autentica antes de marcar `sending` (nada sai se falhar aqui).
        $auth   = $provider->authorize($setting);
        $result = $auth['ok'] ? null : $auth;

        if ($result === null) {
            $row->update(['status' => WhatsAppMessage::STATUS_SENDING]);

            $result = $provider->sendTemplate($setting, $this->phone, $template);
        }

        // Código: timeout de leitura também pode tentar de novo (um código
        // repetido não faz mal; o usuário também pode pedir reenvio).
        $retry = ! $result['ok'] && (($result['retryable'] ?? false) || ($result['error_code'] ?? null) === 'unknown_delivery');

        if ($retry && $this->attempts() < $this->tries) {
            Log::warning('[whatsapp:verification] falha no envio do código — nova tentativa.', [
                'user_id' => $this->userId,
                'error'   => $result['error_code'] ?? 'unknown',
            ]);

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
            Log::warning('[whatsapp:verification] falha no envio do código.', [
                'user_id' => $this->userId,
                'error'   => $result['error_code'] ?? 'unknown',
            ]);

            if (PhoneVerificationService::isConfigurationFailure($result['error_code'] ?? null)) {
                WhatsAppAlerts::critical('verification_undeliverable', ['error_code' => $result['error_code'] ?? null]);
            }
        }
    }

    /**
     * Worker morreu / job estourou o timeout ou as tentativas: a linha não
     * fica `sending`/`pending` para sempre.
     */
    public function failed(Throwable $exception): void
    {
        $row = WhatsAppMessage::query()
            ->where('kind', WhatsAppMessage::KIND_VERIFICATION)
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
}
