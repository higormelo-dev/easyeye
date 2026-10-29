<?php

declare(strict_types=1);

use App\Domains\Recall\Services\RecallKillSwitch;
use App\Http\Controllers\Manager\WhatsAppController;
use App\Models\WhatsApp\WhatsAppSetting;
use App\Services\Audit\AuditLogger;
use App\Services\WhatsApp\Contracts\WhatsAppProvider;
use App\Services\WhatsApp\Providers\{GupshupAuth, GupshupSelfServeProvider};
use App\Services\WhatsApp\{TemplateMessage, WhatsAppTemplates};
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\{Cache, Http};
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config([
        'cache.default'                  => 'array',
        'whatsapp.driver'                => 'gupshup',
        'whatsapp.gupshup.auth_mode'     => 'api_key',
        'whatsapp.gupshup.api_key'       => 'test-api-key',
        'whatsapp.gupshup.app_name'      => 'test-app',
        'whatsapp.gupshup.source_number' => '917834811114',
        'whatsapp.gupshup.api_base_url'  => 'https://api.gupshup.io',
        'whatsapp.gupshup.sandbox'       => true,
    ]);
    Cache::flush();
    Http::preventStrayRequests();
});

it('usa common_status aprovado no Sandbox somente para o teste de conexão', function () {
    Http::fake([
        'api.gupshup.io/wa/app/sandbox-app/template*' => Http::response(['templates' => [
            ['id' => 'sandbox-status', 'elementName' => 'common_status', 'languageCode' => 'en_US', 'status' => 'APPROVED'],
        ]]),
        'api.gupshup.io/wa/api/v1/template/msg' => Http::response(['messageId' => 'test-message'], 202),
    ]);
    $provider = new GupshupSelfServeProvider(new GupshupAuth());
    $setting  = new WhatsAppSetting(['app_id' => 'sandbox-app']);
    $template = app(WhatsAppTemplates::class)->build('connection_test', ['app' => 'EasyEye'], 'pt_BR');

    expect($provider->sendTemplate($setting, '5561999990000', $template))->toBe(['ok' => true, 'message_id' => 'test-message']);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/template/msg')
        && json_decode($r['template'], true) === ['id' => 'sandbox-status', 'params' => [(string) config('app.name'), 'connection test']]);

    $clinical = new TemplateMessage('appointment_confirmation', 'easyeye_confirmacao_consulta', 'pt_BR', ['Paciente'], []);
    expect($provider->sendTemplate($setting, '5561999990000', $clinical)['error_code'])->toBe('template_not_found');
    expect(Http::recorded(fn (Request $r) => $r->method() === 'POST'))->toHaveCount(1);
});

it('mantém o template normal quando o modo Sandbox está desligado', function () {
    config(['whatsapp.gupshup.sandbox' => false]);
    Http::fake(['api.gupshup.io/wa/app/sandbox-app/template*' => Http::response(['templates' => [
        ['id' => 'sandbox-status', 'elementName' => 'common_status', 'languageCode' => 'en_US', 'status' => 'APPROVED'],
    ]])]);

    $template = app(WhatsAppTemplates::class)->build('connection_test', ['app' => 'EasyEye'], 'pt_BR');
    $result   = (new GupshupSelfServeProvider(new GupshupAuth()))->sendTemplate(new WhatsAppSetting(['app_id' => 'sandbox-app']), '5561999990000', $template);

    expect($result['error_code'])->toBe('template_not_found');
    Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
});

it('preserva a marcação manual ao tentar registrar novamente um webhook Self Serve', function (bool $registered) {
    $provider = Mockery::mock(WhatsAppProvider::class);
    $provider->shouldReceive('subscribeWebhook')->once()->andReturn(['ok' => false, 'error_code' => 'manual_webhook']);
    $setting = Mockery::mock(WhatsAppSetting::class)->makePartial();
    $setting->shouldReceive('save')->never();
    $setting->app_id                = 'sandbox-app';
    $setting->webhook_token         = 'fake-webhook-token';
    $setting->webhook_secret        = 'fake-webhook-secret';
    $setting->webhook_subscribed_at = $registered ? now() : null;
    $controller                     = new WhatsAppController($provider, new WhatsAppTemplates(), app(AuditLogger::class), app(RecallKillSwitch::class));

    $result = (new ReflectionMethod($controller, 'registerWebhook'))->invoke($controller, $setting);

    expect($result)->toBe($registered)
        ->and($setting->webhook_subscribed_at !== null)->toBe($registered);
    Http::assertNothingSent();
})->with([true, false]);
