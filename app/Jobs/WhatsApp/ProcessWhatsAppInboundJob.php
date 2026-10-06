<?php

declare(strict_types=1);

namespace App\Jobs\WhatsApp;

use App\Models\WhatsApp\{WhatsAppMessage, WhatsAppSetting};
use App\Services\Billing\ClinicServiceGate;
use App\Services\WhatsApp\Contracts\WhatsAppProvider;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\{ShouldBeUnique, ShouldQueue};
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Throwable;

/**
 * Processa UMA mensagem recebida (linha received de whatsapp_messages,
 * criada pelo webhook): clique em botão, resposta em texto ou SAIR/VOLTAR
 * (WhatsAppService::handleInbound) e envia a resposta automática.
 *
 * A resposta automática é texto livre: só vale dentro da janela de 24h que a
 * mensagem do paciente abriu — fora dela (fila muito atrasada) não sai.
 * Paciente descadastrado (SAIR) naquele número só recebe a confirmação do
 * próprio SAIR e do VOLTAR (handleInbound devolve null nos demais casos).
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

    /** Envia a resposta automática (pode renovar o token: lock de 120 s). */
    public int $timeout = 150;

    public function __construct(public readonly string $inboundMessageId)
    {
        $this->onQueue((string) config('whatsapp.queue', 'default'));
    }

    public function uniqueId(): string
    {
        return $this->inboundMessageId;
    }

    public function handle(WhatsAppService $service, WhatsAppProvider $provider, ClinicServiceGate $gate): void
    {
        $inbound = WhatsAppMessage::find($this->inboundMessageId);

        if (! $inbound || $inbound->direction !== 'in') {
            return;
        }

        // Número (app) que recebeu: o global do EasyEye ou o próprio da clínica.
        $receiver = $inbound->whatsapp_setting_id ? WhatsAppSetting::find($inbound->whatsapp_setting_id) : null;

        if (! $receiver) {
            return;
        }

        $ack = $service->handleInbound($receiver, $inbound);

        if ($ack === null || ! $receiver->isOperational()) {
            return;
        }

        // Fora da janela de 24h a Meta só aceita template: não envia.
        if ($inbound->created_at && $inbound->created_at->lt(now()->subDay())) {
            return;
        }

        $inbound->refresh();
        $entityId = $inbound->entity_id ? (string) $inbound->entity_id : null;

        // Resposta automática de clínica com o acesso bloqueado: o efeito da
        // resposta do paciente (confirmação/nota/SAIR) vale, mas nada sai —
        // fica o registro do motivo.
        if (! $gate->allowsAutomation($entityId)) {
            WhatsAppMessage::create([
                'entity_id'           => $entityId,
                'schedule_id'         => $inbound->schedule_id,
                'whatsapp_setting_id' => $receiver->id,
                'direction'           => 'out',
                'kind'                => WhatsAppMessage::KIND_ACK,
                'phone'               => $inbound->phone,
                'body'                => $ack,
                'status'              => WhatsAppMessage::STATUS_SKIPPED,
                'error_code'          => ClinicServiceGate::REASON_ACCESS_BLOCKED,
                'error'               => ClinicServiceGate::REASON_ACCESS_BLOCKED . ': acesso da clínica bloqueado (assinatura) — resposta automática suspensa.',
            ]);

            return;
        }

        // Ack direto (sem passar pela fila de novo): resposta imediata dá a
        // sensação de conversa; se falhar, registra mas não re-tenta — o ack
        // é cortesia, o efeito principal (confirmação/score) já foi aplicado.
        $result = $provider->sendSessionText($receiver, $inbound->phone, $ack);

        WhatsAppMessage::create([
            'entity_id'           => $entityId,
            'schedule_id'         => $inbound->schedule_id,
            'whatsapp_setting_id' => $receiver->id,
            'sender_app_id'       => $receiver->app_id,
            'direction'           => 'out',
            'kind'                => WhatsAppMessage::KIND_ACK,
            'phone'               => $inbound->phone,
            'body'                => $ack,
            'status'              => $result['ok'] ? WhatsAppMessage::STATUS_SENT : WhatsAppMessage::STATUS_FAILED,
            'provider_message_id' => $result['message_id'] ?? null,
            'sent_at'             => $result['ok'] ? now() : null,
            'failed_at'           => $result['ok'] ? null : now(),
            'error'               => $result['ok'] ? null : mb_substr((string) ($result['error'] ?? ''), 0, 1000),
            'error_code'          => $result['ok'] ? null : ($result['error_code'] ?? null),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        WhatsAppMessage::query()
            ->whereKey($this->inboundMessageId)
            ->update(['error' => mb_substr($exception->getMessage(), 0, 1000)]);
    }
}
