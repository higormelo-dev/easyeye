<?php

declare(strict_types=1);

namespace App\Jobs\WhatsApp;

use App\Models\WhatsApp\{WhatsAppMessage, WhatsAppSetting};
use App\Services\WhatsApp\{WhatsAppService, ZApiClient};
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\{ShouldBeUnique, ShouldQueue};
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Throwable;

/**
 * Processa UMA mensagem inbound (linha received de whatsapp_messages, criada
 * pelo webhook): parseia a resposta do paciente (1/2 da confirmação, 1-5 da
 * pesquisa) via WhatsAppService e envia o agradecimento/ack quando houver.
 *
 * Ingest rápido + processamento assíncrono — mesmo padrão do webhook de
 * billing (ProcessBillingWebhookJob).
 */
class ProcessWhatsAppInboundJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 60;

    public function __construct(public readonly string $inboundMessageId)
    {
        $this->onQueue((string) config('whatsapp.queue', 'default'));
    }

    public function uniqueId(): string
    {
        return $this->inboundMessageId;
    }

    public function handle(WhatsAppService $service, ZApiClient $client): void
    {
        $inbound = WhatsAppMessage::find($this->inboundMessageId);

        if (! $inbound || $inbound->direction !== 'in') {
            return;
        }

        $setting = WhatsAppSetting::query()
            ->where('entity_id', $inbound->entity_id)
            ->first();

        if (! $setting) {
            return;
        }

        $ack = $service->handleInbound($setting, $inbound->phone, $inbound->body);

        if ($ack === null || ! $setting->isOperational()) {
            return;
        }

        // Ack direto (sem passar pela fila de novo): resposta imediata dá a
        // sensação de conversa; se falhar, registra mas não re-tenta — o ack
        // é cortesia, o efeito principal (confirmação/score) já foi aplicado.
        $result = $client->sendText($setting, $inbound->phone, $ack);

        WhatsAppMessage::create([
            'entity_id'       => $inbound->entity_id,
            'schedule_id'     => null,
            'direction'       => 'out',
            'kind'            => WhatsAppMessage::KIND_ACK,
            'phone'           => $inbound->phone,
            'body'            => $ack,
            'status'          => $result['ok'] ? WhatsAppMessage::STATUS_SENT : WhatsAppMessage::STATUS_FAILED,
            'zapi_message_id' => $result['message_id'] ?? null,
            'sent_at'         => $result['ok'] ? now() : null,
            'error'           => $result['ok'] ? null : mb_substr((string) ($result['error'] ?? ''), 0, 1000),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        WhatsAppMessage::query()
            ->whereKey($this->inboundMessageId)
            ->update(['error' => mb_substr($exception->getMessage(), 0, 1000)]);
    }
}
