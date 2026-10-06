<?php

declare(strict_types=1);

use App\Contracts\Notifications\SendsSaasWhatsApp;
use App\Enums\{ClientRule, FeatureKey, SaasRule, ScheduleSituation, SubscriptionStatus};
use App\Http\Controllers\Manager\WhatsAppController;
use App\Http\Middleware\EnsurePhoneVerified;
use App\Jobs\WhatsApp\{ProcessWhatsAppStatusJob, SendPhoneVerificationCodeJob, SendSaasWhatsAppNoticeJob, SendWhatsAppMessageJob};
use App\Models\{Entity, Patient, People, Plan, PlanFeature, Schedule, Subscription, User};
use App\Models\WhatsApp\{WhatsAppMessage, WhatsAppSetting};
use App\Notifications\WhatsAppOperationalAlertNotification;
use App\Services\Auth\PhoneVerificationService;
use App\Services\Lgpd\PatientDataExporter;
use App\Services\WhatsApp\Contracts\WhatsAppProvider;
use App\Services\WhatsApp\Providers\GupshupAuth;
use App\Services\WhatsApp\{WhatsAppService, WhatsAppTemplates};
use Illuminate\Http\Client\{ConnectionException, Request as HttpRequest};
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\{Cache, DB, Event, Http, Notification as NotificationFacade, Queue};
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Correções da revisão da migração para a Gupshup (05/10/2026) — um bloco por
 * achado; cada teste falha sem a correção correspondente.
 */

// ── helpers (prefixo rf: as funções globais dos testes são compartilhadas) ──

function rfGlobal(array $overrides = []): WhatsAppSetting
{
    return WhatsAppSetting::create(array_merge([
        'entity_id'                 => null,
        'app_id'                    => 'global-rf',
        'webhook_token'             => WhatsAppSetting::generateWebhookToken(),
        'webhook_secret'            => 'segredo-global-rf',
        'active'                    => true,
        'confirmation_enabled'      => false,
        'survey_enabled'            => false,
        'confirmation_hours_before' => 24,
        'survey_delay_hours'        => 2,
    ], $overrides));
}

/** Clínica que envia pelo número GLOBAL (sem app próprio) + consulta agendada. */
function rfClinic(string $name, string $cellphone = '61999998888', ?string $appId = null): array
{
    $entity  = Entity::factory()->create(['is_client' => true, 'name' => $name]);
    $setting = WhatsAppSetting::create([
        'entity_id'      => $entity->id, 'app_id' => $appId, 'webhook_token' => WhatsAppSetting::generateWebhookToken(),
        'webhook_secret' => $appId ? 'segredo-' . $appId : null,
        'active'         => true, 'confirmation_enabled' => true, 'confirmation_hours_before' => 24, 'survey_enabled' => true, 'survey_delay_hours' => 0,
    ]);
    $schedule = Schedule::query()->create([
        'entity_id' => $entity->id, 'doctor_id' => createDoctorForEntity($entity)->id, 'full_name' => 'MARIA DA SILVA',
        'cellphone' => $cellphone, 'cellphone_whatsapp' => true, 'date_time' => now()->addHours(5),
        'situation' => ScheduleSituation::Scheduled->value, 'active' => true,
    ]);

    return [$entity, $setting, $schedule];
}

function rfSend(WhatsAppSetting $setting, Schedule $schedule, string $kind = 'confirmation'): WhatsAppMessage
{
    $service = app(WhatsAppService::class);
    $message = $kind === 'survey'
        ? $service->queueSurvey($setting, $schedule->fresh())
        : $service->queueConfirmation($setting, $schedule->fresh());

    SendWhatsAppMessageJob::dispatchSync((string) $message->id);

    return $message->fresh();
}

function rfPost($test, WhatsAppSetting $setting, array $message)
{
    return $test->withHeaders([WhatsAppSetting::WEBHOOK_SECRET_HEADER => (string) $setting->webhook_secret])
        ->postJson('/api/whatsapp/gupshup/webhook/' . $setting->webhook_token, [
            'gs_app_id' => $setting->app_id,
            'object'    => 'whatsapp_business_account',
            'entry'     => [['id' => 'WABA', 'changes' => [['field' => 'messages', 'value' => ['messaging_product' => 'whatsapp', 'messages' => [$message]]]]]],
        ]);
}

function rfText(string $text, string $from = '5561999998888'): array
{
    return ['from' => $from, 'id' => 'wamid.' . Str::random(10), 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $text]];
}

function rfButton(string $payload, string $label, string $from = '5561999998888'): array
{
    return ['from' => $from, 'id' => 'wamid.' . Str::random(10), 'timestamp' => (string) now()->timestamp, 'type' => 'button', 'button' => ['payload' => $payload, 'text' => $label]];
}

function rfLastAck(): ?string
{
    return WhatsAppMessage::query()->where('kind', 'ack')->orderByDesc('created_at')->orderByDesc('id')->value('body');
}

function rfUniversalAuth(): void
{
    config([
        'whatsapp.driver'                          => 'gupshup',
        'whatsapp.gupshup.base_url'                => 'https://partner.gupshup.io',
        'whatsapp.gupshup.auth_mode'               => '',
        'whatsapp.gupshup.universal_token'         => 'UT-JWT',
        'whatsapp.gupshup.partner_email'           => null,
        'whatsapp.gupshup.partner_secret'          => null,
        'whatsapp.gupshup.token_lock_wait_seconds' => 0,
    ]);
}

/** Admin do SaaS com e-mail verificado (recebe os alertas). */
function rfSaasAdmin(): User
{
    $saas = Entity::factory()->create(['is_client' => false, 'active' => true]);
    $user = User::factory()->create(['email_verified_at' => now()]);
    createEntityUser($saas, $user, SaasRule::Admin->value);

    return $user;
}

/** @return list<string> mensagens de log críticas */
function rfCaptureCritical(): ArrayObject
{
    $logs = new ArrayObject();
    Event::listen(MessageLogged::class, function (MessageLogged $e) use ($logs) {
        if ($e->level === 'critical') {
            $logs[] = $e->message;
        }
    });

    return $logs;
}

beforeEach(function () {
    config(['whatsapp.driver' => 'mock']);
    Cache::flush();
});

// ─────────────────────────────────────────────────────────────────────────────
// 1. [ALTA] Texto livre só com resposta exata e uma única candidata
// ─────────────────────────────────────────────────────────────────────────────

describe('1. texto livre não mexe em consulta de outra clínica', function () {
    beforeEach(function () {
        $this->global           = rfGlobal();
        [, $this->sa, $this->a] = rfClinic('CLINICA A');
        [, $this->sb, $this->b] = rfClinic('CLINICA B');
    });

    it('cenário do revisor: botão responde B; "Não entendi, é amanhã?" não cancela A', function () {
        $mA = rfSend($this->sa, $this->a);
        $this->travel(1)->minutes();
        $mB = rfSend($this->sb, $this->b);

        rfPost($this, $this->global, rfButton("confirm:{$mB->id}", 'Confirmar'))->assertOk();
        rfPost($this, $this->global, rfText('Não entendi, é amanhã?'))->assertOk();

        expect($this->b->fresh()->situation)->toBe(ScheduleSituation::Confirmed)
            ->and($this->a->fresh()->situation)->toBe(ScheduleSituation::Scheduled)
            ->and($this->a->fresh()->cancellation_reason)->toBeNull()
            ->and($mA->fresh()->status)->toBe('sent');
    });

    it('"não" com uma única confirmação aguardando cancela essa consulta', function () {
        rfSend($this->sa, $this->a);

        rfPost($this, $this->global, rfText('Não!'))->assertOk();

        expect($this->a->fresh()->situation)->toBe(ScheduleSituation::Cancelled);
    });

    it('duas confirmações aguardando + "1": nenhuma muda e a resposta pede o botão', function () {
        rfSend($this->sa, $this->a);
        rfSend($this->sb, $this->b);

        rfPost($this, $this->global, rfText('1'))->assertOk();

        expect($this->a->fresh()->situation)->toBe(ScheduleSituation::Scheduled)
            ->and($this->b->fresh()->situation)->toBe(ScheduleSituation::Scheduled)
            ->and(rfLastAck())->toBe(__('whatsapp.patient.use_buttons'))
            // Sem candidata única, a entrada não é atribuída a nenhuma clínica.
            ->and(WhatsAppMessage::query()->where('direction', 'in')->value('entity_id'))->toBeNull();
    });

    it('texto que só COMEÇA com sim/cancel não vale; resposta exata com pontuação vale', function () {
        rfSend($this->sa, $this->a);

        rfPost($this, $this->global, rfText('Cancelaram a minha outra consulta?'))->assertOk();
        rfPost($this, $this->global, rfText('sim, mas posso chegar mais tarde?'))->assertOk();
        expect($this->a->fresh()->situation)->toBe(ScheduleSituation::Scheduled);

        rfPost($this, $this->global, rfText('  SIM. '))->assertOk();
        expect($this->a->fresh()->situation)->toBe(ScheduleSituation::Confirmed);
    });

    it('pesquisa: nota em texto só com uma única pesquisa aguardando', function () {
        $sA = rfSend($this->sa, $this->a, 'survey');
        $sB = rfSend($this->sb, $this->b, 'survey');

        rfPost($this, $this->global, rfText('5'))->assertOk();
        expect($sA->fresh()->survey_score)->toBeNull()
            ->and($sB->fresh()->survey_score)->toBeNull()
            ->and(rfLastAck())->toBe(__('whatsapp.patient.use_buttons'));

        rfPost($this, $this->global, rfButton("survey:{$sB->id}:2", '2 - Ruim'))->assertOk();
        expect($sB->fresh()->survey_score)->toBe(2);

        // Revisão final (achado 2): sobrou só A, mas a última coisa que o
        // paciente recebeu foi o agradecimento da pesquisa de B — o "4" pode
        // ser sobre B. Nada muda; o botão de A segue valendo.
        rfPost($this, $this->global, rfText('4'))->assertOk();
        expect($sA->fresh()->survey_score)->toBeNull()
            ->and(rfLastAck())->toBe(__('whatsapp.patient.use_buttons'));

        rfPost($this, $this->global, rfButton("survey:{$sA->id}:4", '4 - Bom'))->assertOk();
        expect($sA->fresh()->survey_score)->toBe(4);
    });

    it('confirmação e pesquisa aguardando ao mesmo tempo: "1" é ambíguo', function () {
        rfSend($this->sa, $this->a);
        $survey = rfSend($this->sb, $this->b, 'survey');

        rfPost($this, $this->global, rfText('1'))->assertOk();

        expect($this->a->fresh()->situation)->toBe(ScheduleSituation::Scheduled)
            ->and($survey->fresh()->survey_score)->toBeNull();
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// 2. [ALTA] Universal App Token: lock, reaproveitamento, revogação, 409
// ─────────────────────────────────────────────────────────────────────────────

describe('2. token universal (UAT) sem estourar o limite de 3 ativos', function () {
    beforeEach(function () {
        Http::preventStrayRequests();
        rfUniversalAuth();
        $this->app_setting = new WhatsAppSetting(['app_id' => 'app-rf', 'active' => true]);
    });

    function rfMints(): int
    {
        return Http::recorded(fn (HttpRequest $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/partner/app/app-rf/token'))->count();
    }

    function rfTemplate()
    {
        return app(WhatsAppTemplates::class)->build('connection_test', ['app' => 'EasyEye'], 'pt_BR');
    }

    it('concorrência: com outro worker gerando (lock), não gera outro token — erro transitório; depois reaproveita', function () {
        Http::fake(['partner.gupshup.io/partner/app/app-rf/token' => Http::response(['status' => 'success', 'token' => 'UAT-1', 'id' => 'tok-1'])]);

        $lock = Cache::lock(GupshupAuth::lockKey('app-rf'), 30);
        expect($lock->get())->toBeTrue();

        $busy = app(GupshupAuth::class)->authorization('app-rf');

        expect($busy['ok'])->toBeFalse()
            ->and($busy['retryable'])->toBeTrue()
            ->and(rfMints())->toBe(0);

        $lock->release();

        expect(app(GupshupAuth::class)->authorization('app-rf')['authorization'])->toBe('Bearer UAT-1')
            ->and(app(GupshupAuth::class)->authorization('app-rf')['authorization'])->toBe('Bearer UAT-1')
            ->and(rfMints())->toBe(1);
    });

    it('401: relê o cache (outro worker já renovou) antes de gerar; revoga o token que tomou 401', function () {
        Http::fake([
            'partner.gupshup.io/partner/app/app-rf/token/revoke' => Http::response(['status' => 'success', 'scope' => 'id']),
            'partner.gupshup.io/partner/app/app-rf/token'        => Http::sequence()
                ->push(['status' => 'success', 'token' => 'UAT-1', 'id' => 'tok-1'])
                ->push(['status' => 'success', 'token' => 'UAT-2', 'id' => 'tok-2']),
            'partner.gupshup.io/partner/app/app-rf/v3/message' => fn (HttpRequest $r) => $r->header('Authorization') === ['Bearer UAT-1']
                ? Http::response(['status' => 'error', 'message' => 'Authentication Failed'], 401)
                : Http::response(['messages' => [['id' => 'gs-ok']]]),
        ]);

        $result = app(WhatsAppProvider::class)->sendTemplate($this->app_setting, '5561999998888', rfTemplate());

        expect($result['ok'])->toBeTrue()->and(rfMints())->toBe(2);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/token/revoke') && $r['id'] === 'tok-1');

        // Um segundo worker que também usava o UAT-1 toma 401: usa o UAT-2 do cache.
        $again = app(GupshupAuth::class)->renew('app-rf', 'Bearer UAT-1');

        expect($again['authorization'])->toBe('Bearer UAT-2')
            ->and(rfMints())->toBe(2);
    });

    it('409 (3 ativos): revoga só os tokens órfãos do próprio sistema e gera de novo', function () {
        $prefix = GupshupAuth::tokenNamePrefix();

        Http::fake([
            'partner.gupshup.io/partner/app/app-rf/tokens*' => Http::response(['status' => 'success', 'tokens' => [
                ['id' => 'old-1', 'name' => $prefix . 'abc', 'status' => 'ACTIVE'],
                ['id' => 'other', 'name' => 'integracao-de-outro-sistema', 'status' => 'ACTIVE'],
            ]]),
            'partner.gupshup.io/partner/app/app-rf/token/revoke' => Http::response(['status' => 'success', 'scope' => 'id']),
            'partner.gupshup.io/partner/app/app-rf/token'        => Http::sequence()
                ->push(['status' => 'error', 'message' => 'Maximum of 3 active UAT tokens reached for this app; revoke one and retry'], 409)
                ->push(['status' => 'success', 'token' => 'UAT-9', 'id' => 'tok-9']),
        ]);

        $result = app(GupshupAuth::class)->authorization('app-rf');

        expect($result['authorization'])->toBe('Bearer UAT-9');
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/token/revoke') && $r['id'] === 'old-1');
        Http::assertNotSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/token/revoke') && $r['id'] === 'other');
    });

    it('409 persistente: transitório (a fila tenta de novo), sem laço, com alerta crítico ao admin', function () {
        NotificationFacade::fake();
        $admin    = rfSaasAdmin();
        $critical = rfCaptureCritical();

        Http::fake([
            'partner.gupshup.io/partner/app/app-rf/tokens*' => Http::response(['status' => 'success', 'tokens' => []]),
            'partner.gupshup.io/partner/app/app-rf/token'   => Http::response(['status' => 'error', 'message' => 'Maximum of 3 active UAT tokens reached for this app; revoke one and retry'], 409),
        ]);

        $result = app(WhatsAppProvider::class)->sendTemplate($this->app_setting, '5561999998888', rfTemplate());

        expect($result['ok'])->toBeFalse()
            ->and($result['error_code'])->toBe('uat_limit')
            ->and($result['retryable'])->toBeTrue()
            ->and(rfMints())->toBe(1)
            ->and(implode("\n", (array) $critical))->toContain('uat_limit');

        NotificationFacade::assertSentTo($admin, WhatsAppOperationalAlertNotification::class, fn ($n) => $n->type === 'uat_limit');
    });

    it('token universal recusado (401 ao gerar o UAT): transitório + alerta', function () {
        NotificationFacade::fake();
        $critical = rfCaptureCritical();

        Http::fake(['partner.gupshup.io/partner/app/app-rf/token' => Http::response(['status' => 'error', 'message' => 'Unauthorised access to the resource'], 401)]);

        $result = app(GupshupAuth::class)->authorization('app-rf');

        expect($result['error_code'])->toBe('universal_token_invalid')
            ->and($result['retryable'])->toBeTrue()
            ->and(implode("\n", (array) $critical))->toContain('universal_token_invalid');
    });

    it('GUPSHUP_APP_TOKEN_HOURS abaixo de 2 vira 2 (a Gupshup recusa expiry perto de agora+1h)', function () {
        config(['whatsapp.gupshup.app_token_hours' => 1]);
        Http::fake(['partner.gupshup.io/partner/app/app-rf/token' => Http::response(['status' => 'success', 'token' => 'UAT-1', 'id' => 'tok-1'])]);

        app(GupshupAuth::class)->authorization('app-rf');

        [$request] = Http::recorded(fn (HttpRequest $r) => str_ends_with($r->url(), '/token'))->first();
        expect((int) $request['expiry'])->toBeGreaterThanOrEqual(now()->addHours(2)->subMinute()->getTimestampMs());
    });

    it('manager avisa quando o token universal está perto de vencer (config ou "exp" do JWT)', function () {
        $saas = Entity::factory()->create(['is_client' => false, 'active' => true]);
        $user = User::factory()->create();
        createEntityUser($saas, $user, SaasRule::Admin->value);
        $this->actingAs($user);
        session(['selected_entity_id' => $saas->id]);

        config(['whatsapp.gupshup.universal_token_expires_at' => now()->addDays(5)->toDateString()]);
        $props = $this->get(route('manager.whatsapp.index'))->viewData('page')['props'];

        expect($props['partner']['ut_expires_at'])->toBe(now()->addDays(5)->toDateString())
            ->and($props['partner']['ut_expiring'])->toBeTrue()
            ->and($props['partner']['ut_expired'])->toBeFalse();

        // Sem a data no .env: lê o "exp" do próprio token (JWT).
        $jwt = 'eyJhbGciOiJSUzI1NiJ9.' . rtrim(strtr(base64_encode(json_encode(['exp' => now()->addDays(40)->timestamp])), '+/', '-_'), '=') . '.assinatura';
        config(['whatsapp.gupshup.universal_token_expires_at' => null, 'whatsapp.gupshup.universal_token' => $jwt]);
        $props = $this->get(route('manager.whatsapp.index'))->viewData('page')['props'];

        expect($props['partner']['ut_expires_at'])->toBe(now()->addDays(40)->toDateString())
            ->and($props['partner']['ut_expiring'])->toBeFalse()
            ->and(json_encode($props))->not->toContain($jwt);
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// 3. [MÉDIA] Consulta excluída/inativa não é confirmada pelo WhatsApp
// ─────────────────────────────────────────────────────────────────────────────

describe('3. consulta excluída (soft delete) ou inativa', function () {
    beforeEach(function () {
        [, $this->setting, $this->schedule] = rfClinic('CLINICA PROPRIA', appId: 'own-app-rf');
    });

    it('excluída: o botão não confirma nem responde "confirmado"', function () {
        $out = rfSend($this->setting, $this->schedule);
        $this->schedule->delete();

        rfPost($this, $this->setting, rfButton("confirm:{$out->id}", 'Confirmar'))->assertOk();

        expect(Schedule::withTrashed()->find($this->schedule->id)->situation)->toBe(ScheduleSituation::Scheduled)
            ->and(rfLastAck())->not->toContain('confirmada');
    });

    it('inativa (active = false): também não muda', function () {
        $out = rfSend($this->setting, $this->schedule);
        $this->schedule->updateQuietly(['active' => false]);

        rfPost($this, $this->setting, rfButton("cancel:{$out->id}", 'Cancelar'))->assertOk();

        expect($this->schedule->fresh()->situation)->toBe(ScheduleSituation::Scheduled);
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// 4. [MÉDIA] Código do cadastro: HMAC e fora da auditoria
// ─────────────────────────────────────────────────────────────────────────────

describe('4. código de verificação fora do audit_logs', function () {
    it('grava HMAC (APP_KEY) do código e nada do código vai para audit_logs', function () {
        Queue::fake();
        rfGlobal();
        $user = User::factory()->create(['phone' => '11988887777']);

        app(PhoneVerificationService::class)->sendCode($user);

        $code = null;
        Queue::assertPushed(SendPhoneVerificationCodeJob::class, function ($job) use (&$code) {
            $code = $job->code;

            return true;
        });

        $stored = $user->fresh()->phone_verification_code;
        expect($stored)->toBe(hash_hmac('sha256', $code, (string) config('app.key')))
            ->and($stored)->not->toBe(hash('sha256', $code));

        $audit = DB::table('audit_logs')->where('auditable_type', User::class)->where('auditable_id', $user->id)->get();
        expect($audit->pluck('new_values')->implode(' '))->not->toContain('phone_verification_code')
            ->and($audit->pluck('new_values')->implode(' '))->not->toContain($stored)
            ->and($audit->pluck('old_values')->implode(' '))->not->toContain('phone_verification_code');

        expect(app(PhoneVerificationService::class)->verify($user, $code))->toBeTrue();
    });

    it('código pendente do formato antigo (sha256, gerado antes do deploy) ainda vale até expirar', function () {
        $user = User::factory()->create([
            'phone'                         => '11988887777',
            'phone_verification_code'       => hash('sha256', '246810'),
            'phone_verification_expires_at' => now()->addMinutes(5),
        ]);

        expect(app(PhoneVerificationService::class)->verify($user, '246810'))->toBeTrue();
    });

    it('migration limpa o hash já gravado em audit_logs (só do User, só essas chaves)', function () {
        $userRow  = (string) Str::uuid();
        $otherRow = (string) Str::uuid();

        DB::table('audit_logs')->insert([
            ['id'            => $userRow, 'auditable_type' => User::class, 'auditable_id' => (string) Str::uuid(), 'event' => 'updated', 'created_at' => now(),
                'old_values' => json_encode(['phone_verification_code' => null, 'name' => 'A']),
                'new_values' => json_encode(['phone_verification_code' => str_repeat('a', 64), 'phone_verification_attempts' => 0, 'phone_verification_expires_at' => '2026-10-05', 'name' => 'B'])],
            ['id'            => $otherRow, 'auditable_type' => Entity::class, 'auditable_id' => (string) Str::uuid(), 'event' => 'updated', 'created_at' => now(),
                'old_values' => null,
                'new_values' => json_encode(['phone_verification_code' => 'nao-e-do-user'])],
        ]);

        (require database_path('migrations/2026_10_08_000100_scrub_phone_verification_from_audit_logs.php'))->up();

        $new = json_decode(DB::table('audit_logs')->where('id', $userRow)->value('new_values'), true);
        $old = json_decode(DB::table('audit_logs')->where('id', $userRow)->value('old_values'), true);

        expect($new)->toBe(['name' => 'B'])
            ->and($old)->toBe(['name' => 'A'])
            ->and(DB::table('audit_logs')->where('id', $otherRow)->value('new_values'))->toContain('nao-e-do-user');
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// 5. [MÉDIA] Cadastro não fica preso quando o WhatsApp não entrega
// ─────────────────────────────────────────────────────────────────────────────

describe('5. gate da verificação do WhatsApp', function () {
    function rfGate(User $user): int
    {
        $request = Request::create('/panel', 'GET');
        $request->setUserResolver(fn () => $user);

        return app(EnsurePhoneVerified::class)->handle($request, fn () => response('painel'))->getStatusCode();
    }

    beforeEach(function () {
        $this->global = rfGlobal(['app_id' => 'global-otp-rf']);
        $this->user   = User::factory()->create(['phone' => '11988887777']);
    });

    it('driver gupshup sem credenciais do parceiro: gate libera e o código nem é gerado', function () {
        useGupshupDriver();
        config(['whatsapp.gupshup.partner_email' => null, 'whatsapp.gupshup.partner_secret' => null]);
        Queue::fake();

        expect(rfGate($this->user))->toBe(200)
            ->and(app(PhoneVerificationService::class)->sendCode($this->user))->toBeFalse();
        Queue::assertNotPushed(SendPhoneVerificationCodeJob::class);
    });

    it('último envio do código falhou por configuração (template reprovado): gate libera + alerta', function () {
        useGupshupDriver();
        NotificationFacade::fake();
        $critical = rfCaptureCritical();
        $admin    = rfSaasAdmin();

        expect(rfGate($this->user))->toBe(302);

        WhatsAppMessage::create([
            'whatsapp_setting_id' => $this->global->id, 'direction' => 'out', 'kind' => WhatsAppMessage::KIND_VERIFICATION,
            'phone'               => '5511988887777', 'body' => 'código ******', 'status' => 'failed', 'error_code' => 'meta_132001',
            'failed_at'           => now(), 'payload' => ['user_id' => (string) $this->user->id],
        ]);

        expect(rfGate($this->user))->toBe(200)
            ->and(implode("\n", (array) $critical))->toContain('verification_undeliverable');
        NotificationFacade::assertSentTo($admin, WhatsAppOperationalAlertNotification::class);
    });

    it('falha do lado do destinatário (número sem WhatsApp) NÃO libera — o usuário corrige o número', function () {
        useGupshupDriver();

        WhatsAppMessage::create([
            'whatsapp_setting_id' => $this->global->id, 'direction' => 'out', 'kind' => WhatsAppMessage::KIND_VERIFICATION,
            'phone'               => '5511988887777', 'body' => 'código ******', 'status' => 'failed', 'error_code' => 'gupshup_1002',
            'failed_at'           => now(), 'payload' => ['user_id' => (string) $this->user->id],
        ]);

        expect(rfGate($this->user))->toBe(302);
    });

    it('driver mock em produção não prende ninguém (nada sai); em dev/teste segue exigindo', function () {
        expect(rfGate($this->user))->toBe(302);

        $env         = app()->environment();
        app()['env'] = 'production';

        try {
            expect(rfGate($this->user))->toBe(200)
                ->and(app(PhoneVerificationService::class)->sendCode($this->user))->toBeFalse();
        } finally {
            app()['env'] = $env;
        }
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// 6. [MÉDIA] Sem envio em duplicidade (timeout / 2xx sem id)
// ─────────────────────────────────────────────────────────────────────────────

describe('6. envio em duplicidade', function () {
    beforeEach(function () {
        Http::preventStrayRequests();
        useGupshupDriver();
        [$this->entity, $this->setting, $this->schedule] = rfClinic('CLINICA DUP', appId: 'app-dup');
        $this->message                                   = app(WhatsAppService::class)->queueConfirmation($this->setting, $this->schedule->fresh());
    });

    function rfRunJob(string $id): void
    {
        app()->call([(new SendWhatsAppMessageJob($id))->withFakeQueueInteractions(), 'handle']);
    }

    it('grava "sending" ANTES de chamar a Gupshup; job que encontra "sending" não reenvia', function () {
        $statusDuringCall = null;
        fakeGupshup(['partner.gupshup.io/partner/app/app-dup/v3/message' => function () use (&$statusDuringCall) {
            $statusDuringCall = WhatsAppMessage::query()->whereKey($this->message->id)->value('status');

            return Http::response(['messages' => [['id' => 'gs-dup-1']]]);
        }]);

        rfRunJob((string) $this->message->id);

        expect($statusDuringCall)->toBe(WhatsAppMessage::STATUS_SENDING)
            ->and($this->message->fresh()->status)->toBe('sent');

        // Worker morreu no meio de uma chamada (linha ficou "sending").
        $this->message->update(['status' => WhatsAppMessage::STATUS_SENDING, 'provider_message_id' => null]);
        rfRunJob((string) $this->message->id);

        expect($this->message->fresh()->status)->toBe('failed')
            ->and($this->message->fresh()->error_code)->toBe('unknown_delivery')
            ->and(gupshupSentMessages())->toHaveCount(1);
    });

    it('timeout de LEITURA: não retenta (pode ter saído) — failed unknown_delivery', function () {
        fakeGupshup(['partner.gupshup.io/partner/app/app-dup/v3/message' => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 15001 milliseconds with 0 bytes received')]);

        rfRunJob((string) $this->message->id);

        expect($this->message->fresh()->status)->toBe('failed')
            ->and($this->message->fresh()->error_code)->toBe('unknown_delivery');
    });

    it('falha de CONEXÃO antes de enviar (connect/DNS): volta para pending e a fila tenta de novo', function () {
        fakeGupshup(['partner.gupshup.io/partner/app/app-dup/v3/message' => fn () => throw new ConnectionException('cURL error 7: Failed to connect to partner.gupshup.io port 443 after 5001 ms')]);

        expect(fn () => rfRunJob((string) $this->message->id))->toThrow(RuntimeException::class);

        expect($this->message->fresh()->status)->toBe('pending')
            ->and($this->message->fresh()->error_code)->toBe('connection');
    });

    it('2xx sem id: enviada (sem nova tentativa), marcada com no_message_id', function () {
        fakeGupshup(['partner.gupshup.io/partner/app/app-dup/v3/message' => Http::response(['messaging_product' => 'whatsapp'])]);

        $result = app(WhatsAppProvider::class)->sendSessionText($this->setting, '5561999998888', 'oi');
        expect($result['ok'])->toBeTrue()
            ->and($result['message_id'])->toBeNull()
            ->and($result['error_code'])->toBe('no_message_id');

        rfRunJob((string) $this->message->id);

        expect($this->message->fresh()->status)->toBe('sent')
            ->and($this->message->fresh()->error_code)->toBe('no_message_id')
            ->and($this->message->fresh()->provider_message_id)->toBeNull();
    });

    it('comando de confirmações considera "sending" como já existente', function () {
        Queue::fake();
        $this->message->update(['status' => WhatsAppMessage::STATUS_SENDING]);

        $this->artisan('whatsapp:send-confirmations')->assertSuccessful();

        expect(WhatsAppMessage::query()->where('kind', 'confirmation')->count())->toBe(1);
        Queue::assertNothingPushed();
    });

    it('aviso do SaaS: timeout de leitura não reenvia na nova tentativa do mesmo job', function () {
        $this->travelTo(now()->setTime(10, 0));
        $global = rfGlobal(['app_id' => 'global-dup']);
        $user   = User::factory()->create(['phone' => '11988887777', 'phone_verified_at' => now()]);
        $notice = new class() extends Notification implements SendsSaasWhatsApp {
            public function toSaasWhatsApp(object $notifiable): ?array
            {
                return ['template' => 'saas_trial_today', 'values' => ['entity' => 'Clínica', 'date' => '10/10/2026'], 'url' => url('/panel/my-subscription')];
            }
        };

        $calls = 0;
        fakeGupshup(['partner.gupshup.io/partner/app/global-dup/v3/message' => function () use (&$calls) {
            $calls++;

            throw new ConnectionException('cURL error 28: Operation timed out after 15001 milliseconds with 0 bytes received');
        }]);

        $job = (new SendSaasWhatsAppNoticeJob((string) $user->id, $notice, 'pt_BR'))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);
        $job->assertNotReleased();
        app()->call([$job, 'handle']); // reentrega do mesmo job

        $rows = WhatsAppMessage::query()->where('kind', WhatsAppMessage::KIND_SAAS_NOTICE)->get();
        expect($calls)->toBe(1)
            ->and($rows)->toHaveCount(1)
            ->and($rows->first()->status)->toBe('failed')
            ->and($rows->first()->error_code)->toBe('unknown_delivery');
    });

    it('código do cadastro: nova tentativa do mesmo job não duplica se já há id', function () {
        rfGlobal(['app_id' => 'global-otp-dup']);
        $user = User::factory()->create(['phone' => '11988887777']);
        fakeGupshup(['partner.gupshup.io/partner/app/global-otp-dup/v3/message' => Http::response(['messages' => [['id' => 'gs-otp']]])]);

        $job = (new SendPhoneVerificationCodeJob((string) $user->id, '5511988887777', '123456'))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);

        expect(gupshupSentMessages())->toHaveCount(1)
            ->and(WhatsAppMessage::query()->where('kind', 'verification')->count())->toBe(1);
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// 7. [MÉDIA/BAIXA] Pendentes da Z-API no deploy
// ─────────────────────────────────────────────────────────────────────────────

describe('7. pendentes antigas da Z-API', function () {
    it('migration: saída pending vira skipped e o comando reaproveita com template', function () {
        Queue::fake();
        [, , $schedule] = rfClinic('CLINICA LEGADA');

        $migration = require database_path('migrations/2026_10_08_000000_migrate_whatsapp_to_gupshup.php');
        $migration->down();

        $id = (string) Str::uuid();
        DB::table('whatsapp_messages')->insert([
            'id'         => $id, 'entity_id' => $schedule->entity_id, 'schedule_id' => $schedule->id, 'direction' => 'out', 'kind' => 'confirmation',
            'phone'      => '5561999998888', 'body' => 'texto antigo da Z-API', 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration->up();
        rfGlobal(); // app global cadastrado no manager depois do deploy

        expect(DB::table('whatsapp_messages')->where('id', $id)->value('status'))->toBe('skipped')
            ->and(DB::table('whatsapp_messages')->where('id', $id)->value('error_code'))->toBe('zapi_legacy');

        $this->artisan('whatsapp:send-confirmations')->assertSuccessful();

        $message = WhatsAppMessage::find($id);
        expect($message->status)->toBe('pending')
            ->and($message->payload['template_key'])->toBe('appointment_confirmation');
        Queue::assertPushed(SendWhatsAppMessageJob::class, 1);
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// 8. [BAIXA] Descadastrado não recebe resposta automática; export LGPD; painel
// ─────────────────────────────────────────────────────────────────────────────

describe('8. descadastro (SAIR) respeitado nas respostas e visível', function () {
    beforeEach(function () {
        [$this->entity, $this->setting, $this->schedule] = rfClinic('CLINICA OPTOUT', appId: 'own-optout');
    });

    it('depois do SAIR, só VOLTAR (e o próprio SAIR) recebem resposta', function () {
        $out = rfSend($this->setting, $this->schedule);

        rfPost($this, $this->setting, rfText('SAIR'))->assertOk();
        expect(WhatsAppMessage::query()->where('kind', 'ack')->count())->toBe(1);

        rfPost($this, $this->setting, rfText('ok, obrigado'))->assertOk();
        rfPost($this, $this->setting, rfButton("confirm:{$out->id}", 'Confirmar'))->assertOk();
        expect(WhatsAppMessage::query()->where('kind', 'ack')->count())->toBe(1);

        rfPost($this, $this->setting, rfText('VOLTAR'))->assertOk();
        expect(WhatsAppMessage::query()->where('kind', 'ack')->count())->toBe(2)
            ->and(rfLastAck())->toBe(__('whatsapp.patient.opted_in'));
    });

    it('export LGPD do paciente traz o descadastro', function () {
        $person  = People::factory()->create(['cellphone' => '61999998888', 'whatsapp' => true]);
        $patient = Patient::factory()->create(['entity_id' => $this->entity->id, 'person_id' => $person->id]);
        $this->schedule->updateQuietly(['patient_id' => $patient->id]);

        app(WhatsAppService::class)->optOut($this->setting, '5561999998888');

        $export = app(PatientDataExporter::class)->export($patient);

        expect($export['whatsapp_opt_outs'])->toHaveCount(1)
            ->and($export['whatsapp_opt_outs'][0]['phone'])->toBe('5561999998888')
            ->and($export['whatsapp_opt_outs'][0]['sender'])->toBe('clinic')
            ->and($export['whatsapp_opt_outs'][0]['source'])->toBe('keyword');
    });

    it('agenda (detalhes da consulta) indica "descadastrado do WhatsApp"', function () {
        $plan = Plan::factory()->create(['active' => true]);
        PlanFeature::create(['plan_id' => $plan->id, 'feature' => FeatureKey::MaxPatients->value, 'value' => '0']);
        Subscription::factory()->create([
            'entity_id' => $this->entity->id, 'plan_id' => $plan->id, 'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
        ]);
        $eu = createEntityUser($this->entity, User::factory()->create(), ClientRule::Admin->value);

        $show = fn () => $this->actingAs($eu->user)->withSession(panelSession($eu))
            ->getJson(route('panel.schedules.show', $this->schedule->id))->assertOk();

        expect($show()->json('data.whatsapp_opted_out'))->toBeFalse();

        app(WhatsAppService::class)->optOut($this->setting, '5561999998888');

        expect($show()->json('data.whatsapp_opted_out'))->toBeTrue();
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// 9. [BAIXA] Códigos de erro da Gupshup
// ─────────────────────────────────────────────────────────────────────────────

describe('9. erros 4001/4002 e saldo (1003)', function () {
    beforeEach(function () {
        Http::preventStrayRequests();
        useGupshupDriver();
        $this->app_setting = new WhatsAppSetting(['app_id' => 'app-err', 'active' => true]);
    });

    it('4001 (rate limit da Gupshup) e 4002 (resposta inválida do WhatsApp) são transitórios mesmo sem HTTP 429/5xx', function (int $code) {
        fakeGupshup(['partner.gupshup.io/partner/app/app-err/v3/message' => Http::response(['code' => $code, 'reason' => 'x'], 400)]);

        $result = app(WhatsAppProvider::class)->sendSessionText($this->app_setting, '5561999998888', 'oi');

        expect($result['error_code'])->toBe('gupshup_' . $code)
            ->and($result['retryable'])->toBeTrue();
    })->with([4001, 4002]);

    it('1003 (saldo): transitório + alerta crítico; esgotadas as tentativas a mensagem não se perde (skipped, volta pelo comando)', function () {
        NotificationFacade::fake();
        $critical               = rfCaptureCritical();
        [, $setting, $schedule] = rfClinic('CLINICA SALDO', appId: 'app-err');
        $message                = app(WhatsAppService::class)->queueConfirmation($setting, $schedule->fresh());

        fakeGupshup(['partner.gupshup.io/partner/app/app-err/v3/message' => Http::response(['code' => 1003, 'reason' => 'Wallet Balance Low'], 400)]);

        $job = new SendWhatsAppMessageJob((string) $message->id);
        expect(fn () => app()->call([$job, 'handle']))->toThrow(RuntimeException::class);
        $job->failed(new RuntimeException('WhatsApp send failed: gupshup_1003'));

        expect($message->fresh()->status)->toBe('skipped')
            ->and($message->fresh()->error_code)->toBe('gupshup_1003')
            ->and(implode("\n", (array) $critical))->toContain('wallet_low');

        Queue::fake();
        $this->artisan('whatsapp:send-confirmations')->assertSuccessful();
        expect($message->fresh()->status)->toBe('pending');
    });

    it('1003 no status assíncrono: a confirmação volta a ser reaproveitável (skipped)', function () {
        [, $setting, $schedule] = rfClinic('CLINICA SALDO 2', appId: 'own-saldo');
        config(['whatsapp.driver' => 'mock']);
        $out = rfSend($setting, $schedule);

        (new ProcessWhatsAppStatusJob((string) $setting->id, ['gs_id' => $out->provider_message_id, 'status' => 'failed', 'code' => 1003, 'reason' => 'Wallet Balance Low']))
            ->withFakeQueueInteractions()
            ->handle(app(WhatsAppService::class));

        expect($out->fresh()->status)->toBe('skipped')
            ->and($out->fresh()->error_code)->toBe('gupshup_1003');
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// 10. [BAIXA] Limite do webhook por token, não por IP
// ─────────────────────────────────────────────────────────────────────────────

describe('10. throttle do webhook', function () {
    it('limite por token da URL: trocar o IP (X-Forwarded-For) não escapa; outro token não é afetado', function () {
        config(['whatsapp.webhook.rate_limit_per_minute' => 2]);
        [, $a] = rfClinic('CLINICA TA', appId: 'app-ta');
        [, $b] = rfClinic('CLINICA TB', '61988887777', appId: 'app-tb');

        $post = fn (WhatsAppSetting $s, string $ip) => $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withHeaders([WhatsAppSetting::WEBHOOK_SECRET_HEADER => (string) $s->webhook_secret, 'X-Forwarded-For' => $ip])
            ->postJson('/api/whatsapp/gupshup/webhook/' . $s->webhook_token, ['gs_app_id' => $s->app_id, 'entry' => []]);

        $post($a, '10.0.0.1')->assertOk();
        $post($a, '10.0.0.2')->assertOk();
        $post($a, '10.0.0.3')->assertStatus(429);

        $post($b, '10.0.0.3')->assertOk();

        // Token desconhecido continua 404 genérico.
        $this->postJson('/api/whatsapp/gupshup/webhook/nao-existe', [])->assertNotFound();
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// 11. [BAIXA] Restos e detalhes
// ─────────────────────────────────────────────────────────────────────────────

describe('11. restos da Z-API e detalhes', function () {
    it('textos de validação da Z-API removidos (pt_BR e en)', function () {
        foreach (['pt_BR', 'en'] as $locale) {
            expect(__('validation.attributes.client_token', [], $locale))->toBe('validation.attributes.client_token')
                ->and(__('validation.attributes.instance_token', [], $locale))->toBe('validation.attributes.instance_token');
        }
    });

    it('[SEGURANÇA] teste do manager em entity que não é clínica → 404', function () {
        $saas = Entity::factory()->create(['is_client' => false, 'active' => true]);
        $user = User::factory()->create();
        createEntityUser($saas, $user, SaasRule::Admin->value);
        $this->actingAs($user);
        session(['selected_entity_id' => $saas->id]);
        rfGlobal();

        $request = Request::create('/panel/manager/whatsapp/x/test', 'POST', []);
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(fn () => auth()->user());

        expect(fn () => app(WhatsAppController::class)->test($request, $saas))->toThrow(NotFoundHttpException::class);
    });

    it('status de mensagem desconhecida é descartado (não volta para a fila)', function () {
        [, $setting] = rfClinic('CLINICA STATUS', appId: 'own-status');

        $job = (new ProcessWhatsAppStatusJob((string) $setting->id, ['gs_id' => 'de-outro-sistema', 'status' => 'delivered']))->withFakeQueueInteractions();
        $job->handle(app(WhatsAppService::class));

        $job->assertNotReleased();
    });

    it('mensagem recebida sem id é deduplicada pelo conteúdo (reentrega não duplica)', function () {
        [, $setting] = rfClinic('CLINICA SEM ID', appId: 'own-noid');
        $message     = ['from' => '5561999998888', 'timestamp' => '1759670000', 'type' => 'text', 'text' => ['body' => 'Olá']];

        rfPost($this, $setting, $message)->assertOk();
        rfPost($this, $setting, $message)->assertOk();

        expect(WhatsAppMessage::query()->where('direction', 'in')->count())->toBe(1)
            ->and(WhatsAppMessage::query()->where('direction', 'in')->value('provider_message_id'))->toStartWith('noid:');
    });
});
