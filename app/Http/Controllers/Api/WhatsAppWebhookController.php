<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\WhatsApp\ProcessWhatsAppInboundJob;
use App\Models\WhatsApp\{WhatsAppMessage, WhatsAppSetting};
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Database\QueryException;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\DB;

/**
 * Webhook "ao receber" da Z-API (mensagens que o paciente manda pro número
 * da clínica). Padrão do webhook de billing: ingest rápido + idempotente
 * (unique whatsapp_messages_inbound_once por zapi_message_id) + job assíncrono.
 *
 * Identificação do tenant: webhook_token aleatório na URL (configurado
 * automaticamente na instância Z-API ao salvar as credenciais) — cruzado com
 * o instanceId do payload como defesa extra: mesmo que alguém descubra a URL,
 * precisa também mandar o instanceId correto da clínica.
 */
class WhatsAppWebhookController extends Controller
{
    public function __invoke(Request $request, string $token): JsonResponse
    {
        $setting = WhatsAppSetting::query()
            ->where('webhook_token', $token)
            ->first();

        if (! $setting) {
            // 404 genérico: não confirma se o token existe (anti-enumeração).
            abort(404);
        }

        $payload = $request->all();

        // Só mensagens recebidas de terceiros: ignora status de entrega,
        // mensagens enviadas pela própria instância (fromMe) e callbacks
        // que não são de recebimento.
        $type = (string) ($payload['type'] ?? '');

        if ($type !== '' && $type !== 'ReceivedCallback') {
            return response()->json(['ok' => true, 'ignored' => 'type']);
        }

        if ((bool) ($payload['fromMe'] ?? false) || (bool) ($payload['isStatusReply'] ?? false)) {
            return response()->json(['ok' => true, 'ignored' => 'fromMe/status']);
        }

        // Cross-check: payload precisa vir da instância desta clínica.
        $instanceId = (string) ($payload['instanceId'] ?? '');

        if ($setting->instance_id && $instanceId !== '' && $instanceId !== $setting->instance_id) {
            abort(404);
        }

        $text = (string) ($payload['text']['message'] ?? '');

        if (trim($text) === '') {
            return response()->json(['ok' => true, 'ignored' => 'empty']);
        }

        $phone = WhatsAppService::normalizePhone((string) ($payload['phone'] ?? ''));

        if ($phone === null) {
            return response()->json(['ok' => true, 'ignored' => 'phone']);
        }

        try {
            // DB::transaction (savepoint quando já há transação externa): o
            // unique violation do dedup faz rollback só do savepoint, sem
            // envenenar a transação de fora — mesmo padrão do
            // WebhookIngestionService de billing.
            $inbound = DB::transaction(fn () => WhatsAppMessage::create([
                'entity_id'       => $setting->entity_id,
                'direction'       => 'in',
                'kind'            => WhatsAppMessage::KIND_REPLY,
                'phone'           => $phone,
                'body'            => mb_substr($text, 0, 2000),
                'status'          => WhatsAppMessage::STATUS_RECEIVED,
                'zapi_message_id' => (string) ($payload['messageId'] ?? '') ?: null,
                // Payload cru pra auditoria/debug — sem dados de credencial.
                'payload' => [
                    'instanceId' => $instanceId,
                    'senderName' => (string) ($payload['senderName'] ?? ''),
                    'momment'    => $payload['momment'] ?? null,
                ],
            ]));
        } catch (QueryException $e) {
            if (in_array($e->getCode(), ['23000', '23505'], true)) {
                // Redelivery do mesmo messageId — já ingerido, responde ok.
                return response()->json(['ok' => true, 'duplicate' => true]);
            }

            throw $e;
        }

        ProcessWhatsAppInboundJob::dispatch((string) $inbound->id)->afterCommit();

        return response()->json(['ok' => true, 'id' => $inbound->id]);
    }
}
