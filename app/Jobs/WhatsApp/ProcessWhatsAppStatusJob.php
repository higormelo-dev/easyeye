<?php

declare(strict_types=1);

namespace App\Jobs\WhatsApp;

use App\Models\WhatsApp\{WhatsAppMessage, WhatsAppOptOut, WhatsAppSetting};
use App\Services\WhatsApp\Providers\GupshupProvider;
use App\Services\WhatsApp\{WhatsAppAlerts, WhatsAppService};
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Aplica UM evento de status (sent/delivered/read/failed) do webhook v3 à
 * mensagem enviada. Idempotente: só preenche o que ainda está vazio, então
 * reentrega e ordem trocada não estragam nada.
 *
 * Ids do evento (https://partner-docs.gupshup.io/docs/passthrough-v3-incoming-events
 * e https://partner-docs.gupshup.io/docs/bsuid): gs_id = id Gupshup (o
 * devolvido no envio), id = id da mensagem, meta_msg_id = wamid da Meta (o
 * que volta no context.id da resposta do paciente). Falha: code/reason no
 * próprio status (Gupshup) ou errors[] (formato Meta).
 *
 * Status de mensagem desconhecida (enviada por outro sistema/console no
 * mesmo app, ou anterior à migração) é descartado com log de debug — só
 * volta para a fila se houver envio deste app em andamento (`sending`): é a
 * corrida em que o status chega antes de o envio gravar o id.
 *
 * Falha TRANSITÓRIA (saldo da carteira — Gupshup 1003 —, limite de envio,
 * Meta indisponível...) em confirmação/pesquisa: a mensagem não foi entregue
 * e fica `skipped` com o error_code (o comando a reenfileira — não se
 * perde); saldo também gera alerta.
 *
 * @phpstan-type StatusEvent array{gs_id?: ?string, id?: ?string, meta_msg_id?: ?string, status: string, timestamp?: ?string, code?: int|string|null, reason?: ?string}
 */
class ProcessWhatsAppStatusJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 4;

    public int $backoff = 20;

    /** @param StatusEvent $event */
    public function __construct(
        public readonly string $settingId,
        public readonly array $event,
    ) {
        $this->onQueue((string) config('whatsapp.queue', 'default'));
    }

    public function handle(WhatsAppService $service): void
    {
        $ids = array_values(array_filter([
            $this->event['gs_id'] ?? null,
            $this->event['id'] ?? null,
            $this->event['meta_msg_id'] ?? null,
        ], fn ($v) => is_string($v) && $v !== ''));

        if ($ids === []) {
            return;
        }

        $message = WhatsAppMessage::query()
            ->where('direction', 'out')
            ->where('whatsapp_setting_id', $this->settingId)
            ->where(fn ($q) => $q->whereIn('provider_message_id', $ids)->orWhereIn('wa_message_id', $ids))
            ->first();

        if ($message === null) {
            $inFlight = WhatsAppMessage::query()
                ->where('whatsapp_setting_id', $this->settingId)
                ->where('status', WhatsAppMessage::STATUS_SENDING)
                ->exists();

            if ($inFlight && $this->attempts() < $this->tries) {
                $this->release($this->backoff);

                return;
            }

            Log::debug('[whatsapp:status] status de mensagem desconhecida descartado.', [
                'setting_id' => $this->settingId,
                'status'     => $this->event['status'],
            ]);

            return;
        }

        $at      = $this->timestamp();
        $updates = [];
        $wamid   = collect([$this->event['meta_msg_id'] ?? null, $this->event['id'] ?? null])
            ->first(fn ($v) => is_string($v) && str_starts_with($v, 'wamid.'));

        if ($wamid !== null && $message->wa_message_id === null) {
            $updates['wa_message_id'] = $wamid;
        }

        switch ($this->event['status']) {
            case 'read':
                $updates['read_at']      = $message->read_at ?? $at;
                $updates['delivered_at'] = $message->delivered_at ?? $at;

                break;
            case 'delivered':
                $updates['delivered_at'] = $message->delivered_at ?? $at;

                break;
            case 'failed':
                $code       = $this->event['code'] ?? null;
                $classified = is_numeric($code) ? GupshupProvider::classifyCode((int) $code) : ['error_code' => 'failed'];

                $updates['failed_at']  = $message->failed_at ?? $at;
                $updates['error_code'] = $classified['error_code'];
                $updates['error']      = mb_substr((string) ($this->event['reason'] ?? 'Falha informada pela Meta/Gupshup.'), 0, 1000);

                // Respondida continua respondida (a resposta já chegou).
                if (in_array($message->status, [WhatsAppMessage::STATUS_PENDING, WhatsAppMessage::STATUS_SENDING, WhatsAppMessage::STATUS_SENT], true)) {
                    $walletLow = $classified['error_code'] === 'gupshup_' . GupshupProvider::WALLET_LOW;

                    // Falha transitória: confirmação/pesquisa volta a ser
                    // reenfileirável (o comando reaproveita a linha).
                    $updates['status'] = GupshupProvider::isTransientErrorCode($classified['error_code'])
                        && in_array($message->kind, [WhatsAppMessage::KIND_CONFIRMATION, WhatsAppMessage::KIND_SURVEY], true)
                        ? WhatsAppMessage::STATUS_SKIPPED
                        : WhatsAppMessage::STATUS_FAILED;

                    if ($walletLow) {
                        WhatsAppAlerts::critical('wallet_low', [
                            'setting_id' => $this->settingId,
                            'app_id'     => WhatsAppSetting::query()->whereKey($this->settingId)->value('app_id'),
                        ]);
                    }
                }

                if ($classified['error_code'] === 'opted_out' && ($setting = WhatsAppSetting::find($this->settingId))) {
                    $service->optOut($setting, $message->phone, WhatsAppOptOut::SOURCE_PROVIDER);
                }

                break;
        }

        if ($updates !== []) {
            $message->update($updates);
        }
    }

    private function timestamp(): Carbon
    {
        $ts = $this->event['timestamp'] ?? null;

        return is_numeric($ts) ? Carbon::createFromTimestamp((int) $ts, (string) config('app.timezone')) : now();
    }
}
