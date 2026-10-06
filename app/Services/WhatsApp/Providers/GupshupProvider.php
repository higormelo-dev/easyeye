<?php

declare(strict_types=1);

namespace App\Services\WhatsApp\Providers;

use App\Models\WhatsApp\WhatsAppSetting;
use App\Services\WhatsApp\Contracts\WhatsAppProvider;
use App\Services\WhatsApp\{TemplateMessage, WhatsAppAlerts};
use Illuminate\Http\Client\{ConnectionException, Response};
use Illuminate\Support\Str;

/**
 * WhatsApp oficial (Cloud API da Meta) pela Partner API da Gupshup.
 *
 * Endpoints (documentação oficial, conferida em 05/10/2026):
 *  - Envio v3 (template e texto de sessão), corpo no formato da Meta:
 *    POST /partner/app/{appId}/v3/message   Authorization: {token do app}
 *    https://partner-docs.gupshup.io/reference/post_partner-app-appid-v3-message-10
 *    https://partner-docs.gupshup.io/reference/post_partner-app-appid-v3-message-9
 *    https://partner-docs.gupshup.io/reference/post_partner-app-appid-v3-text-message
 *    Resposta: {"messages":[{"id":"<id Gupshup>"}], ...}
 *  - Saúde do app: GET /partner/app/{appId}/health → {"healthy":"true"}
 *    https://partner-docs.gupshup.io/reference/get_partner-app-appid-health
 *  - Webhook (subscription v3; "meta" vira header em cada chamada):
 *    GET/DELETE/POST /partner/app/{appId}/subscription[/{id}]
 *    https://partner-docs.gupshup.io/reference/setsubscription-api-v3
 *    https://partner-docs.gupshup.io/reference/get_partner-app-appid-subscription
 *    https://partner-docs.gupshup.io/reference/delete_partner-app-appid-subscription-subscriptionid
 *  - Códigos de erro: https://partner-docs.gupshup.io/docs/error-codes e
 *    https://developers.facebook.com/docs/whatsapp/cloud-api/support/error-codes
 *
 * Nunca loga nem devolve token/URL com segredo.
 */
class GupshupProvider implements WhatsAppProvider
{
    /**
     * Erros transitórios (nova tentativa vale a pena): Meta 2 (indisponível),
     * 4/80007/130429 (limite de chamadas), 131000 (erro desconhecido),
     * 131016 (serviço indisponível), 131056 (muitas mensagens ao mesmo
     * número); Gupshup 500 (erro interno), 4001 (limite de chamadas da
     * Gupshup — vem como 4xx, sem 429), 4002 (resposta inválida do WhatsApp)
     * e 1003 (saldo da carteira — volta quando recarregar; gera alerta).
     * https://partner-docs.gupshup.io/docs/error-codes.
     */
    private const TRANSIENT_CODES = [2, 4, 80007, 130429, 131000, 131016, 131056, 500, 1003, 4001, 4002];

    /** Gupshup 1003: saldo da carteira insuficiente. */
    public const WALLET_LOW = 1003;

    /** Códigos próprios da Gupshup (os demais são da Meta). */
    private const GUPSHUP_CODES = [1001, 1002, 1003, 1004, 1005, 1006, 1007, 1008, 1009, 1010, 1011, 1012, 4001, 4002, 4003, 4004, 4005];

    public function __construct(private readonly GupshupAuth $auth)
    {
    }

    public function name(): string
    {
        return 'gupshup';
    }

    public function authorize(WhatsAppSetting $setting): array
    {
        $appId = (string) $setting->app_id;

        if ($appId === '') {
            return ['ok' => false, 'error' => 'App Gupshup não configurado.', 'error_code' => 'missing_app', 'retryable' => false];
        }

        $auth = $this->auth->authorization($appId);

        // O valor do header (token) fica só no cache — não sai daqui.
        return $auth['ok'] ? ['ok' => true] : $auth;
    }

    public function sendTemplate(WhatsAppSetting $setting, string $phone, TemplateMessage $template): array
    {
        $body = [
            'name'     => $template->name,
            'language' => ['code' => $template->language],
        ];

        $components = $template->components();

        if ($components !== []) {
            $body['components'] = $components;
        }

        return $this->send($setting, [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $phone,
            'type'              => 'template',
            'template'          => $body,
        ]);
    }

    public function sendSessionText(WhatsAppSetting $setting, string $phone, string $text): array
    {
        return $this->send($setting, [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $phone,
            'type'              => 'text',
            'text'              => ['body' => mb_substr($text, 0, 4096), 'preview_url' => false],
        ]);
    }

    public function health(WhatsAppSetting $setting): array
    {
        $response = $this->appRequest($setting, 'GET', 'health');

        if (! $response['ok']) {
            return $response;
        }

        $healthy = $response['body']['healthy'] ?? false;

        return ['ok' => true, 'healthy' => $healthy === true || $healthy === 'true'];
    }

    public function subscribeWebhook(WhatsAppSetting $setting, string $url, string $secret): array
    {
        $tag = (string) config('whatsapp.gupshup.subscription_tag', 'easyeye-v3');

        // Refaz a assinatura do EasyEye (mesma tag): a URL ou o segredo podem
        // ter mudado. As assinaturas de outras tags ficam intactas.
        $list = $this->appRequest($setting, 'GET', 'subscription');

        if ($list['ok']) {
            foreach ((array) ($list['body']['subscriptions'] ?? []) as $subscription) {
                if (($subscription['tag'] ?? null) === $tag && filled($subscription['id'] ?? null)) {
                    $this->appRequest($setting, 'DELETE', 'subscription/' . rawurlencode((string) $subscription['id']));
                }
            }
        }

        $response = $this->appRequest($setting, 'POST', 'subscription', [
            'modes'    => (string) config('whatsapp.gupshup.subscription_modes'),
            'tag'      => $tag,
            'url'      => $url,
            'version'  => 3,
            'showOnUI' => 'false',
            'meta'     => json_encode([WhatsAppSetting::WEBHOOK_SECRET_HEADER => $secret]),
        ], form: true);

        if (! $response['ok']) {
            return $response;
        }

        return ['ok' => true, 'subscription_id' => (string) data_get($response['body'], 'subscription.id', '')];
    }

    /**
     * Envio. Sem duplicidade: a Gupshup não tem chave de idempotência, então
     *  - 2xx sem id → aceito (a mensagem saiu): ok com error_code
     *    no_message_id, sem nova tentativa;
     *  - timeout de LEITURA/conexão caída depois de enviar → unknown_delivery,
     *    sem nova tentativa (pode ter saído);
     *  - falha de CONEXÃO antes de enviar (DNS, connect timeout, recusa) →
     *    connection, transitória (com certeza não saiu).
     *
     * @param array<string, mixed> $payload
     *
     * @return array{ok: bool, message_id?: ?string, error?: string, error_code?: ?string, retryable?: bool}
     */
    private function send(WhatsAppSetting $setting, array $payload): array
    {
        $response = $this->appRequest($setting, 'POST', 'v3/message', $payload);

        if (! $response['ok']) {
            if (($response['error_code'] ?? null) === 'gupshup_' . self::WALLET_LOW) {
                WhatsAppAlerts::critical('wallet_low', ['app_id' => (string) $setting->app_id]);
            }

            return $response;
        }

        $messageId = (string) data_get($response['body'], 'messages.0.id', '');

        if ($messageId === '') {
            return ['ok' => true, 'message_id' => null, 'error_code' => 'no_message_id'];
        }

        return ['ok' => true, 'message_id' => $messageId];
    }

    /**
     * A conexão caiu ANTES de o pedido chegar à Gupshup (nada foi enviado)?
     * cURL 6 (DNS), 7 (não conectou), 35 (handshake TLS) e connect timeout.
     * Qualquer outro caso (timeout de leitura, conexão resetada, resposta
     * vazia) é ambíguo: o pedido pode ter sido processado.
     */
    public static function failedBeforeSending(ConnectionException $e): bool
    {
        $message = $e->getMessage();

        return (bool) preg_match('/cURL error (6|7|35):|Connection timed out after|Could not resolve host|Failed to connect|Connection refused/i', $message);
    }

    /**
     * Chamada autenticada ao app; 401 → GupshupAuth::renew (relê o cache,
     * só gera token novo se o recusado for o atual) e uma nova tentativa.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{ok: bool, body?: array<string, mixed>, error?: string, error_code?: string, retryable?: bool}
     */
    private function appRequest(WhatsAppSetting $setting, string $method, string $path, array $payload = [], bool $form = false, ?array $auth = null): array
    {
        $appId = (string) $setting->app_id;

        if ($appId === '') {
            return ['ok' => false, 'error' => 'App Gupshup não configurado.', 'error_code' => 'missing_app', 'retryable' => false];
        }

        $retried = $auth !== null;
        $auth ??= $this->auth->authorization($appId);

        if (! $auth['ok']) {
            return $auth;
        }

        $url = '/partner/app/' . rawurlencode($appId) . '/' . $path;

        try {
            $client = $this->auth->client()->withHeaders(['Authorization' => $auth['authorization']]);
            $client = $form ? $client->asForm() : $client->asJson();

            $response = match ($method) {
                'GET'    => $client->get($url),
                'DELETE' => $client->delete($url),
                default  => $client->post($url, $payload),
            };
        } catch (ConnectionException $e) {
            if ($path === 'v3/message' && ! self::failedBeforeSending($e)) {
                return ['ok' => false, 'error' => 'Sem resposta da Gupshup (timeout de leitura): a mensagem pode ter saído — não reenviada.', 'error_code' => 'unknown_delivery', 'retryable' => false];
            }

            return ['ok' => false, 'error' => 'Falha de conexão com a Gupshup.', 'error_code' => 'connection', 'retryable' => true];
        }

        if ($response->status() === 401 && ! $retried) {
            $renewed = $this->auth->renew($appId, $auth['authorization']);

            return $renewed['ok'] ? $this->appRequest($setting, $method, $path, $payload, $form, $renewed) : $renewed;
        }

        if ($response->successful() && data_get($response->json(), 'status') !== 'error') {
            return ['ok' => true, 'body' => (array) $response->json()];
        }

        return self::failure($response);
    }

    /**
     * Falha HTTP → {error, error_code, retryable}. 5xx, 429 e os códigos
     * transitórios da Meta/Gupshup voltam para a fila; o resto (4xx de
     * validação, template inexistente, número inválido) falha de vez.
     *
     * @return array{ok: false, error: string, error_code: string, retryable: bool}
     */
    public static function failure(Response $response): array
    {
        $status = $response->status();
        $code   = data_get($response->json(), 'error.code') ?? data_get($response->json(), 'code');

        if (is_numeric($code)) {
            $classified = self::classifyCode((int) $code);

            return [
                'ok'         => false,
                'error'      => 'Gupshup HTTP ' . $status . ': ' . self::errorMessage($response),
                'error_code' => $classified['error_code'],
                'retryable'  => $classified['retryable'] || $status === 429 || $status >= 500,
            ];
        }

        return [
            'ok'         => false,
            'error'      => 'Gupshup HTTP ' . $status . ': ' . self::errorMessage($response),
            'error_code' => match (true) {
                $status === 401, $status === 403 => 'auth_failed',
                $status === 429 => 'rate_limited',
                default         => 'http_' . $status,
            },
            'retryable' => $status === 429 || $status >= 500,
        ];
    }

    /**
     * Código de erro da Meta ou da Gupshup (envio ou webhook de status).
     *
     * @return array{error_code: string, retryable: bool}
     */
    public static function classifyCode(int $code): array
    {
        if ($code === 1012) {
            // "Number Opted Out" — o número pediu para não receber.
            return ['error_code' => 'opted_out', 'retryable' => false];
        }

        return [
            'error_code' => (in_array($code, self::GUPSHUP_CODES, true) ? 'gupshup_' : 'meta_') . $code,
            'retryable'  => in_array($code, self::TRANSIENT_CODES, true),
        ];
    }

    /**
     * O error_code gravado é de falha transitória (uma nova tentativa ou um
     * novo pedido pode dar certo)? Mesmo critério de failure/classifyCode.
     */
    public static function isTransientErrorCode(?string $code): bool
    {
        if ($code === null || $code === '') {
            return false;
        }

        if (in_array($code, ['connection', 'rate_limited', 'token_busy', 'token_missing', 'uat_limit', 'universal_token_invalid'], true)) {
            return true;
        }

        if (preg_match('/^http_(\d+)$/', $code, $m)) {
            return (int) $m[1] === 429 || (int) $m[1] >= 500;
        }

        if (preg_match('/^(?:meta|gupshup)_(\d+)$/', $code, $m)) {
            return in_array((int) $m[1], self::TRANSIENT_CODES, true);
        }

        return false;
    }

    /** Mensagem curta do erro, sem nada além do que o provedor devolveu. */
    public static function errorMessage(Response $response): string
    {
        $json    = $response->json();
        $message = is_array($json)
            ? (data_get($json, 'error.message') ?? data_get($json, 'message') ?? data_get($json, 'reason'))
            : null;

        return Str::limit(is_string($message) && $message !== '' ? $message : trim($response->body()), 300);
    }
}
