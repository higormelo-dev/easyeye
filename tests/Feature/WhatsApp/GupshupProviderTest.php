<?php

declare(strict_types=1);

use App\Enums\ScheduleSituation;
use App\Jobs\WhatsApp\SendWhatsAppMessageJob;
use App\Models\{Entity, Schedule};
use App\Models\WhatsApp\{WhatsAppMessage, WhatsAppOptOut, WhatsAppSetting};
use App\Services\WhatsApp\Contracts\WhatsAppProvider;
use App\Services\WhatsApp\Providers\{GupshupProvider, MockWhatsAppProvider};
use App\Services\WhatsApp\{WhatsAppService, WhatsAppTemplates};
use Illuminate\Http\Client\{ConnectionException, Request};
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\{Cache, Event, Http};

/**
 * Caminho HTTP real da Partner API da Gupshup (Http::fake, nada sai):
 * autenticação e cache de tokens, renovação em 401, corpo exato do envio v3,
 * erros transitórios × permanentes, saúde do app e assinatura do webhook.
 * Documentação: ver docblocks de GupshupProvider/GupshupAuth.
 */
beforeEach(function () {
    Http::preventStrayRequests();
    Cache::flush();
    useGupshupDriver();

    $this->app_setting = new WhatsAppSetting(['app_id' => 'app-123', 'active' => true]);
});

function provider(): WhatsAppProvider
{
    return app(WhatsAppProvider::class);
}

function confirmationTemplate(string $messageId = '9b2f8f7e-1111-4222-8333-944455556666')
{
    return app(WhatsAppTemplates::class)->build('appointment_confirmation', [
        'first_name' => 'Maria',
        'clinic'     => 'Clínica Visão',
        'when'       => '10/10/2026 às 14:30',
        'doctor'     => 'Dr. João',
    ], 'pt_BR', ['message_id' => $messageId]);
}

it('driver do container: gupshup com WHATSAPP_DRIVER=gupshup; mock com mock ou vazio', function () {
    expect(provider())->toBeInstanceOf(GupshupProvider::class);

    config(['whatsapp.driver' => '']);
    expect(provider())->toBeInstanceOf(MockWhatsAppProvider::class);

    config(['whatsapp.driver' => 'mock']);
    expect(provider())->toBeInstanceOf(MockWhatsAppProvider::class);
});

describe('autenticação (e-mail + client secret)', function () {
    it('login do parceiro → token do app → envio v3 com o corpo exato da documentação', function () {
        fakeGupshup(['partner.gupshup.io/partner/app/app-123/v3/message' => Http::response([
            'messages'          => [['id' => 'gs-msg-1']],
            'messaging_product' => 'whatsapp',
            'contacts'          => [['input' => '5561999998888', 'wa_id' => '5561999998888']],
        ])]);

        $result = provider()->sendTemplate($this->app_setting, '5561999998888', confirmationTemplate());

        expect($result)->toBe(['ok' => true, 'message_id' => 'gs-msg-1']);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://partner.gupshup.io/partner/account/login'
            && $r->method() === 'POST'
            && $r->isForm()
            && $r['email'] === 'parceiro@easyeye.test'
            && $r['secret'] === 'client-secret-test');

        Http::assertSent(fn (Request $r) => $r->url() === 'https://partner.gupshup.io/partner/app/app-123/token'
            && $r->method() === 'GET'
            && $r->header('Authorization') === ['PARTNER-TOKEN']);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://partner.gupshup.io/partner/app/app-123/v3/message'
            && $r->header('Authorization') === ['sk_app_token'] // sem "Bearer"
            && $r->isJson()
            && $r->data() === [
                'messaging_product' => 'whatsapp',
                'recipient_type'    => 'individual',
                'to'                => '5561999998888',
                'type'              => 'template',
                'template'          => [
                    'name'       => 'easyeye_confirmacao_consulta',
                    'language'   => ['code' => 'pt_BR'],
                    'components' => [
                        ['type' => 'body', 'parameters' => [
                            ['type' => 'text', 'text' => 'Maria'],
                            ['type' => 'text', 'text' => 'Clínica Visão'],
                            ['type' => 'text', 'text' => '10/10/2026 às 14:30'],
                            ['type' => 'text', 'text' => 'Dr. João'],
                        ]],
                        ['type' => 'button', 'sub_type' => 'quick_reply', 'index' => '0', 'parameters' => [['type' => 'payload', 'payload' => 'confirm:9b2f8f7e-1111-4222-8333-944455556666']]],
                        ['type' => 'button', 'sub_type' => 'quick_reply', 'index' => '1', 'parameters' => [['type' => 'payload', 'payload' => 'cancel:9b2f8f7e-1111-4222-8333-944455556666']]],
                    ],
                ],
            ]);
    });

    it('tokens ficam em cache: dois envios fazem um login e um pedido de token', function () {
        fakeGupshup(['partner.gupshup.io/partner/app/app-123/v3/message' => Http::response(['messages' => [['id' => 'gs']]])]);

        provider()->sendTemplate($this->app_setting, '5561999998888', confirmationTemplate());
        provider()->sendSessionText($this->app_setting, '5561999998888', 'Olá');

        Http::assertSentCount(4);
        expect(Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/account/login')))->toHaveCount(1)
            ->and(Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/app-123/token')))->toHaveCount(1);
    });

    it('[SEGURANÇA] token guardado cifrado no cache, nunca em log', function () {
        $logs = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logs) {
            $logs[] = $e->message . json_encode($e->context);
        });
        fakeGupshup(['partner.gupshup.io/partner/app/app-123/v3/message' => Http::response(['status' => 'error', 'message' => 'Invalid App Details'], 400)]);

        provider()->sendTemplate($this->app_setting, '5561999998888', confirmationTemplate());

        $cached = Cache::get('whatsapp:gupshup:partner-token');
        expect($cached)->toBeString()->not->toContain('PARTNER-TOKEN')
            ->and(implode("\n", $logs))->not->toContain('sk_app_token')
            ->and(implode("\n", $logs))->not->toContain('PARTNER-TOKEN');
    });

    it('401 no envio: descarta os tokens, pega novos e tenta uma vez de novo', function () {
        fakeGupshup(['partner.gupshup.io/partner/app/app-123/v3/message' => Http::sequence()
            ->push(['status' => 'error', 'message' => 'Authentication Failed'], 401)
            ->push(['messages' => [['id' => 'gs-after-401']]])]);

        $result = provider()->sendTemplate($this->app_setting, '5561999998888', confirmationTemplate());

        expect($result['message_id'])->toBe('gs-after-401')
            ->and(Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/account/login')))->toHaveCount(2)
            ->and(Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/v3/message')))->toHaveCount(2);
    });

    it('401 persistente vira falha permanente (auth_failed), sem laço', function () {
        fakeGupshup(['partner.gupshup.io/partner/app/app-123/v3/message' => Http::response(['status' => 'error', 'message' => 'Authentication Failed'], 401)]);

        $result = provider()->sendTemplate($this->app_setting, '5561999998888', confirmationTemplate());

        expect($result['ok'])->toBeFalse()
            ->and($result['error_code'])->toBe('auth_failed')
            ->and($result['retryable'])->toBeFalse()
            ->and(Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/v3/message')))->toHaveCount(2);
    });

    it('sem credenciais do parceiro no .env: falha sem HTTP', function () {
        config(['whatsapp.gupshup.partner_email' => null, 'whatsapp.gupshup.partner_secret' => null, 'whatsapp.gupshup.auth_mode' => '']);
        Http::fake();

        $result = provider()->sendTemplate($this->app_setting, '5561999998888', confirmationTemplate());

        expect($result['error_code'])->toBe('missing_partner_credentials')
            ->and($result['retryable'])->toBeFalse();
        Http::assertNothingSent();
    });
});

describe('autenticação (token universal — recomendado pela Gupshup)', function () {
    it('gera o token do app (UAT) com o token universal e envia com Bearer; reaproveita do cache', function () {
        config(['whatsapp.gupshup.auth_mode' => '', 'whatsapp.gupshup.universal_token' => 'UT-JWT']);
        Http::fake([
            'partner.gupshup.io/partner/app/app-123/token'      => Http::response(['status' => 'success', 'token' => 'UAT-JWT', 'id' => 'tok-1']),
            'partner.gupshup.io/partner/app/app-123/v3/message' => Http::response(['messages' => [['id' => 'gs-uat']]]),
        ]);

        provider()->sendTemplate($this->app_setting, '5561999998888', confirmationTemplate());
        provider()->sendTemplate($this->app_setting, '5561999998888', confirmationTemplate());

        $mint = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/token'));
        expect($mint)->toHaveCount(1);

        [$request] = $mint->first();
        expect($request->method())->toBe('POST')
            ->and($request->header('Authorization'))->toBe(['Bearer UT-JWT'])
            ->and($request['name'])->toStartWith('easyeye-')
            ->and((int) $request['expiry'])->toBeGreaterThan(now()->addHour()->getTimestampMs())
            ->and((int) $request['expiry'])->toBeLessThanOrEqual(now()->addHours(24)->getTimestampMs());

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v3/message') && $r->header('Authorization') === ['Bearer UAT-JWT']);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/account/login'));
    });
});

describe('erros: transitórios × permanentes', function () {
    it('classifica cada resposta', function (mixed $response, string $code, bool $retryable) {
        fakeGupshup(['partner.gupshup.io/partner/app/app-123/v3/message' => $response]);

        $result = provider()->sendTemplate($this->app_setting, '5561999998888', confirmationTemplate());

        expect($result['ok'])->toBeFalse()
            ->and($result['error_code'])->toBe($code)
            ->and($result['retryable'])->toBe($retryable);
    })->with([
        '5xx'                  => [fn () => Http::response(['status' => 'error', 'message' => 'Internal'], 500), 'http_500', true],
        '429'                  => [fn () => Http::response(['status' => 'error', 'message' => 'Too Many Requests'], 429), 'rate_limited', true],
        'Meta 130429 (vazão)'  => [fn () => Http::response(['error' => ['code' => 130429, 'message' => 'Rate limit hit']], 400), 'meta_130429', true],
        'template inexistente' => [fn () => Http::response(['error' => ['code' => 132001, 'message' => 'Template does not exist']], 400), 'meta_132001', false],
        'parâmetros errados'   => [fn () => Http::response(['error' => ['code' => 132000, 'message' => 'Number of parameters mismatch']], 400), 'meta_132000', false],
        'número sem WhatsApp'  => [fn () => Http::response(['code' => 1002, 'reason' => 'Number Does Not Exist On WhatsApp'], 400), 'gupshup_1002', false],
        'número descadastrado' => [fn () => Http::response(['code' => 1012, 'reason' => 'Number Opted Out'], 400), 'opted_out', false],
        '4xx de validação'     => [fn () => Http::response(['status' => 'error', 'message' => 'Invalid App Details'], 400), 'http_400', false],
        // 2xx sem id deixou de ser falha (a mensagem saiu) — WhatsAppReviewFixesTest §6.
    ]);

    it('falha de conexão ANTES de enviar (DNS) é transitória', function () {
        fakeGupshup(['partner.gupshup.io/partner/app/app-123/v3/message' => fn () => throw new ConnectionException('cURL error 6: Could not resolve host: partner.gupshup.io')]);

        $result = provider()->sendTemplate($this->app_setting, '5561999998888', confirmationTemplate());

        expect($result['error_code'])->toBe('connection')
            ->and($result['retryable'])->toBeTrue();
    });

    it('timeout de leitura não é retentado (a mensagem pode ter saído)', function () {
        fakeGupshup(['partner.gupshup.io/partner/app/app-123/v3/message' => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 15001 milliseconds with 0 bytes received')]);

        $result = provider()->sendTemplate($this->app_setting, '5561999998888', confirmationTemplate());

        expect($result['error_code'])->toBe('unknown_delivery')
            ->and($result['retryable'])->toBeFalse();
    });
});

describe('outras chamadas', function () {
    it('texto de sessão no formato v3 da Meta', function () {
        fakeGupshup(['partner.gupshup.io/partner/app/app-123/v3/message' => Http::response(['messages' => [['id' => 'gs-txt']]])]);

        provider()->sendSessionText($this->app_setting, '5561999998888', 'Presença confirmada!');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v3/message') && $r->data() === [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => '5561999998888',
            'type'              => 'text',
            'text'              => ['body' => 'Presença confirmada!', 'preview_url' => false],
        ]);
    });

    it('saúde do app (GET /health)', function () {
        fakeGupshup(['partner.gupshup.io/partner/app/app-123/health' => Http::response(['status' => 'success', 'healthy' => 'true'])]);
        expect(provider()->health($this->app_setting))->toBe(['ok' => true, 'healthy' => true]);
    });

    it('saúde: app que não está no ar volta o erro da Gupshup', function () {
        fakeGupshup(['partner.gupshup.io/partner/app/app-123/health' => Http::response(['status' => 'error', 'message' => 'App is not live.'], 400)]);

        $result = provider()->health($this->app_setting);

        expect($result['ok'])->toBeFalse()
            ->and($result['error'])->toContain('App is not live.');
    });

    it('assinatura v3 do webhook: refaz só a do EasyEye (mesma tag) e manda o segredo no meta', function () {
        fakeGupshup([
            'partner.gupshup.io/partner/app/app-123/subscription/111' => Http::response(['status' => 'success']),
            'partner.gupshup.io/partner/app/app-123/subscription'     => Http::sequence()
                ->push(['status' => 'success', 'subscriptions' => [
                    ['id' => '111', 'tag' => 'easyeye-v3', 'url' => 'https://velho'],
                    ['id' => '222', 'tag' => 'outro-sistema', 'url' => 'https://outro'],
                ]])
                ->push(['status' => 'success', 'subscription' => ['id' => '333', 'tag' => 'easyeye-v3', 'version' => 3]]),
        ]);

        $result = provider()->subscribeWebhook($this->app_setting, 'https://easyeye.test/api/whatsapp/gupshup/webhook/tok', 'segredo-123');

        expect($result)->toBe(['ok' => true, 'subscription_id' => '333']);

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/subscription/111'));
        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/subscription/222'));
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/subscription')
            && $r->isForm()
            && $r['tag'] === 'easyeye-v3'
            && (string) $r['version'] === '3'
            && $r['url'] === 'https://easyeye.test/api/whatsapp/gupshup/webhook/tok'
            && $r['modes'] === 'MESSAGE,SENT,DELIVERED,READ,FAILED'
            && json_decode($r['meta'], true) === ['X-EasyEye-Webhook-Secret' => 'segredo-123']);
    });
});

describe('job de envio com a Gupshup', function () {
    beforeEach(function () {
        $this->entity  = Entity::factory()->create(['is_client' => true, 'name' => 'Clínica Job']);
        $this->setting = WhatsAppSetting::create([
            'entity_id' => $this->entity->id, 'app_id' => 'app-123', 'webhook_token' => WhatsAppSetting::generateWebhookToken(),
            'active'    => true, 'confirmation_enabled' => true, 'confirmation_hours_before' => 24, 'survey_enabled' => true, 'survey_delay_hours' => 0,
        ]);
        $schedule = Schedule::query()->create([
            'entity_id' => $this->entity->id, 'doctor_id' => createDoctorForEntity($this->entity)->id, 'full_name' => 'MARIA DA SILVA',
            'cellphone' => '61999998888', 'cellphone_whatsapp' => true, 'date_time' => now()->addHours(5),
            'situation' => ScheduleSituation::Scheduled->value, 'active' => true,
        ]);
        $this->message = app(WhatsAppService::class)->queueConfirmation($this->setting, $schedule);
    });

    it('falha transitória: lança para a fila tentar de novo (continua pending, erro registrado)', function () {
        fakeGupshup(['partner.gupshup.io/partner/app/app-123/v3/message' => Http::response(['status' => 'error', 'message' => 'Internal'], 503)]);

        expect(fn () => app()->call([new SendWhatsAppMessageJob((string) $this->message->id), 'handle']))
            ->toThrow(RuntimeException::class);

        expect($this->message->fresh()->status)->toBe('pending')
            ->and($this->message->fresh()->error_code)->toBe('http_503');
    });

    it('falha permanente: failed na hora, sem nova tentativa', function () {
        fakeGupshup(['partner.gupshup.io/partner/app/app-123/v3/message' => Http::response(['error' => ['code' => 132001, 'message' => 'Template does not exist']], 400)]);

        app()->call([new SendWhatsAppMessageJob((string) $this->message->id), 'handle']);

        $message = $this->message->fresh();
        expect($message->status)->toBe('failed')
            ->and($message->error_code)->toBe('meta_132001')
            ->and($message->failed_at)->not->toBeNull();
    });

    it('número descadastrado na Gupshup (1012): failed e entra na supressão', function () {
        fakeGupshup(['partner.gupshup.io/partner/app/app-123/v3/message' => Http::response(['code' => 1012, 'reason' => 'Number Opted Out'], 400)]);

        app()->call([new SendWhatsAppMessageJob((string) $this->message->id), 'handle']);

        expect($this->message->fresh()->error_code)->toBe('opted_out')
            ->and(WhatsAppOptOut::isSuppressed($this->setting, '5561999998888'))->toBeTrue();
    });

    it('sucesso grava o id da Gupshup', function () {
        fakeGupshup(['partner.gupshup.io/partner/app/app-123/v3/message' => Http::response(['messages' => [['id' => 'gs-job-1']]])]);

        app()->call([new SendWhatsAppMessageJob((string) $this->message->id), 'handle']);

        expect($this->message->fresh()->provider_message_id)->toBe('gs-job-1')
            ->and(WhatsAppMessage::query()->where('status', 'sent')->count())->toBe(1);
    });
});
