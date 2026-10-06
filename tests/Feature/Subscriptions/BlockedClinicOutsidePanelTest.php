<?php

declare(strict_types=1);

use App\Enums\{BillingCycle, FeatureKey, ScheduleSituation, SubscriptionAccessLevel, SubscriptionStatus};
use App\Jobs\WhatsApp\{ProcessWhatsAppInboundJob, SendWhatsAppMessageJob};
use App\Models\{Entity, EntityIntegrator, EntityUserIntegrator, Patient, PatientAccount, PatientCall, People, Plan, PlanFeature, Schedule, Subscription};
use App\Models\WhatsApp\{WhatsAppMessage, WhatsAppSetting};
use App\Services\Billing\ClinicServiceGate;
use App\Services\WhatsApp\Contracts\WhatsAppProvider;
use App\Services\WhatsApp\{WhatsAppService, WhatsAppTemplates};
use Carbon\{Carbon, CarbonImmutable};
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\{Cache, Http, Queue, Route};

/**
 * Clínica com o acesso BLOQUEADO (accessLevel none) fora do painel:
 * WhatsApp automático parado (com o motivo; volta sozinho), TV de chamada
 * "indisponível" sem dados de paciente, portal do paciente só leitura e a
 * API de integradores recusando. Acesso limitado segue normal; a chave
 * BILLING_ENFORCE_SUBSCRIPTION_ACCESS desligada não bloqueia nada.
 */
beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-05 10:00:00'));
    Http::preventStrayRequests();
    config(['billing.enforce_subscription_access' => true, 'whatsapp.driver' => 'mock']);

    $this->plan = Plan::factory()->create(['active' => true]);

    $this->clinic                = Entity::factory()->make(['is_client' => true, 'active' => true, 'name' => 'Clínica Visão']);
    $this->clinic->skipAutoTrial = true;
    $this->clinic->save();
});

afterEach(fn () => Carbon::setTestNow());

/** full | limited | none */
function blockedClinicAccess(string $level): Subscription
{
    $base = ['plan_id' => test()->plan->id, 'entity_id' => test()->clinic->id];

    $subscription = match ($level) {
        'full' => Subscription::factory()->create([...$base, 'status' => SubscriptionStatus::Active, 'starts_at' => now()->subMonth(), 'ends_at' => now()->addMonth()]),
        // Cliente pagante em atraso há 5 dias (régua: acesso limitado).
        'limited' => Subscription::factory()->gateway('mercadopago')->create([...$base,
            'billing_cycle'   => BillingCycle::Monthly,
            'status'          => SubscriptionStatus::PastDue,
            'last_payment_at' => '2026-09-01 10:00:00',
            'ends_at'         => '2026-09-30 23:59:59',
            'past_due_at'     => '2026-09-30 23:59:59',
        ]),
        // Plano vencido, sem renovação: sem acesso.
        'none' => Subscription::factory()->create([...$base, 'status' => SubscriptionStatus::Active, 'starts_at' => now()->subMonths(2), 'ends_at' => now()->subDay()]),
    };

    expect($subscription->fresh()->accessLevel()->value)->toBe($level);

    return $subscription;
}

function blockedClinicWhatsApp(): Schedule
{
    test()->wppSetting = WhatsAppSetting::create([
        'entity_id'                 => test()->clinic->id,
        'app_id'                    => 'clinic-own-app',
        'webhook_token'             => str_repeat('w', 48),
        'active'                    => true,
        'confirmation_enabled'      => true,
        'confirmation_hours_before' => 24,
        'survey_enabled'            => true,
        'survey_delay_hours'        => 0,
    ]);

    return Schedule::query()->create([
        'entity_id'          => test()->clinic->id,
        'doctor_id'          => createDoctorForEntity(test()->clinic)->id,
        'full_name'          => 'MARIA DA SILVA',
        'cellphone'          => '61999998888',
        'cellphone_whatsapp' => true,
        'date_time'          => now()->addHours(5),
        'situation'          => ScheduleSituation::Scheduled->value,
        'active'             => true,
    ]);
}

describe('ClinicServiceGate', function () {
    it('bloqueia só no acesso none; limitado e cliente sem bloqueio seguem; chave desligada não bloqueia', function () {
        $none = blockedClinicAccess('none');

        expect(app(ClinicServiceGate::class)->isBlocked($this->clinic))->toBeTrue()
            ->and(app(ClinicServiceGate::class)->allowsAutomation((string) $this->clinic->id))->toBeFalse();

        config(['billing.enforce_subscription_access' => false]);
        expect(app(ClinicServiceGate::class)->isBlocked($this->clinic))->toBeFalse();
        config(['billing.enforce_subscription_access' => true]);

        $none->delete();
        blockedClinicAccess('limited');
        expect(app(ClinicServiceGate::class)->isBlocked($this->clinic))->toBeFalse();

        $saas                = Entity::factory()->make(['is_client' => false]);
        $saas->skipAutoTrial = true;
        $saas->save();
        expect(app(ClinicServiceGate::class)->isBlocked($saas))->toBeFalse();
    });
});

describe('WhatsApp automático', function () {
    it('confirmação: clínica bloqueada é pulada (sem mensagem nem job) e volta sozinha com o acesso', function () {
        Queue::fake();
        $none     = blockedClinicAccess('none');
        $schedule = blockedClinicWhatsApp();

        $this->artisan('whatsapp:send-confirmations')
            ->expectsOutputToContain('acesso bloqueado')
            ->assertSuccessful();

        expect(WhatsAppMessage::query()->count())->toBe(0);
        Queue::assertNothingPushed();

        // Pagou: o acesso volta e o próximo ciclo do agendador envia.
        $none->update(['ends_at' => now()->addMonth()]);

        $this->artisan('whatsapp:send-confirmations')->assertSuccessful();

        expect(WhatsAppMessage::query()->where('schedule_id', $schedule->id)->value('status'))->toBe(WhatsAppMessage::STATUS_PENDING);
        Queue::assertPushed(SendWhatsAppMessageJob::class, 1);
    });

    it('acesso limitado (régua) continua enviando — a agenda segue', function () {
        Queue::fake();
        blockedClinicAccess('limited');
        blockedClinicWhatsApp();

        $this->artisan('whatsapp:send-confirmations')->assertSuccessful();

        expect(WhatsAppMessage::query()->where('kind', 'confirmation')->count())->toBe(1);
        Queue::assertPushed(SendWhatsAppMessageJob::class, 1);
    });

    it('mensagem já na fila quando o acesso foi bloqueado: pulada com o motivo, sem enviar nem re-tentar; reenfileirada quando o acesso volta', function () {
        Queue::fake();
        $sub      = blockedClinicAccess('full');
        $schedule = blockedClinicWhatsApp();

        $this->artisan('whatsapp:send-confirmations')->assertSuccessful();
        $message = WhatsAppMessage::query()->where('schedule_id', $schedule->id)->firstOrFail();

        // Bloqueou antes de o worker pegar a mensagem.
        $sub->update(['ends_at' => now()->subMinute()]);

        $provider = Mockery::mock(WhatsAppProvider::class);
        $provider->shouldNotReceive('sendTemplate');

        (new SendWhatsAppMessageJob((string) $message->id))->handle($provider, app(WhatsAppService::class), app(WhatsAppTemplates::class), app(ClinicServiceGate::class));

        $message->refresh();
        expect($message->status)->toBe(WhatsAppMessage::STATUS_SKIPPED)
            ->and($message->error)->toContain(ClinicServiceGate::REASON_ACCESS_BLOCKED);

        // O worker terminou o job (a trava de job único sai com ele).
        (new UniqueLock(Cache::store()))->release(new SendWhatsAppMessageJob((string) $message->id));

        // Pagou: o comando reaproveita a mesma linha (sem duplicar).
        $sub->update(['ends_at' => now()->addMonth()]);
        $this->artisan('whatsapp:send-confirmations')->assertSuccessful();

        expect(WhatsAppMessage::query()->where('schedule_id', $schedule->id)->count())->toBe(1)
            ->and($message->fresh()->status)->toBe(WhatsAppMessage::STATUS_PENDING)
            ->and($message->fresh()->error)->toBeNull();
        Queue::assertPushed(SendWhatsAppMessageJob::class, 2);
    });

    it('pesquisa de satisfação: clínica bloqueada é pulada', function () {
        Queue::fake();
        blockedClinicAccess('none');
        $schedule = blockedClinicWhatsApp();
        $schedule->update(['situation' => ScheduleSituation::Attended->value, 'date_time' => now()->subHours(2)]);

        $this->artisan('whatsapp:send-surveys')->expectsOutputToContain('acesso bloqueado')->assertSuccessful();

        expect(WhatsAppMessage::query()->count())->toBe(0);
        Queue::assertNothingPushed();
    });

    it('resposta do paciente: o efeito vale, mas a resposta automática não sai (registro com o motivo)', function () {
        $sub      = blockedClinicAccess('full');
        $schedule = blockedClinicWhatsApp();

        $out = WhatsAppMessage::create([
            'entity_id' => $this->clinic->id, 'schedule_id' => $schedule->id, 'whatsapp_setting_id' => $this->wppSetting->id, 'direction' => 'out', 'kind' => 'confirmation',
            'phone'     => '5561999998888', 'body' => 'Confirma?', 'status' => WhatsAppMessage::STATUS_SENT, 'sent_at' => now()->subHour(),
        ]);
        $in = WhatsAppMessage::create([
            'entity_id' => $this->clinic->id, 'whatsapp_setting_id' => $this->wppSetting->id, 'direction' => 'in', 'kind' => 'reply', 'phone' => '5561999998888', 'body' => '1', 'status' => WhatsAppMessage::STATUS_RECEIVED,
        ]);

        $sub->update(['ends_at' => now()->subMinute()]);

        $provider = Mockery::mock(WhatsAppProvider::class);
        $provider->shouldNotReceive('sendSessionText');
        app()->instance(WhatsAppProvider::class, $provider);

        app()->call([new ProcessWhatsAppInboundJob((string) $in->id), 'handle']);

        $ack = WhatsAppMessage::query()->where('kind', WhatsAppMessage::KIND_ACK)->first();

        expect($schedule->fresh()->situation)->toBe(ScheduleSituation::Confirmed)
            ->and($ack?->status)->toBe(WhatsAppMessage::STATUS_SKIPPED)
            ->and($ack?->error)->toContain(ClinicServiceGate::REASON_ACCESS_BLOCKED)
            ->and($out->id)->not->toBeNull();
    });
});

describe('painel de chamada da TV', function () {
    beforeEach(function () {
        $this->clinic->forceFill(['call_panel_enabled' => true, 'call_panel_token' => str_repeat('t', 48)])->save();
        PatientCall::create(['entity_id' => $this->clinic->id, 'patient_name' => 'JOAO DA TV', 'doctor_name' => 'DRA ANA', 'created_at' => now()]);
    });

    it('bloqueada: "serviço indisponível" e o feed não devolve chamadas', function () {
        blockedClinicAccess('none');

        $this->get(route('call-panel.show', str_repeat('t', 48)), inertiaHeaders())
            ->assertOk()
            ->assertJsonPath('props.unavailable', true)
            ->assertJsonPath('props.texts.unavailable_title', __('call_panel.unavailable_title'));

        $this->getJson(route('call-panel.feed', str_repeat('t', 48)))
            ->assertOk()
            ->assertJsonPath('unavailable', true)
            ->assertJsonCount(0, 'data')
            ->assertDontSee('JOAO DA TV');
    });

    it('limitada: chamadas normais', function () {
        blockedClinicAccess('limited');

        $this->getJson(route('call-panel.feed', str_repeat('t', 48)))
            ->assertOk()
            ->assertJsonPath('unavailable', false)
            ->assertJsonPath('data.0.patient', 'JOAO DA TV');
    });
});

describe('portal do paciente', function () {
    beforeEach(function () {
        $this->person  = People::factory()->create();
        $this->patient = Patient::factory()->create(['entity_id' => $this->clinic->id, 'person_id' => $this->person->id]);
        $this->account = PatientAccount::factory()->create(['person_id' => $this->person->id]);

        // Rota de escrita do portal (ex.: agendar) — o middleware vale para todas.
        Route::middleware(['web', 'patient.auth', 'patient.read-only'])
            ->post('/meus-documentos/clinicas/{patient}/teste-escrita', fn (Patient $patient) => response()->json(['ok' => true]))
            ->name('test.portal.write');
    });

    it('bloqueada: só leitura — vê os documentos com aviso neutro, escrita recusada sem falar de pagamento', function () {
        blockedClinicAccess('none');
        loginAsPatient($this->account);

        $this->get(route('patient-portal.dashboard'), inertiaHeaders())
            ->assertOk()
            ->assertJsonPath('props.clinics.0.read_only', true)
            ->assertJsonPath('props.readOnly.notice', __('patient_portal.read_only.notice'));

        $this->get(route('patient-portal.clinics.show', $this->patient), inertiaHeaders())
            ->assertOk()
            ->assertJsonPath('props.readOnly', true);

        $response = $this->postJson('/meus-documentos/clinicas/' . $this->patient->id . '/teste-escrita')
            ->assertStatus(423)
            ->assertJsonPath('code', 'clinic_read_only')
            ->assertJsonPath('message', __('patient_portal.read_only.action_blocked'));

        expect(mb_strtolower((string) $response->json('message')))->not->toContain('pagamento')
            ->and(mb_strtolower((string) $response->json('message')))->not->toContain('assinatura');
    });

    it('em dia: escrita liberada e sem aviso', function () {
        blockedClinicAccess('full');
        loginAsPatient($this->account);

        $this->get(route('patient-portal.dashboard'), inertiaHeaders())->assertJsonPath('props.clinics.0.read_only', false);
        $this->postJson('/meus-documentos/clinicas/' . $this->patient->id . '/teste-escrita')->assertOk();
    });
});

describe('API de integradores', function () {
    it('acesso none: 403 (ApiCheckPlanAccess); acesso limitado: liberada', function () {
        $plan = Plan::factory()->create();
        PlanFeature::create(['plan_id' => $plan->id, 'feature' => FeatureKey::HasApiIntegrator->value, 'value' => '1']);
        $this->plan = $plan;

        $sub = blockedClinicAccess('none');

        $integratorUser = EntityUserIntegrator::factory()->create(['entity_id' => $this->clinic->id, 'active' => true]);
        $integrator     = EntityIntegrator::factory()->create(['entity_user_integrator_id' => $integratorUser->id, 'active' => true]);
        $token          = $integratorUser->createToken('integrator-token', ['integrator_id:' . $integrator->id], now()->addDays(7));
        $headers        = ['Authorization' => 'Bearer ' . $token->plainTextToken];

        $this->getJson('/api/integrators/v1/patients', $headers)->assertForbidden()->assertJsonPath('valid', false);

        $sub->delete();
        blockedClinicAccess('limited');

        $this->getJson('/api/integrators/v1/patients', $headers)->assertOk();
        expect(SubscriptionAccessLevel::Limited->hasAccess())->toBeTrue();
    });
});
