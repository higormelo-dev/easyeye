<?php

declare(strict_types=1);

namespace App\Jobs\WhatsApp;

use App\Models\WhatsApp\WhatsAppSetting;
use App\Services\WhatsApp\ZApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Illuminate\Support\Facades\Log;

/**
 * Envia o código OTP de verificação de WhatsApp do registro (/register) pela
 * instância GLOBAL Z-API do SaaS.
 *
 * Diferente do SendWhatsAppMessageJob (que serializa um WhatsAppMessage de
 * clínica/agenda), aqui não há entity: o destinatário é o responsável da
 * empresa recém-registrada, e a mensagem é transacional de autenticação —
 * não entra na trilha whatsapp_messages de conversa por clínica.
 *
 * O CÓDIGO viaja no payload do job (fila database, TTL do código 10 min);
 * o hash no banco continua sendo a única fonte de validação.
 */
class SendPhoneVerificationCodeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> backoff progressivo entre tentativas (segundos) */
    public array $backoff = [10, 60];

    public function __construct(
        public readonly string $userId,
        public readonly string $phone,
        public readonly string $code,
    ) {
    }

    public function handle(ZApiClient $client): void
    {
        $setting = WhatsAppSetting::globalSetting();

        if (! $setting || ! $setting->isOperational()) {
            Log::warning('[whatsapp:verification] instância global indisponível — código não enviado.', [
                'user_id' => $this->userId,
            ]);

            return;
        }

        $message = "EasyEye: seu código de verificação é {$this->code}. "
            . 'Válido por 10 minutos. Se você não solicitou, ignore esta mensagem.';

        $result = $client->sendText($setting, $this->phone, $message);

        if (! $result['ok']) {
            Log::warning('[whatsapp:verification] falha no envio do código.', [
                'user_id' => $this->userId,
                'error'   => $result['error'] ?? 'unknown',
            ]);

            // Relança para o retry/backoff do job atuar.
            $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)]);
        }
    }
}
