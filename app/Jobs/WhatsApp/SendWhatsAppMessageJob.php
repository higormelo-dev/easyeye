<?php

declare(strict_types=1);

namespace App\Jobs\WhatsApp;

use App\Models\WhatsApp\{WhatsAppMessage, WhatsAppSetting};
use App\Services\WhatsApp\ZApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\{ShouldBeUnique, ShouldQueue};
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use RuntimeException;
use Throwable;

/**
 * Envia UMA mensagem outbound (linha pending de whatsapp_messages) via Z-API.
 *
 * Retry no nível da fila (tries/backoff) — sem Http::retry, mesmo padrão dos
 * gateways de billing. Falha esperada da API (ok=false) lança RuntimeException
 * pra acionar o retry; esgotadas as tentativas, failed() marca a linha como
 * failed com o último erro (nunca perde a trilha).
 */
class SendWhatsAppMessageJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public int $timeout = 60;

    public function __construct(public readonly string $messageId)
    {
        $this->onQueue((string) config('whatsapp.queue', 'default'));
    }

    public function uniqueId(): string
    {
        return $this->messageId;
    }

    public function handle(ZApiClient $client): void
    {
        $message = WhatsAppMessage::find($this->messageId);

        if (! $message || $message->status !== WhatsAppMessage::STATUS_PENDING) {
            return; // já enviada/cancelada — nada a fazer
        }

        $setting = WhatsAppSetting::query()
            ->where('entity_id', $message->entity_id)
            ->first();

        if (! $setting || ! $setting->isOperational()) {
            $message->update([
                'status' => WhatsAppMessage::STATUS_FAILED,
                'error'  => 'Configuração WhatsApp inativa ou sem credenciais.',
            ]);

            return;
        }

        $result = $client->sendText($setting, $message->phone, $message->body);

        if (! $result['ok']) {
            // Lança pra fila re-tentar; o erro fica registrado desde já.
            $message->update(['error' => mb_substr((string) ($result['error'] ?? 'erro desconhecido'), 0, 1000)]);

            throw new RuntimeException('Z-API send failed: ' . ($result['error_code'] ?? 'unknown'));
        }

        $message->update([
            'status'          => WhatsAppMessage::STATUS_SENT,
            'zapi_message_id' => $result['message_id'] ?? null,
            'sent_at'         => now(),
            'error'           => null,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        WhatsAppMessage::query()
            ->whereKey($this->messageId)
            ->where('status', WhatsAppMessage::STATUS_PENDING)
            ->update([
                'status' => WhatsAppMessage::STATUS_FAILED,
                'error'  => mb_substr($exception->getMessage(), 0, 1000),
            ]);
    }
}
