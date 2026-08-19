<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

use App\Models\WhatsApp\WhatsAppSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\{Http, Log};
use Illuminate\Support\Str;

/**
 * Cliente HTTP da Z-API (https://developer.z-api.io) — cada clínica tem sua
 * PRÓPRIA instância (número de WhatsApp próprio): as credenciais vêm sempre
 * do WhatsAppSetting da entity, nunca de env global.
 *
 * Contrato Z-API usado:
 *   POST {base}/instances/{instance}/token/{token}/send-text     {phone, message}
 *   GET  {base}/instances/{instance}/token/{token}/status
 *   PUT  {base}/instances/{instance}/token/{token}/update-webhook-received {value}
 * Header obrigatório em todas: Client-Token (token de segurança da conta).
 *
 * Driver mock (config whatsapp.driver=mock): não sai HTTP — loga e devolve
 * resposta fake. Mesmo padrão do transporte TISS (config tiss.transport.driver).
 *
 * Retorno SEMPRE array estruturado {ok, ...} — nunca lança em falha esperada
 * (o job decide retry via status); ConnectionException vira ok=false com
 * error_code=connection (mesma distinção do AbstractHttpGateway).
 */
class ZApiClient
{
    /**
     * @return array{ok: bool, message_id?: string, error?: string, error_code?: string}
     */
    public function sendText(WhatsAppSetting $setting, string $phone, string $message): array
    {
        if ($this->isMock()) {
            Log::info('[whatsapp:mock] send-text', ['phone' => $phone, 'message' => Str::limit($message, 120)]);

            return ['ok' => true, 'message_id' => 'mock-' . Str::uuid()->toString()];
        }

        $response = $this->request($setting, 'POST', 'send-text', [
            'phone'   => $phone,
            'message' => $message,
        ]);

        if (! $response['ok']) {
            return $response;
        }

        // Z-API devolve {zaapId, messageId, id} — messageId é o id da mensagem
        // no WhatsApp (o mesmo que volta no webhook de recebimento).
        $body = $response['body'];

        return [
            'ok'         => true,
            'message_id' => (string) ($body['messageId'] ?? $body['id'] ?? $body['zaapId'] ?? ''),
        ];
    }

    /**
     * Status da instância — usado pelo botão "Testar conexão" das configurações.
     *
     * @return array{ok: bool, connected?: bool, error?: string}
     */
    public function status(WhatsAppSetting $setting): array
    {
        if ($this->isMock()) {
            return ['ok' => true, 'connected' => true];
        }

        $response = $this->request($setting, 'GET', 'status');

        if (! $response['ok']) {
            return $response;
        }

        return [
            'ok'        => true,
            'connected' => (bool) ($response['body']['connected'] ?? false),
        ];
    }

    /**
     * Aponta o webhook "ao receber" da instância pra URL desta clínica —
     * chamado automaticamente ao salvar as configurações (o admin não precisa
     * colar URL no painel da Z-API na mão).
     *
     * @return array{ok: bool, error?: string}
     */
    public function updateReceivedWebhook(WhatsAppSetting $setting, string $url): array
    {
        if ($this->isMock()) {
            Log::info('[whatsapp:mock] update-webhook-received', ['url' => $url]);

            return ['ok' => true];
        }

        $response = $this->request($setting, 'PUT', 'update-webhook-received', ['value' => $url]);

        return $response['ok'] ? ['ok' => true] : $response;
    }

    private function isMock(): bool
    {
        return config('whatsapp.driver', 'mock') !== 'zapi';
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{ok: bool, body?: array<string, mixed>, error?: string, error_code?: string}
     */
    private function request(WhatsAppSetting $setting, string $method, string $endpoint, array $payload = []): array
    {
        $credentials = $setting->credentials ?? [];
        $instanceId  = (string) ($credentials['instance_id'] ?? '');
        $token       = (string) ($credentials['instance_token'] ?? '');
        $clientToken = (string) ($credentials['client_token'] ?? '');

        if ($instanceId === '' || $token === '' || $clientToken === '') {
            return ['ok' => false, 'error' => 'Credenciais Z-API não configuradas.', 'error_code' => 'missing_credentials'];
        }

        $url = rtrim((string) config('whatsapp.zapi.base_url'), '/')
            . "/instances/{$instanceId}/token/{$token}/{$endpoint}";

        try {
            $client = Http::timeout((int) config('whatsapp.http.timeout_seconds', 15))
                ->connectTimeout((int) config('whatsapp.http.connect_timeout_seconds', 5))
                ->withHeaders([
                    'Client-Token' => $clientToken,
                    'Accept'       => 'application/json',
                ]);

            $response = match (strtoupper($method)) {
                'GET'   => $client->get($url),
                'PUT'   => $client->put($url, $payload),
                default => $client->post($url, $payload),
            };
        } catch (ConnectionException $e) {
            return ['ok' => false, 'error' => 'Falha de conexão com a Z-API.', 'error_code' => 'connection'];
        }

        if ($response->failed()) {
            return [
                'ok' => false,
                // NUNCA logar/persistir a URL (contém o instance_token).
                'error'      => 'Z-API HTTP ' . $response->status() . ': ' . mb_substr($response->body(), 0, 500),
                'error_code' => 'http_' . $response->status(),
            ];
        }

        return ['ok' => true, 'body' => (array) $response->json()];
    }
}
