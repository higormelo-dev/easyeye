<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\WhatsApp\{ProcessWhatsAppInboundJob, ProcessWhatsAppStatusJob};
use App\Models\WhatsApp\{WhatsAppMessage, WhatsAppSetting};
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Database\QueryException;
use Illuminate\Http\{Request, Response};
use Illuminate\Support\Facades\DB;

/**
 * Webhook v3 da Gupshup (formato da Meta, com gs_app_id na raiz): mensagens
 * do paciente (texto, clique em botão) e status das mensagens enviadas.
 *   https://partner-docs.gupshup.io/docs/passthrough-v3-incoming-events
 *   https://partner-docs.gupshup.io/docs/set-callback-url-1
 *   https://developers.facebook.com/documentation/business-messaging/whatsapp/webhooks/reference/messages/button.
 *
 * Autenticação (a Gupshup não assina o corpo): token aleatório na URL
 * (identifica a configuração) + header de segredo cadastrado no "meta" da
 * subscription (https://partner-docs.gupshup.io/reference/setsubscription-api-v3),
 * comparado com hash_equals, + gs_app_id igual ao app da configuração.
 * Qualquer divergência → 404 genérico (não confirma o que existe).
 *
 * Responde 2xx vazio na hora (a Gupshup reenvia se passar de 10s —
 * https://partner-docs.gupshup.io/docs/webhook-key-points); o trabalho vai
 * para a fila. Idempotente pelo id do provedor (reentrega não duplica;
 * mensagem sem id é deduplicada pelo hash de telefone+timestamp+conteúdo).
 * Limite de chamadas por token da URL (RateLimiter whatsapp-webhook), não
 * por IP — atrás do proxy o IP vem de X-Forwarded-For, que é forjável.
 */
class GupshupWebhookController extends Controller
{
    public function __invoke(Request $request, string $token): Response
    {
        $setting = WhatsAppSetting::query()->where('webhook_token', $token)->first();

        if (! $setting || ! $setting->hasApp()) {
            abort(404);
        }

        $secret = (string) $setting->webhook_secret;
        $header = (string) $request->header(WhatsAppSetting::WEBHOOK_SECRET_HEADER, '');

        if ($secret === '' || ! hash_equals($secret, $header)) {
            abort(404);
        }

        $payload = $request->all();
        $appId   = (string) ($payload['gs_app_id'] ?? '');

        if ($appId !== '' && $appId !== $setting->app_id) {
            abort(404);
        }

        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                if (($change['field'] ?? 'messages') !== 'messages') {
                    continue;
                }

                $value = (array) ($change['value'] ?? []);

                foreach ((array) ($value['messages'] ?? []) as $message) {
                    $this->ingestMessage($setting, (array) $message);
                }

                foreach ((array) ($value['statuses'] ?? []) as $status) {
                    $this->ingestStatus($setting, (array) $status);
                }
            }
        }

        return response('', 200);
    }

    /** @param array<string, mixed> $message */
    private function ingestMessage(WhatsAppSetting $setting, array $message): void
    {
        $type = (string) ($message['type'] ?? '');

        [$text, $buttonPayload] = match ($type) {
            'text'   => [(string) data_get($message, 'text.body', ''), null],
            'button' => [(string) data_get($message, 'button.text', ''), data_get($message, 'button.payload')],
            // Botões de mensagem interativa (sessão): id = payload, title = rótulo.
            'interactive' => [
                (string) (data_get($message, 'interactive.button_reply.title') ?? data_get($message, 'interactive.list_reply.title') ?? ''),
                data_get($message, 'interactive.button_reply.id') ?? data_get($message, 'interactive.list_reply.id'),
            ],
            default => ['', null],
        };

        // Sem número (usuário com BSUID que ocultou o telefone) ou sem
        // conteúdo que o sistema entenda: ignora.
        $phone = WhatsAppService::normalizePhone('+' . ltrim((string) ($message['from'] ?? ''), '+'));

        if ($phone === null || (trim($text) === '' && blank($buttonPayload))) {
            return;
        }

        try {
            // Savepoint: a reentrega do mesmo id cai no unique parcial
            // whatsapp_messages_inbound_once sem envenenar transação externa.
            $inbound = DB::transaction(fn () => WhatsAppMessage::create([
                'entity_id'           => $setting->entity_id,
                'whatsapp_setting_id' => $setting->id,
                'direction'           => 'in',
                'kind'                => WhatsAppMessage::KIND_REPLY,
                'phone'               => $phone,
                'body'                => mb_substr($text, 0, 2000),
                'status'              => WhatsAppMessage::STATUS_RECEIVED,
                // Sem id (não deveria acontecer): chave pelo conteúdo, para a
                // reentrega cair no mesmo unique em vez de duplicar.
                'provider_message_id' => (string) ($message['id'] ?? '') ?: 'noid:' . sha1(implode('|', [
                    $phone, (string) ($message['timestamp'] ?? ''), $type, $text, is_string($buttonPayload) ? $buttonPayload : '',
                ])),
                // Só o necessário para casar a resposta — sem nome de perfil.
                'payload' => array_filter([
                    'type'           => $type,
                    'button_payload' => is_string($buttonPayload) ? mb_substr($buttonPayload, 0, 200) : null,
                    'button_text'    => $type !== 'text' ? mb_substr($text, 0, 100) : null,
                    'context_id'     => data_get($message, 'context.id'),
                    'timestamp'      => $message['timestamp'] ?? null,
                ], fn ($v) => $v !== null && $v !== ''),
            ]));
        } catch (QueryException $e) {
            if (in_array($e->getCode(), ['23000', '23505'], true)) {
                return; // reentrega do mesmo id — já ingerido
            }

            throw $e;
        }

        ProcessWhatsAppInboundJob::dispatch((string) $inbound->id)->afterCommit();
    }

    /** @param array<string, mixed> $status */
    private function ingestStatus(WhatsAppSetting $setting, array $status): void
    {
        $state = (string) ($status['status'] ?? '');

        if (! in_array($state, ['sent', 'delivered', 'read', 'failed'], true)) {
            return; // enqueued, set-callback etc.
        }

        ProcessWhatsAppStatusJob::dispatch((string) $setting->id, [
            'gs_id'       => $status['gs_id'] ?? null,
            'id'          => $status['id'] ?? null,
            'meta_msg_id' => $status['meta_msg_id'] ?? null,
            'status'      => $state,
            'timestamp'   => $status['timestamp'] ?? null,
            'code'        => $status['code'] ?? data_get($status, 'errors.0.code'),
            'reason'      => $status['reason'] ?? data_get($status, 'errors.0.title') ?? data_get($status, 'errors.0.message'),
        ])->afterCommit();
    }
}
