<?php

declare(strict_types=1);

use App\Enums\{SaasRule, ScheduleSituation};
use App\Http\Middleware\EnsurePhoneVerified;
use App\Jobs\WhatsApp\{ProcessWhatsAppStatusJob, SendPhoneVerificationCodeJob, SendSaasWhatsAppNoticeJob, SendWhatsAppMessageJob};
use App\Models\{Entity, Schedule, User};
use App\Models\WhatsApp\{WhatsAppMessage, WhatsAppSetting};
use App\Notifications\WhatsAppOperationalAlertNotification;
use App\Services\Auth\PhoneVerificationService;
use App\Services\Billing\ClinicServiceGate;
use App\Services\WhatsApp\Contracts\WhatsAppProvider;
use App\Services\WhatsApp\Providers\GupshupAuth;
use App\Services\WhatsApp\{WhatsAppAlerts, WhatsAppService};
use App\Services\WhatsApp\WhatsAppTemplates;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\{Artisan, Cache, DB, Http, Notification as NotificationFacade};
use Illuminate\Support\Str;

/**
 * Revisão FINAL da migração para a Gupshup (05/10/2026) — um bloco por
 * achado; cada teste falha sem a correção correspondente (as reproduções P1..P7
 * do revisor viraram testes aqui).
 */

// ── helpers (prefixo fr: as funções globais dos testes são compartilhadas) ──

function frGlobal(array $overrides = []): WhatsAppSetting
{
    return WhatsAppSetting::create(array_merge([
        'entity_id'                 => null,
        'app_id'                    => 'global-fr',
        'webhook_token'             => WhatsAppSetting::generateWebhookToken(),
        'webhook_secret'            => 'segredo-global-fr',
        'active'                    => true,
        'confirmation_enabled'      => false,
        'survey_enabled'            => false,
        'confirmation_hours_before' => 24,
        'survey_delay_hours'        => 2,
    ], $overrides));
}

/** Clínica que envia pelo número GLOBAL + consulta agendada para daqui a 5h. */
function frClinic(string $name): array
{
    $entity  = Entity::factory()->create(['is_client' => true, 'name' => $name]);
    $setting = WhatsAppSetting::create([
        'entity_id' => $entity->id, 'app_id' => null, 'webhook_token' => WhatsAppSetting::generateWebhookToken(),
        'active'    => true, 'confirmation_enabled' => true, 'confirmation_hours_before' => 24, 'survey_enabled' => true, 'survey_delay_hours' => 0,
    ]);
    $schedule = Schedule::query()->create([
        'entity_id' => $entity->id, 'doctor_id' => createDoctorForEntity($entity)->id, 'full_name' => 'MARIA SILVA',
        'cellphone' => '61999998888', 'cellphone_whatsapp' => true, 'date_time' => now()->addHours(5)->startOfMinute(),
        'situation' => ScheduleSituation::Scheduled->value, 'active' => true,
    ]);

    return [$entity, $setting, $schedule];
}

function frSend(WhatsAppSetting $setting, Schedule $schedule): WhatsAppMessage
{
    $message = app(WhatsAppService::class)->queueConfirmation($setting, $schedule->fresh());
    SendWhatsAppMessageJob::dispatchSync((string) $message->id);

    return $message->fresh();
}

function frPost($test, WhatsAppSetting $global, array $message)
{
    return $test->withHeaders([WhatsAppSetting::WEBHOOK_SECRET_HEADER => (string) $global->webhook_secret])
        ->postJson('/api/whatsapp/gupshup/webhook/' . $global->webhook_token, [
            'gs_app_id' => $global->app_id,
            'entry'     => [['changes' => [['field' => 'messages', 'value' => ['messages' => [$message]]]]]],
        ]);
}

function frButton(string $payload, string $label, array $extra = []): array
{
    return array_merge(['from' => '5561999998888', 'id' => 'wamid.' . Str::random(12), 'timestamp' => (string) now()->timestamp, 'type' => 'button', 'button' => ['payload' => $payload, 'text' => $label]], $extra);
}

function frText(string $text, array $extra = []): array
{
    return array_merge(['from' => '5561999998888', 'id' => 'wamid.' . Str::random(12), 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $text]], $extra);
}

function frLastAck(): ?string
{
    return WhatsAppMessage::query()->where('kind', 'ack')->orderByDesc('created_at')->orderByDesc('id')->value('body');
}

function frGate(User $user): int
{
    $request = Request::create('/panel', 'GET');
    $request->setUserResolver(fn () => $user);

    return app(EnsurePhoneVerified::class)->handle($request, fn () => response('painel'))->getStatusCode();
}

function frUniversal(string $token = 'UT-JWT'): void
{
    config([
        'whatsapp.driver'                          => 'gupshup',
        'whatsapp.gupshup.base_url'                => 'https://partner.gupshup.io',
        'whatsapp.gupshup.auth_mode'               => '',
        'whatsapp.gupshup.universal_token'         => $token,
        'whatsapp.gupshup.partner_email'           => null,
        'whatsapp.gupshup.partner_secret'          => null,
        'whatsapp.gupshup.token_lock_wait_seconds' => 0,
    ]);
}

beforeEach(function () {
    config(['whatsapp.driver' => 'mock']);
    Cache::flush();
});

// ─────────────────────────────────────────────────────────────────────────────
// 1. [ALTA] Botão com payload nosso nunca cai no texto livre
// ─────────────────────────────────────────────────────────────────────────────

describe('1. botão de mensagem não encontrada não cai no texto livre', function () {
    beforeEach(function () {
        $this->global           = frGlobal();
        [, $this->sa, $this->a] = frClinic('CLINICA A');
        [, $this->sb, $this->b] = frClinic('CLINICA B');
    });

    it('P1: "Cancelar" de B (failed unknown_delivery, mas entregue) cancela B — nunca A', function () {
        $mA = frSend($this->sa, $this->a);
        $mB = frSend($this->sb, $this->b);
        // Timeout de leitura no envio de B: failed unknown_delivery, sem id.
        $mB->update(['status' => 'failed', 'error_code' => 'unknown_delivery', 'provider_message_id' => null, 'sent_at' => null]);

        frPost($this, $this->global, frButton("cancel:{$mB->id}", 'Cancelar'))->assertOk();

        expect($this->a->fresh()->situation)->toBe(ScheduleSituation::Scheduled)
            ->and($mA->fresh()->status)->toBe('sent')
            ->and($this->b->fresh()->situation)->toBe(ScheduleSituation::Cancelled)
            ->and($mB->fresh()->status)->toBe('answered');
    });

    it('botão de mensagem desconhecida (ou de outro telefone) não muda nada e pede o botão', function () {
        $mA = frSend($this->sa, $this->a);

        frPost($this, $this->global, frButton('cancel:' . Str::uuid(), 'Cancelar'))->assertOk();

        expect($this->a->fresh()->situation)->toBe(ScheduleSituation::Scheduled)
            ->and($mA->fresh()->status)->toBe('sent')
            ->and(frLastAck())->toBe(__('whatsapp.patient.use_buttons'));
    });

    it('botão de tipo incompatível (nota apontando para confirmação) não cai no texto livre', function () {
        $mA = frSend($this->sa, $this->a);

        frPost($this, $this->global, frButton("survey:{$mA->id}:2", '2 - Ruim'))->assertOk();

        expect($this->a->fresh()->situation)->toBe(ScheduleSituation::Scheduled)
            ->and($mA->fresh()->status)->toBe('sent')
            ->and(frLastAck())->toBe(__('whatsapp.patient.use_buttons'));
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// 2. [MÉDIA] Texto livre / citação não pegam a consulta errada
// ─────────────────────────────────────────────────────────────────────────────

describe('2. texto livre só com a candidata sendo a última mensagem; citação só da citada', function () {
    beforeEach(function () {
        $this->global           = frGlobal();
        [, $this->sa, $this->a] = frClinic('CLINICA A');
        [, $this->sb, $this->b] = frClinic('CLINICA B');
    });

    it('P2: confirma B pelo botão e depois manda "Não" (pensando em B) — A não é cancelada', function () {
        frSend($this->sa, $this->a);
        $mB = frSend($this->sb, $this->b);

        frPost($this, $this->global, frButton("confirm:{$mB->id}", 'Confirmar'))->assertOk();
        frPost($this, $this->global, frText('Não'))->assertOk();

        expect($this->b->fresh()->situation)->toBe(ScheduleSituation::Confirmed)
            ->and($this->a->fresh()->situation)->toBe(ScheduleSituation::Scheduled)
            ->and(frLastAck())->toBe(__('whatsapp.patient.use_buttons'));
    });

    it('P3: resposta citando a resposta automática de B com "2" não cai na candidata A', function () {
        frSend($this->sa, $this->a);
        $mB = frSend($this->sb, $this->b);

        frPost($this, $this->global, frButton("confirm:{$mB->id}", 'Confirmar'))->assertOk();
        $ack = WhatsAppMessage::query()->where('kind', 'ack')->latest()->firstOrFail();

        frPost($this, $this->global, frText('2', ['context' => ['id' => (string) $ack->provider_message_id]]))->assertOk();

        expect($this->a->fresh()->situation)->toBe(ScheduleSituation::Scheduled)
            ->and(frLastAck())->toBe(__('whatsapp.patient.use_buttons'));
    });

    it('citação de mensagem que o sistema não conhece também não usa a candidata', function () {
        frSend($this->sa, $this->a);

        frPost($this, $this->global, frText('Não', ['context' => ['id' => 'wamid.de-outro-sistema']]))->assertOk();

        expect($this->a->fresh()->situation)->toBe(ScheduleSituation::Scheduled);
    });

    it('com A como última mensagem, "Não" ainda cancela A; a citação da própria A também vale', function () {
        $mA = frSend($this->sa, $this->a);
        $mA->update(['wa_message_id' => 'wamid.da-confirmacao-a']);

        frPost($this, $this->global, frText('Sim', ['context' => ['id' => 'wamid.da-confirmacao-a']]))->assertOk();

        expect($this->a->fresh()->situation)->toBe(ScheduleSituation::Confirmed);
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// 3. [MÉDIA] Remarcação: confirmação antiga não vale; nova sai com a nova data
// ─────────────────────────────────────────────────────────────────────────────

describe('3. consulta remarcada', function () {
    beforeEach(function () {
        $this->global           = frGlobal();
        [, $this->sa, $this->a] = frClinic('CLINICA A');
    });

    it('remarcar marca a confirmação como substituída; o botão antigo não confirma e responde "remarcada"', function () {
        $old = frSend($this->sa, $this->a);
        expect($old->payload['schedule_at'])->toBe($this->a->fresh()->date_time->format('Y-m-d H:i:s'));

        $this->a->fresh()->update(['date_time' => now()->addHours(8)->startOfMinute()]);

        expect($old->fresh()->status)->toBe('skipped')
            ->and($old->fresh()->error_code)->toBe(WhatsAppMessage::ERROR_RESCHEDULED);

        frPost($this, $this->global, frButton("confirm:{$old->id}", 'Confirmar'))->assertOk();

        expect($this->a->fresh()->situation)->toBe(ScheduleSituation::Scheduled)
            ->and(frLastAck())->toBe(__('whatsapp.patient.rescheduled'));
    });

    it('o comando reenvia a MESMA linha com a nova data; a nova resposta confirma', function () {
        $old = frSend($this->sa, $this->a);
        $new = now()->addHours(8)->startOfMinute();
        $this->a->fresh()->update(['date_time' => $new]);

        Artisan::call('whatsapp:send-confirmations');

        $again = $old->fresh();
        expect(WhatsAppMessage::query()->where('schedule_id', $this->a->id)->where('kind', 'confirmation')->count())->toBe(1)
            ->and($again->status)->toBe('sent')
            ->and($again->payload['schedule_at'])->toBe($new->format('Y-m-d H:i:s'))
            ->and($again->answered_at)->toBeNull();

        frPost($this, $this->global, frButton("confirm:{$again->id}", 'Confirmar'))->assertOk();

        expect($this->a->fresh()->situation)->toBe(ScheduleSituation::Confirmed);
    });

    it('data mudada por fora do model (sem o evento): a resposta confere a data e não aplica', function () {
        $old = frSend($this->sa, $this->a);
        DB::table('schedules')->where('id', $this->a->id)->update(['date_time' => now()->addDays(2)->startOfMinute()]);

        frPost($this, $this->global, frButton("cancel:{$old->id}", 'Cancelar'))->assertOk();

        expect($this->a->fresh()->situation)->toBe(ScheduleSituation::Scheduled)
            ->and(frLastAck())->toBe(__('whatsapp.patient.rescheduled'));
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// 4. [MÉDIA] Token universal vencido / alerta de canal fora do ar soltam o gate
// ─────────────────────────────────────────────────────────────────────────────

describe('4. gate do cadastro com o canal fora do ar', function () {
    beforeEach(function () {
        Http::preventStrayRequests();
        frGlobal(['app_id' => 'global-otp-fr']);
        $this->user = User::factory()->create(['phone' => '11988887777']);
    });

    it('P4: token universal recusado (401) — o código não sai e o gate libera (alerta marca o canal)', function () {
        frUniversal('UT-VENCIDO');
        Http::fake(['partner.gupshup.io/partner/app/*/token' => Http::response(['status' => 'error', 'message' => 'Unauthorized'], 401)]);

        expect(frGate($this->user))->toBe(302);

        app(PhoneVerificationService::class)->sendCode($this->user);

        expect(WhatsAppAlerts::channelDown('global-otp-fr'))->toBeTrue()
            ->and(frGate($this->user))->toBe(200);
    });

    it('a marca expira em 15 min; com as tentativas esgotadas (failed universal_token_invalid) o gate segue liberado', function () {
        frUniversal();
        WhatsAppAlerts::critical('uat_limit', ['app_id' => 'global-otp-fr']);
        expect(frGate($this->user))->toBe(200);

        $this->travel(WhatsAppAlerts::CHANNEL_DOWN_MINUTES + 1)->minutes();
        expect(frGate($this->user))->toBe(302);

        WhatsAppMessage::create([
            'direction' => 'out', 'kind' => WhatsAppMessage::KIND_VERIFICATION, 'phone' => '5511988887777', 'body' => 'código ******',
            'status'    => 'failed', 'error_code' => 'universal_token_invalid', 'failed_at' => now(), 'payload' => ['user_id' => (string) $this->user->id],
        ]);

        expect(frGate($this->user))->toBe(200);
    });

    it('alerta de saldo de OUTRO app (número próprio de clínica) não solta o gate do app global', function () {
        frUniversal();
        WhatsAppAlerts::critical('wallet_low', ['app_id' => 'app-da-clinica']);

        expect(frGate($this->user))->toBe(302);
    });

    it('token universal com validade vencida (GUPSHUP_UNIVERSAL_TOKEN_EXPIRES_AT): gate libera sem tentar', function () {
        frUniversal();
        config(['whatsapp.gupshup.universal_token_expires_at' => now()->subDay()->toDateString()]);

        expect(PhoneVerificationService::channelAvailable())->toBeFalse()
            ->and(frGate($this->user))->toBe(200);

        config(['whatsapp.gupshup.universal_token_expires_at' => now()->addDays(30)->toDateString()]);
        expect(frGate($this->user))->toBe(302);
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// 5. [MÉDIA] Falha transitória esgotada não perde a confirmação/pesquisa
// ─────────────────────────────────────────────────────────────────────────────

describe('5. falha transitória vira skipped (o comando reaproveita)', function () {
    beforeEach(function () {
        [, $this->sa, $this->a] = frClinic('CLINICA A');
        $this->global           = frGlobal();
    });

    it('P5: tentativas esgotadas com token universal recusado → skipped com o código; o comando reenvia', function () {
        $message = app(WhatsAppService::class)->queueConfirmation($this->sa, $this->a->fresh());
        $message->update(['status' => 'pending', 'error_code' => 'universal_token_invalid']);

        (new SendWhatsAppMessageJob((string) $message->id))->failed(new RuntimeException('WhatsApp send failed: universal_token_invalid'));

        expect($message->fresh()->status)->toBe('skipped')
            ->and($message->fresh()->error_code)->toBe('universal_token_invalid');

        Artisan::call('whatsapp:send-confirmations');

        expect($message->fresh()->status)->toBe('sent')
            ->and($message->fresh()->error_code)->toBeNull();
    });

    it('falha permanente esgotada continua failed', function () {
        $message = app(WhatsAppService::class)->queueConfirmation($this->sa, $this->a->fresh());
        $message->update(['status' => 'pending', 'error_code' => 'meta_132001']);

        (new SendWhatsAppMessageJob((string) $message->id))->failed(new RuntimeException('x'));

        expect($message->fresh()->status)->toBe('failed');
    });

    it('status "failed" transitório (Meta 131000) do webhook → skipped; permanente → failed', function () {
        $message = frSend($this->sa, $this->a);

        (new ProcessWhatsAppStatusJob((string) $this->global->id, ['gs_id' => $message->provider_message_id, 'status' => 'failed', 'code' => 131000, 'reason' => 'Something went wrong']))
            ->handle(app(WhatsAppService::class));

        expect($message->fresh()->status)->toBe('skipped')
            ->and($message->fresh()->error_code)->toBe('meta_131000');

        $message->fresh()->update(['status' => 'sent', 'failed_at' => null]);
        (new ProcessWhatsAppStatusJob((string) $this->global->id, ['gs_id' => $message->provider_message_id, 'status' => 'failed', 'code' => 131026]))
            ->handle(app(WhatsAppService::class));

        expect($message->fresh()->status)->toBe('failed');
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// 6/7. Driver mock só exige o código em local / testes automatizados
// ─────────────────────────────────────────────────────────────────────────────

describe('6/7. mock na homologação (APP_ENV=testing) não prende o cadastro', function () {
    beforeEach(function () {
        frGlobal(['app_id' => 'global-mock-fr']);
        $this->user = User::factory()->create(['phone' => '11988887777']);
    });

    afterEach(fn () => app()->detectEnvironment(fn () => 'testing'));

    it('homologação (testing sem WHATSAPP_MOCK_REQUIRES_CODE): gate libera e nada é gerado', function () {
        config(['whatsapp.mock_requires_code' => false]);

        expect(frGate($this->user))->toBe(200)
            ->and(app(PhoneVerificationService::class)->sendCode($this->user))->toBeFalse();
    });

    it('testes automatizados (chave ligada) e dev local exigem; produção nunca', function () {
        expect(frGate($this->user))->toBe(302); // phpunit.xml: WHATSAPP_MOCK_REQUIRES_CODE=true

        config(['whatsapp.mock_requires_code' => false]);
        app()->detectEnvironment(fn () => 'local');
        expect(frGate($this->user))->toBe(302);

        config(['whatsapp.mock_requires_code' => true]);
        app()->detectEnvironment(fn () => 'production');
        expect(frGate($this->user))->toBe(200);
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// 10. [BAIXA] Gate: lista BRANCA de falhas de configuração
// ─────────────────────────────────────────────────────────────────────────────

describe('10. falhas de configuração em lista branca', function () {
    it('código desconhecido/genérico ou do destinatário não conta como configuração', function () {
        foreach (['http_400', 'meta_131009', 'gupshup_1006', 'gupshup_1008', 'gupshup_1002', 'opted_out', 'meta_999999', 'unknown_delivery', 'universal_token_invalid', 'gupshup_1003', null] as $code) {
            expect(PhoneVerificationService::isConfigurationFailure($code))->toBeFalse("{$code} não é configuração");
        }

        foreach (['missing_app', 'missing_partner_credentials', 'auth_failed', 'meta_132001', 'meta_132015', 'meta_132016', 'meta_131030', 'gupshup_4003', 'gupshup_4005', 'gupshup_1001', 'gupshup_1009'] as $code) {
            expect(PhoneVerificationService::isConfigurationFailure($code))->toBeTrue("{$code} é configuração");
        }
    });

    it('último envio com falha 1006 (sem opt-in na Gupshup) não solta o gate', function () {
        $global = frGlobal(['app_id' => 'global-wl-fr']);
        $user   = User::factory()->create(['phone' => '11988887777']);
        useGupshupDriver();

        WhatsAppMessage::create([
            'whatsapp_setting_id' => $global->id, 'direction' => 'out', 'kind' => WhatsAppMessage::KIND_VERIFICATION, 'phone' => '5511988887777',
            'body'                => 'código ******', 'status' => 'failed', 'error_code' => 'gupshup_1006', 'failed_at' => now(), 'payload' => ['user_id' => (string) $user->id],
        ]);

        expect(frGate($user))->toBe(302);
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// 11. [BAIXA] Flood com tokens aleatórios no webhook
// ─────────────────────────────────────────────────────────────────────────────

describe('11. limite global para token inexistente', function () {
    it('P6: tokens aleatórios estouram o balde global (429); o token de verdade tem o seu', function () {
        config(['whatsapp.webhook.unknown_rate_limit_per_minute' => 3, 'whatsapp.webhook.rate_limit_per_minute' => 1200]);
        $global = frGlobal();

        $codes = [];

        for ($i = 0; $i < 5; $i++) {
            $codes[] = $this->postJson('/api/whatsapp/gupshup/webhook/' . Str::random(48), [])->status();
        }

        expect($codes)->toBe([404, 404, 404, 429, 429]);

        frPost($this, $global, frText('oi'))->assertOk();
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// 12. [BAIXA] Linhas presas em `sending`; failed() dos jobs; sucesso limpa erro
// ─────────────────────────────────────────────────────────────────────────────

describe('12. mensagens presas em envio', function () {
    beforeEach(function () {
        $this->global = frGlobal();
    });

    it('whatsapp:sweep-stuck: sending velho vira failed unknown_delivery + alerta; o recente fica', function () {
        NotificationFacade::fake();
        $saas  = Entity::factory()->create(['is_client' => false, 'active' => true]);
        $admin = User::factory()->create(['email_verified_at' => now()]);
        createEntityUser($saas, $admin, SaasRule::Admin->value);

        $old = WhatsAppMessage::create(['whatsapp_setting_id' => $this->global->id, 'direction' => 'out', 'kind' => 'saas_notice', 'phone' => '5511988887777', 'body' => 'x', 'status' => 'sending']);
        DB::table('whatsapp_messages')->where('id', $old->id)->update(['updated_at' => now()->subMinutes(30)]);
        $recent = WhatsAppMessage::create(['whatsapp_setting_id' => $this->global->id, 'direction' => 'out', 'kind' => 'confirmation', 'phone' => '5511988887777', 'body' => 'y', 'status' => 'sending']);

        $this->artisan('whatsapp:sweep-stuck')->assertSuccessful();

        expect($old->fresh()->status)->toBe('failed')
            ->and($old->fresh()->error_code)->toBe('unknown_delivery')
            ->and($old->fresh()->failed_at)->not->toBeNull()
            ->and($recent->fresh()->status)->toBe('sending');
        NotificationFacade::assertSentTo($admin, WhatsAppOperationalAlertNotification::class, fn ($n) => $n->type === 'stuck_sending');
    });

    it('o comando está agendado a cada 10 minutos', function () {
        $event = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'whatsapp:sweep-stuck'));

        expect($event)->not->toBeNull()
            ->and($event->expression)->toBe('*/10 * * * *');
    });

    it('failed() do aviso do SaaS e do código fecham a linha presa', function () {
        $notice = new SendSaasWhatsAppNoticeJob((string) Str::uuid(), new class() extends Notification {}, 'pt_BR');
        $code   = new SendPhoneVerificationCodeJob((string) Str::uuid(), '5511988887777', '123456');

        $a = WhatsAppMessage::create(['direction' => 'out', 'kind' => 'saas_notice', 'phone' => '5511988887777', 'body' => 'x', 'status' => 'sending', 'payload' => ['send_key' => $notice->sendKey]]);
        $b = WhatsAppMessage::create(['direction' => 'out', 'kind' => 'verification', 'phone' => '5511988887777', 'body' => 'y', 'status' => 'pending', 'error_code' => 'connection', 'payload' => ['send_key' => $code->sendKey]]);

        $notice->failed(new RuntimeException('timeout do job'));
        $code->failed(new RuntimeException('timeout do job'));

        expect($a->fresh()->status)->toBe('failed')->and($a->fresh()->error_code)->toBe('unknown_delivery')
            ->and($b->fresh()->status)->toBe('failed')->and($b->fresh()->error_code)->toBe('connection');
    });

    it('envio com sucesso limpa failed_at/error_code de tentativa anterior', function () {
        [, $setting, $schedule] = frClinic('CLINICA A');
        $message                = app(WhatsAppService::class)->queueConfirmation($setting, $schedule->fresh());
        $message->update(['error_code' => 'connection', 'error' => 'Falha de conexão', 'failed_at' => now()->subMinute()]);

        SendWhatsAppMessageJob::dispatchSync((string) $message->id);

        expect($message->fresh()->status)->toBe('sent')
            ->and($message->fresh()->error_code)->toBeNull()
            ->and($message->fresh()->error)->toBeNull()
            ->and($message->fresh()->failed_at)->toBeNull();
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// 13. [BAIXA] Autenticação antes da reserva `sending`; lock longo o bastante
// ─────────────────────────────────────────────────────────────────────────────

describe('13. reserva só depois da autenticação', function () {
    beforeEach(function () {
        Http::preventStrayRequests();
        frUniversal();
        [, $this->setting, $this->schedule] = frClinic('CLINICA A');
        $this->global                       = frGlobal(['app_id' => 'app-lock-fr']);
    });

    it('a linha ainda está pending enquanto o token é gerado (sob lock de ≥ 120 s)', function () {
        $message = app(WhatsAppService::class)->queueConfirmation($this->setting, $this->schedule->fresh());
        $seen    = new ArrayObject();

        Http::fake([
            'partner.gupshup.io/partner/app/app-lock-fr/token' => function () use ($message, $seen) {
                $seen['status'] = WhatsAppMessage::query()->whereKey($message->id)->value('status');
                $lock           = Cache::getStore()->locks[GupshupAuth::lockKey('app-lock-fr')] ?? null;
                $seen['ttl']    = $lock ? $lock['expiresAt']->diffInSeconds(now(), true) : null;

                return Http::response(['status' => 'success', 'token' => 'UAT-1', 'id' => 'tok-1']);
            },
            'partner.gupshup.io/partner/app/app-lock-fr/v3/message' => Http::response(['messages' => [['id' => 'gs-1']]]),
        ]);

        SendWhatsAppMessageJob::dispatchSync((string) $message->id);

        expect($seen['status'])->toBe('pending')
            ->and($seen['ttl'])->toBeGreaterThanOrEqual(119)
            ->and($message->fresh()->status)->toBe('sent');
    });

    it('falha transitória na autenticação: a linha nunca passa por sending e volta para a fila', function () {
        $message = app(WhatsAppService::class)->queueConfirmation($this->setting, $this->schedule->fresh());
        Http::fake(['partner.gupshup.io/partner/app/app-lock-fr/token' => Http::response('erro', 503)]);

        expect(fn () => (new SendWhatsAppMessageJob((string) $message->id))->handle(
            app(WhatsAppProvider::class),
            app(WhatsAppService::class),
            app(WhatsAppTemplates::class),
            app(ClinicServiceGate::class),
        ))->toThrow(RuntimeException::class);

        expect($message->fresh()->status)->toBe('pending')
            ->and($message->fresh()->error_code)->toBe('http_503');
        Http::assertNotSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/v3/message'));
    });

    it('timeout dos jobs de envio cobre o lock do token', function () {
        $lock = GupshupAuth::lockSeconds();

        expect($lock)->toBeGreaterThanOrEqual(120)
            ->and((new SendWhatsAppMessageJob('x'))->timeout)->toBeGreaterThan($lock)
            ->and((new SendPhoneVerificationCodeJob('u', '5511988887777', '123456'))->timeout)->toBeGreaterThan($lock)
            ->and((new SendSaasWhatsAppNoticeJob('u', new class() extends Notification {}, 'pt_BR'))->timeout)->toBeGreaterThan($lock);
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// 14. [BAIXA] Migrations sem SQL só do PostgreSQL no que dá para portar
// ─────────────────────────────────────────────────────────────────────────────

describe('14. migrations 2026_10_08', function () {
    it('tokens e limpeza da auditoria em PHP (sem md5(random())/jsonb); índices parciais documentados', function () {
        $gupshup = file_get_contents(database_path('migrations/2026_10_08_000000_migrate_whatsapp_to_gupshup.php'));
        $scrub   = file_get_contents(database_path('migrations/2026_10_08_000100_scrub_phone_verification_from_audit_logs.php'));

        foreach (['md5(random()', '::jsonb', '::json', 'clock_timestamp'] as $pgOnly) {
            expect($gupshup)->not->toContain($pgOnly);
        }

        expect($scrub)->not->toContain('jsonb_exists_any')->not->toContain('::jsonb')
            ->and($gupshup)->toContain('MySQL/MariaDB/SQL Server');
    });

    it('a migration troca os tokens (64 hex) e tira segredos da auditoria do WhatsAppSetting', function () {
        $setting = frGlobal();
        $before  = $setting->webhook_token;
        $auditId = (string) Str::uuid();
        DB::table('audit_logs')->insert([
            'id'         => $auditId, 'auditable_type' => WhatsAppSetting::class, 'auditable_id' => $setting->id, 'event' => 'updated',
            'old_values' => json_encode(['webhook_token' => 'vazado', 'active' => false]), 'new_values' => json_encode(['webhook_token' => 'vazado-2', 'active' => true]),
            'created_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_10_08_000000_migrate_whatsapp_to_gupshup.php');
        $migration->down();
        $migration->up();

        $token = DB::table('whatsapp_settings')->where('id', $setting->id)->value('webhook_token');
        $audit = DB::table('audit_logs')->where('id', $auditId)->first();

        expect($token)->toMatch('/^[0-9a-f]{64}$/')->not->toBe($before)
            ->and(json_decode($audit->old_values, true))->toBe(['active' => false])
            ->and(json_decode($audit->new_values, true))->toBe(['active' => true]);
    });
});
