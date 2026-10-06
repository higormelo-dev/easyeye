<?php

declare(strict_types=1);

namespace App\Jobs\WhatsApp;

use App\Models\WhatsApp\{WhatsAppMessage, WhatsAppOptOut, WhatsAppSetting};
use App\Services\Billing\ClinicServiceGate;
use App\Services\WhatsApp\Contracts\WhatsAppProvider;
use App\Services\WhatsApp\Providers\GupshupProvider;
use App\Services\WhatsApp\{WhatsAppService, WhatsAppTemplates};
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\{ShouldBeUnique, ShouldQueue};
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use RuntimeException;
use Throwable;

/**
 * Envia UMA mensagem outbound (linha pending de whatsapp_messages) como
 * template aprovado na Meta, pelo app da clínica (se tiver) ou o global.
 *
 * Sem envio em duplicidade: a linha passa a `sending` (update atômico)
 * ANTES da chamada; um job que encontra `sending` não reenvia (a chamada
 * anterior pode ter saído) — vira failed com error_code unknown_delivery.
 * Timeout de leitura também vira unknown_delivery, sem nova tentativa.
 *
 * A autenticação do app (token em cache; gerar um novo pode esperar o lock
 * de outro worker) acontece ANTES da reserva: demora ou falha ali nunca
 * deixa a linha em `sending` sem que nada tenha saído.
 *
 * Retry no nível da fila (tries/backoff), só para falha transitória que com
 * certeza não enviou (5xx, 429, falha de conexão antes de enviar,
 * indisponibilidade da Meta, token universal recusado, saldo) — a linha
 * volta para pending e o job lança; esgotadas as tentativas, failed() deixa
 * a linha `skipped` com o error_code (o comando a reenfileira na próxima
 * rodada — a mensagem não se perde). Falha permanente (template inexistente,
 * número inválido, 4xx de validação) vira failed na hora, sem nova tentativa.
 *
 * $timeout (150 s) cobre o pior caso de uma chamada (renovação do token em
 * 401 com o lock de 120 s + envio); o retry_after da conexão da fila
 * (REDIS_QUEUE_RETRY_AFTER / DB_QUEUE_RETRY_AFTER) precisa ser MAIOR que
 * ele — ver docs/infra/reverb-ambiente-teste.md.
 */
class SendWhatsAppMessageJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public int $timeout = 150;

    public function __construct(public readonly string $messageId)
    {
        $this->onQueue((string) config('whatsapp.queue', 'default'));
    }

    public function uniqueId(): string
    {
        return $this->messageId;
    }

    public function handle(WhatsAppProvider $provider, WhatsAppService $service, WhatsAppTemplates $templates, ClinicServiceGate $gate): void
    {
        $message = WhatsAppMessage::find($this->messageId);

        if ($message?->status === WhatsAppMessage::STATUS_SENDING) {
            // Tentativa anterior morreu no meio da chamada (worker caiu,
            // timeout do job): pode ter saído — não reenvia, fica para revisão.
            $message->update([
                'status'     => WhatsAppMessage::STATUS_FAILED,
                'failed_at'  => now(),
                'error_code' => 'unknown_delivery',
                'error'      => 'Envio interrompido no meio da chamada à Gupshup: a mensagem pode ter saído — não reenviada (conferir).',
            ]);

            return;
        }

        if (! $message || $message->status !== WhatsAppMessage::STATUS_PENDING) {
            return; // já enviada/cancelada — nada a fazer
        }

        // Acesso da clínica bloqueado depois de enfileirar: não envia nem
        // re-tenta (sem fila infinita) — fica pulada com o motivo, e o
        // comando a reenfileira quando o acesso voltar.
        if (! $gate->allowsAutomation($message->entity_id ? (string) $message->entity_id : null)) {
            $message->update([
                'status'     => WhatsAppMessage::STATUS_SKIPPED,
                'error_code' => ClinicServiceGate::REASON_ACCESS_BLOCKED,
                'error'      => ClinicServiceGate::REASON_ACCESS_BLOCKED . ': acesso da clínica bloqueado (assinatura) — envio automático suspenso.',
            ]);

            return;
        }

        $setting = WhatsAppSetting::query()->where('entity_id', $message->entity_id)->first();

        // App próprio da clínica, se cadastrado; senão o global do EasyEye.
        $sender = $setting?->active ? $setting->sendingSetting() : null;

        if ($sender === null) {
            $message->update([
                'status'     => WhatsAppMessage::STATUS_FAILED,
                'failed_at'  => now(),
                'error_code' => 'missing_app',
                'error'      => 'Configuração WhatsApp inativa ou sem app (próprio ou global).',
            ]);

            return;
        }

        // Descadastrado depois de enfileirar: não envia e não volta à fila.
        if ($service->isSuppressed($sender, $message->phone)) {
            $message->update([
                'status'              => WhatsAppMessage::STATUS_SUPPRESSED,
                'whatsapp_setting_id' => $sender->id,
                'error_code'          => 'opted_out',
                'error'               => 'Paciente pediu para não receber mensagens deste número (SAIR).',
            ]);

            return;
        }

        $payload  = (array) ($message->payload ?? []);
        $template = $templates->build(
            (string) ($payload['template_key'] ?? ''),
            (array) ($payload['values'] ?? []),
            (string) ($payload['locale'] ?? 'pt_BR'),
            ['message_id' => (string) $message->id],
        );

        // Autentica ANTES de reservar (nada saiu ainda se falhar aqui).
        $auth = $provider->authorize($sender);

        if (! $auth['ok']) {
            $this->recordFailure($message, $sender, $auth);

            return;
        }

        // Reserva a linha (pending → sending) antes de chamar a Gupshup: outro
        // worker com a mesma mensagem não envia de novo.
        $claimed = WhatsAppMessage::query()
            ->whereKey($message->id)
            ->where('status', WhatsAppMessage::STATUS_PENDING)
            ->update([
                'status'              => WhatsAppMessage::STATUS_SENDING,
                'template'            => $template->name,
                'whatsapp_setting_id' => $sender->id,
                'sender_app_id'       => $sender->app_id,
                'updated_at'          => now(),
            ]);

        if ($claimed === 0) {
            return;
        }

        $message->refresh();

        $result = $provider->sendTemplate($sender, $message->phone, $template);

        if ($result['ok']) {
            // Explícito (forceFill): erro e falha de tentativas anteriores
            // não ficam numa mensagem enviada.
            $message->forceFill([
                'status'              => WhatsAppMessage::STATUS_SENT,
                'provider_message_id' => $result['message_id'] ?? null,
                'sent_at'             => now(),
                'failed_at'           => null,
                'error'               => null,
                // no_message_id: a Gupshup aceitou (2xx) sem devolver o id.
                'error_code' => $result['error_code'] ?? null,
            ])->save();

            return;
        }

        if (($result['error_code'] ?? null) === 'opted_out') {
            $service->optOut($sender, $message->phone, WhatsAppOptOut::SOURCE_PROVIDER);
        }

        $this->recordFailure($message, $sender, $result);
    }

    /**
     * Falha na autenticação ou no envio: transitória (com certeza não saiu)
     * → volta para pending e lança (a fila tenta de novo); permanente ou
     * ambígua (unknown_delivery — pode ter saído) → failed na hora.
     *
     * @param array{error?: string, error_code?: ?string, retryable?: bool} $result
     */
    private function recordFailure(WhatsAppMessage $message, WhatsAppSetting $sender, array $result): void
    {
        $error = mb_substr((string) ($result['error'] ?? 'erro desconhecido'), 0, 1000);

        if ($result['retryable'] ?? false) {
            // Com certeza não saiu: volta para pending e lança pra fila
            // re-tentar; o erro fica registrado desde já.
            $message->update(['status' => WhatsAppMessage::STATUS_PENDING, 'error' => $error, 'error_code' => $result['error_code'] ?? null]);

            throw new RuntimeException('WhatsApp send failed: ' . ($result['error_code'] ?? 'unknown'));
        }

        $message->update([
            'status'              => WhatsAppMessage::STATUS_FAILED,
            'whatsapp_setting_id' => $sender->id,
            'sender_app_id'       => $sender->app_id,
            'failed_at'           => now(),
            'error'               => $error,
            'error_code'          => $result['error_code'] ?? null,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $message = WhatsAppMessage::query()
            ->whereKey($this->messageId)
            ->whereIn('status', [WhatsAppMessage::STATUS_PENDING, WhatsAppMessage::STATUS_SENDING])
            ->first();

        if ($message === null) {
            return;
        }

        // Tentativas esgotadas por falha TRANSITÓRIA (saldo da carteira,
        // token universal recusado, limite de UATs, 5xx, conexão...): nada
        // saiu e a mensagem não se perde — fica pulada com o error_code e o
        // comando a reenfileira na próxima rodada (os casos que pedem uma
        // pessoa já foram alertados).
        if ($message->status === WhatsAppMessage::STATUS_PENDING && GupshupProvider::isTransientErrorCode($message->error_code)) {
            $message->update(['status' => WhatsAppMessage::STATUS_SKIPPED]);

            return;
        }

        $message->update([
            'status'     => WhatsAppMessage::STATUS_FAILED,
            'failed_at'  => now(),
            'error_code' => $message->status === WhatsAppMessage::STATUS_SENDING ? 'unknown_delivery' : $message->error_code,
            'error'      => mb_substr($exception->getMessage(), 0, 1000),
        ]);
    }
}
