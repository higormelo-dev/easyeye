<?php

declare(strict_types=1);

use App\Models\WhatsApp\WhatsAppSetting;
use App\Services\WhatsApp\Contracts\WhatsAppProvider;
use App\Services\WhatsApp\Providers\{GupshupProvider, GupshupSelfServeProvider};
use App\Services\WhatsApp\WhatsAppTemplates;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\{Cache, Http};
use Illuminate\Support\Str;

/**
 * Conta COMUM da Gupshup (self-serve, sem Partner API): corpo exato de cada envio
 * (Http::fake, nada sai), template por ID, OTP com o código 2x, erros, saúde e o
 * limite "só o app global". Documentação: docblock de GupshupSelfServeProvider.
 */
beforeEach(function () {
    Http::preventStrayRequests();
    Cache::flush();

    config([
        'whatsapp.driver'                => 'gupshup',
        'whatsapp.gupshup.auth_mode'     => '',
        'whatsapp.gupshup.api_base_url'  => 'https://api.gupshup.io',
        'whatsapp.gupshup.api_key'       => 'sk_conta_comum',
        'whatsapp.gupshup.app_name'      => 'EasyEyeApp',
        'whatsapp.gupshup.source_number' => '+55 61 99999-0000',
    ]);

    // Linha GLOBAL (entity_id nulo) com app.
    $this->global = new WhatsAppSetting(['app_id' => 'app-123', 'active' => true]);
});

function selfServe(): WhatsAppProvider
{
    return app(WhatsAppProvider::class);
}

function templatesStub(): array
{
    return [
        'api.gupshup.io/wa/app/app-123/template*' => Http::response(['status' => 'success', 'templates' => [
            ['id' => 'tpl-conf', 'elementName' => 'easyeye_confirmacao_consulta', 'languageCode' => 'pt_BR', 'status' => 'APPROVED'],
            ['id' => 'tpl-otp', 'elementName' => 'easyeye_codigo_verificacao', 'languageCode' => 'pt_BR', 'status' => 'APPROVED'],
            ['id' => 'tpl-pend', 'elementName' => 'easyeye_teste_conexao', 'languageCode' => 'pt_BR', 'status' => 'PENDING'],
        ]]),
    ];
}

/** @return array<string, mixed> campos do form do último POST ao path. */
function selfServeForm(string $path): array
{
    return Http::recorded(fn (Request $r) => str_ends_with($r->url(), $path))->last()[0]->data();
}

it('driver do container: conta comum quando há GUPSHUP_API_KEY; parceiro sem ela', function () {
    expect(selfServe())->toBeInstanceOf(GupshupSelfServeProvider::class);

    config(['whatsapp.gupshup.api_key' => null, 'whatsapp.gupshup.partner_email' => 'p@x.test', 'whatsapp.gupshup.partner_secret' => 's']);
    expect(selfServe())->toBeInstanceOf(GupshupProvider::class);
});

it('modo explícito api_key vale mesmo com token universal no .env', function () {
    config(['whatsapp.gupshup.auth_mode' => 'api_key', 'whatsapp.gupshup.universal_token' => 'jwt']);

    expect(selfServe())->toBeInstanceOf(GupshupSelfServeProvider::class);
});

describe('template', function () {
    it('envia por ID: header apikey, source só dígitos, params e postbackTexts dos botões de resposta', function () {
        Http::fake([...templatesStub(), 'api.gupshup.io/wa/api/v1/template/msg' => Http::response(['status' => 'success', 'messageId' => 'gs-1'], 202)]);

        $template = app(WhatsAppTemplates::class)->build('appointment_confirmation', [
            'first_name' => 'Maria', 'clinic' => 'Clínica Visão', 'when' => '10/10 14:30', 'doctor' => 'Dr. João',
        ], 'pt_BR', ['message_id' => 'msg-uuid']);

        $result = selfServe()->sendTemplate($this->global, '5561999998888', $template);

        expect($result)->toBe(['ok' => true, 'message_id' => 'gs-1']);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/wa/api/v1/template/msg')
            && $r->hasHeader('apikey', 'sk_conta_comum')
            && $r->isForm());

        $form = selfServeForm('/wa/api/v1/template/msg');

        expect($form['channel'])->toBe('whatsapp')
            ->and($form['source'])->toBe('5561999990000')
            ->and($form['destination'])->toBe('5561999998888')
            ->and($form['src.name'])->toBe('EasyEyeApp')
            ->and(json_decode($form['template'], true))->toBe(['id' => 'tpl-conf', 'params' => ['Maria', 'Clínica Visão', '10/10 14:30', 'Dr. João']])
            ->and(json_decode($form['postbackTexts'], true))->toHaveCount(2)
            ->and(json_decode($form['postbackTexts'], true)[0]['index'])->toBe(0);
    });

    it('OTP: o código vai 2x em params (corpo + botão) e não há postbackTexts', function () {
        Http::fake([...templatesStub(), 'api.gupshup.io/wa/api/v1/template/msg' => Http::response(['status' => 'success', 'messageId' => 'gs-2'], 202)]);

        $template = app(WhatsAppTemplates::class)->build('verification_code', ['code' => '123456'], 'pt_BR');

        selfServe()->sendTemplate($this->global, '5561999998888', $template);

        $form = selfServeForm('/wa/api/v1/template/msg');

        expect(json_decode($form['template'], true))->toBe(['id' => 'tpl-otp', 'params' => ['123456', '123456']])
            ->and($form)->not->toHaveKey('postbackTexts');
    });

    it('template inexistente ou não aprovado → template_not_found permanente, sem envio', function () {
        Http::fake(templatesStub());

        $template = app(WhatsAppTemplates::class)->build('connection_test', ['app' => 'EasyEye'], 'pt_BR'); // PENDING

        $result = selfServe()->sendTemplate($this->global, '5561999998888', $template);

        expect($result['ok'])->toBeFalse()
            ->and($result['error_code'])->toBe('template_not_found')
            ->and($result['retryable'])->toBeFalse();

        expect(Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/template/msg')))->toHaveCount(0);
    });

    it('a lista de templates fica em cache entre envios', function () {
        Http::fake([...templatesStub(), 'api.gupshup.io/wa/api/v1/template/msg' => Http::response(['status' => 'success', 'messageId' => 'gs-3'], 202)]);

        $template = app(WhatsAppTemplates::class)->build('verification_code', ['code' => '111111'], 'pt_BR');

        selfServe()->sendTemplate($this->global, '5561999998888', $template);
        selfServe()->sendTemplate($this->global, '5561999998888', $template);

        expect(Http::recorded(fn (Request $r) => str_contains($r->url(), '/wa/app/app-123/template')))->toHaveCount(1);
    });
});

describe('texto de sessão', function () {
    it('POST /wa/api/v1/msg com message JSON', function () {
        Http::fake(['api.gupshup.io/wa/api/v1/msg' => Http::response(['status' => 'submitted', 'messageId' => 'gs-4'])]);

        $result = selfServe()->sendSessionText($this->global, '5561999998888', 'Consulta confirmada.');

        expect($result)->toBe(['ok' => true, 'message_id' => 'gs-4']);

        $form = selfServeForm('/wa/api/v1/msg');

        expect(json_decode($form['message'], true))->toBe(['type' => 'text', 'text' => 'Consulta confirmada.', 'previewUrl' => false])
            ->and($form['src.name'])->toBe('EasyEyeApp');
    });
});

describe('erros', function () {
    it('401 → auth_failed permanente; 429 e 5xx → transitórios; body status=error → falha', function () {
        Http::fake(['api.gupshup.io/wa/api/v1/msg' => Http::sequence()
            ->push(['status' => 'error', 'message' => 'Authentication Failed'], 401)
            ->push(['status' => 'error', 'message' => 'Too many requests'], 429)
            ->push('', 503)
            ->push(['status' => 'error', 'message' => 'Invalid Destination'], 200)]);

        $auth = selfServe()->sendSessionText($this->global, '5561999998888', 'x');
        $rate = selfServe()->sendSessionText($this->global, '5561999998888', 'x');
        $down = selfServe()->sendSessionText($this->global, '5561999998888', 'x');
        $body = selfServe()->sendSessionText($this->global, '5561999998888', 'x');

        expect($auth['error_code'])->toBe('auth_failed')
            ->and($auth['retryable'])->toBeFalse()
            ->and($auth['error'])->toContain('Authentication Failed')
            ->and($rate['retryable'])->toBeTrue()
            ->and($down['retryable'])->toBeTrue()
            ->and($body['ok'])->toBeFalse();
    });

    it('falha de conexão antes de enviar é transitória; timeout de leitura é unknown_delivery', function () {
        Http::fake(['api.gupshup.io/wa/api/v1/msg' => Http::sequence()
            ->pushFailedConnection('cURL error 7: Failed to connect')
            ->pushFailedConnection('cURL error 28: Operation timed out after 15000 milliseconds with 0 bytes received')]);

        $before = selfServe()->sendSessionText($this->global, '5561999998888', 'x');
        $after  = selfServe()->sendSessionText($this->global, '5561999998888', 'x');

        expect($before['error_code'])->toBe('connection')
            ->and($before['retryable'])->toBeTrue()
            ->and($after['error_code'])->toBe('unknown_delivery')
            ->and($after['retryable'])->toBeFalse();
    });

    it('app de clínica, sem app e sem credenciais falham antes de qualquer HTTP', function () {
        $clinic = new WhatsAppSetting(['entity_id' => (string) Str::uuid(), 'app_id' => 'app-clinica', 'active' => true]);

        expect(selfServe()->authorize($clinic)['error_code'])->toBe('self_serve_global_only')
            ->and(selfServe()->authorize(new WhatsAppSetting())['error_code'])->toBe('missing_app');

        config(['whatsapp.gupshup.source_number' => null, 'whatsapp.gupshup.auth_mode' => 'api_key']);

        expect(selfServe()->authorize($this->global)['error_code'])->toBe('missing_partner_credentials');

        Http::assertNothingSent();
    });
});

it('saúde: lista 1 template; 401 devolve a falha', function () {
    Http::fake(['api.gupshup.io/wa/app/app-123/template*' => Http::sequence()
        ->push(['status' => 'success', 'templates' => []])
        ->push(['status' => 'error', 'message' => 'Authentication Failed'], 401)]);

    expect(selfServe()->health($this->global))->toBe(['ok' => true, 'healthy' => true])
        ->and(selfServe()->health($this->global)['error_code'])->toBe('auth_failed');
});

it('webhook: sem API de assinatura — manual_webhook, nada é chamado', function () {
    $result = selfServe()->subscribeWebhook($this->global, 'https://easyeye.app/api/whatsapp/gupshup/webhook/tok', 'segredo');

    expect($result['ok'])->toBeFalse()
        ->and($result['error_code'])->toBe('manual_webhook');

    Http::assertNothingSent();
});

it('whatsapp:webhook-secret imprime URL, cabeçalho e segredo do app global e marca como cadastrado', function () {
    $setting = WhatsAppSetting::create([
        'provider'       => 'gupshup',
        'app_id'         => 'app-123',
        'webhook_token'  => WhatsAppSetting::generateWebhookToken(),
        'webhook_secret' => 'segredo-fixo-de-teste',
        'active'         => true,
    ]);

    $this->artisan('whatsapp:webhook-secret', ['--registered' => true])
        ->expectsOutputToContain('/api/whatsapp/gupshup/webhook/' . $setting->webhook_token)
        ->expectsOutputToContain('X-EasyEye-Webhook-Secret')
        ->expectsOutputToContain('segredo-fixo-de-teste')
        ->assertSuccessful();

    expect($setting->fresh()->webhook_subscribed_at)->not->toBeNull();
});

it('whatsapp:webhook-secret falha sem app global', function () {
    $this->artisan('whatsapp:webhook-secret')->assertFailed();
});
