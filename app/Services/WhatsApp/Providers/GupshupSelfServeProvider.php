<?php

declare(strict_types=1);

namespace App\Services\WhatsApp\Providers;

use App\Models\WhatsApp\WhatsAppSetting;
use App\Services\WhatsApp\Contracts\WhatsAppProvider;
use App\Services\WhatsApp\TemplateMessage;
use Illuminate\Http\Client\{ConnectionException, PendingRequest};
use Illuminate\Support\Facades\{Cache, Http};

/**
 * WhatsApp oficial pela conta COMUM da Gupshup (self-serve, sem Partner API).
 * Só o app/número GLOBAL do EasyEye: nome do app, número de envio e API key
 * vêm do .env (GUPSHUP_API_KEY, GUPSHUP_APP_NAME, GUPSHUP_SOURCE_NUMBER).
 *
 * Endpoints (documentação oficial, conferida em 28/10/2026), header `apikey`,
 * corpo application/x-www-form-urlencoded:
 *  - Template (por ID, não por nome): POST /wa/api/v1/template/msg
 *    template={"id","params"}, postbackTexts=[{"index","text"}] nos botões de resposta
 *    https://docs.gupshup.io/reference/sending-text-template
 *    https://docs.gupshup.io/reference/sending-authentication-template (código do OTP 2x em params)
 *  - Texto de sessão: POST /wa/api/v1/msg  message={"type":"text","text"}
 *    https://docs.gupshup.io/reference/session-text-message
 *  - Templates do app (id ↔ nome+idioma): GET /wa/app/{appId}/template
 *    https://docs.gupshup.io/reference/get-all-templates-for-an-app
 *  - Webhook: sem API — cadastrado no painel (Webhooks, formato Meta v3, cabeçalho de
 *    segredo): ver `php artisan whatsapp:webhook-secret`.
 *
 * Nunca loga nem devolve a API key.
 */
class GupshupSelfServeProvider implements WhatsAppProvider
{
    /** Lista de templates (id por nome+idioma) em cache: o catálogo muda raramente. */
    private const TEMPLATES_TTL_MINUTES = 10;

    public function __construct(private readonly GupshupAuth $auth)
    {
    }

    public function name(): string
    {
        return 'gupshup';
    }

    public function authorize(WhatsAppSetting $setting): array
    {
        if (! $setting->hasApp()) {
            return ['ok' => false, 'error' => 'App Gupshup não configurado.', 'error_code' => 'missing_app', 'retryable' => false];
        }

        if (! $setting->isGlobal()) {
            return ['ok' => false, 'error' => 'Conta self-serve da Gupshup atende só o número global do EasyEye (app próprio por clínica exige conta de parceiro).', 'error_code' => 'self_serve_global_only', 'retryable' => false];
        }

        if (! $this->auth->configured()) {
            return ['ok' => false, 'error' => 'GUPSHUP_API_KEY, GUPSHUP_APP_NAME e GUPSHUP_SOURCE_NUMBER ausentes no .env.', 'error_code' => 'missing_partner_credentials', 'retryable' => false];
        }

        return ['ok' => true];
    }

    public function sendTemplate(WhatsAppSetting $setting, string $phone, TemplateMessage $template): array
    {
        if (! ($auth = $this->authorize($setting))['ok']) {
            return $auth;
        }

        $id = $this->templateId($setting, $template->name, $template->language);

        if ($id === null) {
            return ['ok' => false, 'error' => "Template {$template->name} ({$template->language}) não encontrado ou não aprovado neste app.", 'error_code' => 'template_not_found', 'retryable' => false];
        }

        // Variáveis do corpo + valor dos botões de URL/OTP (o código do OTP vai no corpo e no botão).
        $params = [...$template->bodyParams];

        foreach ($template->buttons as $button) {
            if ($button['type'] !== 'quick_reply') {
                $params[] = $button['value'];
            }
        }

        $form = ['template' => json_encode(['id' => $id, 'params' => $params], JSON_UNESCAPED_UNICODE)];

        $postbacks = [];

        foreach ($template->buttons as $button) {
            if ($button['type'] === 'quick_reply') {
                $postbacks[] = ['index' => $button['index'], 'text' => $button['value']];
            }
        }

        if ($postbacks !== []) {
            $form['postbackTexts'] = json_encode($postbacks, JSON_UNESCAPED_UNICODE);
        }

        return $this->send('/wa/api/v1/template/msg', $phone, $form);
    }

    public function sendSessionText(WhatsAppSetting $setting, string $phone, string $text): array
    {
        if (! ($auth = $this->authorize($setting))['ok']) {
            return $auth;
        }

        return $this->send('/wa/api/v1/msg', $phone, [
            'message' => json_encode(['type' => 'text', 'text' => mb_substr($text, 0, 4096), 'previewUrl' => false], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /** Sem endpoint de saúde: "saudável" = a API key é aceita e lista os templates do app. */
    public function health(WhatsAppSetting $setting): array
    {
        if (! ($auth = $this->authorize($setting))['ok']) {
            return $auth;
        }

        $response = $this->get($setting, ['pageSize' => 1]);

        return $response['ok'] ? ['ok' => true, 'healthy' => true] : $response;
    }

    /** A Gupshup comum não tem API de assinatura: o webhook é cadastrado à mão no painel. */
    public function subscribeWebhook(WhatsAppSetting $setting, string $url, string $secret): array
    {
        return [
            'ok'         => false,
            'error'      => 'Conta self-serve: cadastre o webhook no painel da Gupshup (php artisan whatsapp:webhook-secret).',
            'error_code' => 'manual_webhook',
        ];
    }

    /**
     * Envio sem duplicidade: a Gupshup não tem chave de idempotência, então timeout de
     * leitura depois de enviar → unknown_delivery, sem nova tentativa (pode ter saído);
     * falha de conexão antes de enviar → transitória.
     *
     * @param array<string, string> $form
     *
     * @return array{ok: bool, message_id?: ?string, error?: string, error_code?: ?string, retryable?: bool}
     */
    private function send(string $path, string $phone, array $form): array
    {
        try {
            $response = $this->client()->asForm()->post($path, [
                'channel'     => 'whatsapp',
                'source'      => preg_replace('/\D/', '', (string) config('whatsapp.gupshup.source_number')),
                'destination' => $phone,
                'src.name'    => (string) config('whatsapp.gupshup.app_name'),
                ...$form,
            ]);
        } catch (ConnectionException $e) {
            if (! GupshupProvider::failedBeforeSending($e)) {
                return ['ok' => false, 'error' => 'Sem resposta da Gupshup (timeout de leitura): a mensagem pode ter saído — não reenviada.', 'error_code' => 'unknown_delivery', 'retryable' => false];
            }

            return ['ok' => false, 'error' => 'Falha de conexão com a Gupshup.', 'error_code' => 'connection', 'retryable' => true];
        }

        if (! $response->successful() || data_get($response->json(), 'status') === 'error') {
            return GupshupProvider::failure($response);
        }

        $messageId = (string) data_get($response->json(), 'messageId', '');

        return $messageId === ''
            ? ['ok' => true, 'message_id' => null, 'error_code' => 'no_message_id']
            : ['ok' => true, 'message_id' => $messageId];
    }

    /**
     * ID do template na Gupshup para nome+idioma (só aprovados). Um template novo pode
     * ter sido aprovado depois do cache: na falta, relê a lista uma vez.
     */
    private function templateId(WhatsAppSetting $setting, string $name, string $language): ?string
    {
        $key = $name . '|' . $language;

        foreach ([false, true] as $refresh) {
            $map = $this->templates($setting, $refresh);

            if (isset($map[$key])) {
                return $map[$key];
            }
        }

        return null;
    }

    /** @return array<string, string> "nome|idioma" => id dos templates APPROVED */
    private function templates(WhatsAppSetting $setting, bool $refresh): array
    {
        $cacheKey = 'whatsapp:gupshup:self-serve:templates:' . sha1((string) $setting->app_id);

        if (! $refresh && is_array($cached = Cache::get($cacheKey))) {
            return $cached;
        }

        $response = $this->get($setting, ['pageSize' => 200, 'templateStatus' => 'APPROVED']);

        if (! $response['ok']) {
            return [];
        }

        $map = [];

        foreach ((array) data_get($response['body'], 'templates', []) as $template) {
            if (($template['status'] ?? null) === 'APPROVED' && filled($template['id'] ?? null)) {
                $map[($template['elementName'] ?? '') . '|' . ($template['languageCode'] ?? '')] = (string) $template['id'];
            }
        }

        Cache::put($cacheKey, $map, now()->addMinutes(self::TEMPLATES_TTL_MINUTES));

        return $map;
    }

    /**
     * @param array<string, scalar> $query
     *
     * @return array{ok: bool, body?: array<string, mixed>, error?: string, error_code?: string, retryable?: bool}
     */
    private function get(WhatsAppSetting $setting, array $query): array
    {
        try {
            $response = $this->client()->get('/wa/app/' . rawurlencode((string) $setting->app_id) . '/template', $query);
        } catch (ConnectionException) {
            return ['ok' => false, 'error' => 'Falha de conexão com a Gupshup.', 'error_code' => 'connection', 'retryable' => true];
        }

        return $response->successful() && data_get($response->json(), 'status') !== 'error'
            ? ['ok' => true, 'body' => (array) $response->json()]
            : GupshupProvider::failure($response);
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('whatsapp.gupshup.api_base_url', 'https://api.gupshup.io'), '/'))
            ->withHeaders(['apikey' => (string) config('whatsapp.gupshup.api_key')])
            ->timeout((int) config('whatsapp.http.timeout_seconds', 15))
            ->connectTimeout((int) config('whatsapp.http.connect_timeout_seconds', 5))
            ->acceptJson();
    }
}
