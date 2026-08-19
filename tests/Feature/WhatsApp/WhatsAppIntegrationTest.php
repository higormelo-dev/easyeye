<?php

declare(strict_types=1);

use App\Enums\{SaasRule, ScheduleSituation};
use App\Http\Controllers\Manager\WhatsAppController;
use App\Jobs\WhatsApp\SendWhatsAppMessageJob;
use App\Models\{Entity, Schedule, ScheduleSituationLog, User};
use App\Models\WhatsApp\{WhatsAppMessage, WhatsAppSetting};
use App\Services\WhatsApp\{WhatsAppService, ZApiClient};
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Queue};
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(RefreshDatabase::class);

/**
 * Integração WhatsApp (Z-API): confirmação de consulta + pesquisa de
 * satisfação. Driver mock (config whatsapp.driver=mock, default em teste) —
 * nenhum HTTP sai; o fluxo completo comando→job→webhook→transição é real.
 */
beforeEach(function () {
    config()->set('whatsapp.driver', 'mock');

    $this->entity = Entity::factory()->create(['is_client' => true, 'name' => 'CLINICA TESTE WPP']);

    $this->setting = WhatsAppSetting::create([
        'entity_id'                 => $this->entity->id,
        'credentials'               => ['instance_id' => 'INST1', 'instance_token' => 'TOK1', 'client_token' => 'CT1'],
        'instance_id'               => 'INST1',
        'webhook_token'             => 'test-webhook-token-0000000000000000000000000000',
        'active'                    => true,
        'confirmation_enabled'      => true,
        'confirmation_hours_before' => 24,
        'survey_enabled'            => true,
        'survey_delay_hours'        => 0,
    ]);

    $this->doctor = createDoctorForEntity($this->entity);

    $this->schedule = Schedule::query()->create([
        'entity_id' => $this->entity->id,
        'doctor_id' => $this->doctor->id,
        'full_name' => 'MARIA DA SILVA',
        'cellphone' => '61999998888',
        'date_time' => now()->addHours(5),
        'situation' => ScheduleSituation::Scheduled->value,
        'active'    => true,
    ]);
});

describe('normalização de telefone', function () {
    it('prefixa DDI 55 e aceita 10/11 dígitos', function () {
        expect(WhatsAppService::normalizePhone('61999998888'))->toBe('5561999998888')
            ->and(WhatsAppService::normalizePhone('(61) 99999-8888'))->toBe('5561999998888')
            ->and(WhatsAppService::normalizePhone('6133334444'))->toBe('556133334444')
            ->and(WhatsAppService::normalizePhone('5561999998888'))->toBe('5561999998888')
            ->and(WhatsAppService::normalizePhone('123'))->toBeNull()
            ->and(WhatsAppService::normalizePhone(null))->toBeNull();
    });
});

describe('whatsapp:send-confirmations', function () {
    it('cria mensagem pending e despacha o job para consulta na janela', function () {
        Queue::fake();

        $this->artisan('whatsapp:send-confirmations')->assertSuccessful();

        $message = WhatsAppMessage::query()->where('kind', 'confirmation')->first();
        expect($message)->not->toBeNull()
            ->and($message->schedule_id)->toBe($this->schedule->id)
            ->and($message->phone)->toBe('5561999998888')
            ->and($message->status)->toBe('pending')
            ->and($message->body)->toContain('Maria')
            ->and($message->body)->toContain('1 - CONFIRMAR')
            ->and($message->body)->toContain('2 - CANCELAR');

        Queue::assertPushed(SendWhatsAppMessageJob::class, 1);
    });

    it('é idempotente: rodar duas vezes não duplica a confirmação', function () {
        Queue::fake();

        $this->artisan('whatsapp:send-confirmations')->assertSuccessful();
        $this->artisan('whatsapp:send-confirmations')->assertSuccessful();

        expect(WhatsAppMessage::query()->where('kind', 'confirmation')->count())->toBe(1);
    });

    it('ignora consultas fora da janela e clínicas com integração inativa', function () {
        Queue::fake();
        $this->schedule->updateQuietly(['date_time' => now()->addDays(10)]);

        $this->artisan('whatsapp:send-confirmations')->assertSuccessful();
        expect(WhatsAppMessage::count())->toBe(0);

        $this->schedule->updateQuietly(['date_time' => now()->addHours(5)]);
        $this->setting->update(['active' => false]);

        $this->artisan('whatsapp:send-confirmations')->assertSuccessful();
        expect(WhatsAppMessage::count())->toBe(0);
    });
});

describe('envio (job + driver mock)', function () {
    it('marca sent com zapi_message_id ao enviar', function () {
        $message = app(WhatsAppService::class)->queueConfirmation($this->setting, $this->schedule->fresh());

        (new SendWhatsAppMessageJob((string) $message->id))->handle(app(ZApiClient::class));

        $message->refresh();
        expect($message->status)->toBe('sent')
            ->and($message->zapi_message_id)->toStartWith('mock-')
            ->and($message->sent_at)->not->toBeNull();
    });
});

describe('webhook inbound + resposta do paciente', function () {
    function sendOutboundConfirmation($test): WhatsAppMessage
    {
        $message = app(WhatsAppService::class)->queueConfirmation($test->setting, $test->schedule->fresh());
        $message->update(['status' => 'sent', 'sent_at' => now(), 'zapi_message_id' => 'out-1']);

        return $message;
    }

    function postWebhook($test, array $overrides = [])
    {
        return $test->postJson('/api/whatsapp/webhooks/' . $test->setting->webhook_token, array_merge([
            'type'       => 'ReceivedCallback',
            'instanceId' => 'INST1',
            'messageId'  => 'in-' . uniqid(),
            'fromMe'     => false,
            'phone'      => '5561999998888',
            'text'       => ['message' => '1'],
        ], $overrides));
    }

    it('resposta "1" confirma a consulta com efeitos completos (confirmed_at + log + patient-initiated)', function () {
        sendOutboundConfirmation($this);

        postWebhook($this, ['text' => ['message' => '1']])->assertOk();

        // Processa o job inline (fila síncrona em teste... dispatch real):
        // o webhook despacha afterCommit; com QUEUE sync em testes o job já rodou.
        $schedule = $this->schedule->fresh();
        expect($schedule->situation)->toBe(ScheduleSituation::Confirmed)
            ->and($schedule->confirmed_at)->not->toBeNull();

        $log = ScheduleSituationLog::query()->where('schedule_id', $schedule->id)->first();
        expect($log)->not->toBeNull()
            ->and($log->entity_user_id)->toBeNull() // ação do paciente
            ->and($log->to_situation)->toBe(ScheduleSituation::Confirmed);

        // Outbound marcada como respondida + ack enviado.
        expect(WhatsAppMessage::query()->where('kind', 'confirmation')->value('status'))->toBe('answered')
            ->and(WhatsAppMessage::query()->where('kind', 'ack')->exists())->toBeTrue();
    });

    it('resposta "2" cancela com cancellation_reason', function () {
        sendOutboundConfirmation($this);

        postWebhook($this, ['text' => ['message' => '2']])->assertOk();

        $schedule = $this->schedule->fresh();
        expect($schedule->situation)->toBe(ScheduleSituation::Cancelled)
            ->and($schedule->cancellation_reason)->toBe('Cancelado pelo paciente via WhatsApp');
    });

    it('NÃO sobrescreve consulta que já saiu de Scheduled (ex.: já Attended)', function () {
        sendOutboundConfirmation($this);
        $this->schedule->updateQuietly(['situation' => ScheduleSituation::Attended->value]);

        postWebhook($this, ['text' => ['message' => '2']])->assertOk();

        expect($this->schedule->fresh()->situation)->toBe(ScheduleSituation::Attended);
    });

    it('redelivery do mesmo messageId é deduplicada (idempotência)', function () {
        sendOutboundConfirmation($this);

        postWebhook($this, ['messageId' => 'dup-1'])->assertOk();
        $response = postWebhook($this, ['messageId' => 'dup-1']);

        $response->assertOk()->assertJsonPath('duplicate', true);
        expect(WhatsAppMessage::query()->where('direction', 'in')->count())->toBe(1);
    });

    it('[SEGURANÇA] token de webhook inválido → 404; instanceId divergente → 404', function () {
        $this->postJson('/api/whatsapp/webhooks/token-que-nao-existe', ['type' => 'ReceivedCallback'])
            ->assertNotFound();

        postWebhook($this, ['instanceId' => 'INSTANCIA-DE-OUTRA-CLINICA'])->assertNotFound();
    });

    it('ignora fromMe e callbacks que não são de recebimento', function () {
        postWebhook($this, ['fromMe' => true])->assertOk()->assertJsonPath('ignored', 'fromMe/status');
        postWebhook($this, ['type' => 'MessageStatusCallback'])->assertOk()->assertJsonPath('ignored', 'type');
        expect(WhatsAppMessage::query()->where('direction', 'in')->count())->toBe(0);
    });
});

describe('pesquisa de satisfação', function () {
    it('whatsapp:send-surveys envia para Attended após o delay e registra a nota da resposta', function () {
        // Fake SÓ o job de envio — o de inbound (webhook) precisa rodar de
        // verdade pra registrar o score.
        Queue::fake([SendWhatsAppMessageJob::class]);
        $this->schedule->updateQuietly([
            'situation' => ScheduleSituation::Attended->value,
            'date_time' => now()->subHours(3),
        ]);

        $this->artisan('whatsapp:send-surveys')->assertSuccessful();

        $survey = WhatsAppMessage::query()->where('kind', 'survey')->first();
        expect($survey)->not->toBeNull()
            ->and($survey->body)->toContain('1 a 5');

        // Simula envio + resposta "5" via webhook.
        $survey->update(['status' => 'sent', 'sent_at' => now(), 'zapi_message_id' => 'srv-1']);

        $this->postJson('/api/whatsapp/webhooks/' . $this->setting->webhook_token, [
            'type'       => 'ReceivedCallback',
            'instanceId' => 'INST1',
            'messageId'  => 'in-survey-1',
            'fromMe'     => false,
            'phone'      => '5561999998888',
            'text'       => ['message' => '5'],
        ])->assertOk();

        $survey->refresh();
        expect($survey->status)->toBe('answered')
            ->and($survey->survey_score)->toBe(5);
    });

    it('nota inválida não registra score e pede novamente', function () {
        $survey = app(WhatsAppService::class)->queueSurvey($this->setting, $this->schedule->fresh());
        $survey->update(['status' => 'sent', 'sent_at' => now(), 'zapi_message_id' => 'srv-2']);

        $ack = app(WhatsAppService::class)->handleInbound($this->setting, '5561999998888', 'nota 10!');

        expect($ack)->toContain('1 a 5')
            ->and($survey->fresh()->survey_score)->toBeNull()
            ->and($survey->fresh()->status)->toBe('sent'); // continua aguardando
    });
});

describe('configurações no MANAGER (exclusivas do dono do SaaS)', function () {
    /**
     * A configuração vive em /panel/manager/whatsapp (Gate SaasAdminPanel) —
     * requisito de produto: "só parte se cadastrado e configurado pelos donos
     * do SaaS". A clínica não tem NENHUMA rota de configuração.
     */
    function actingAsSaas($test, string $rule, bool $owner = false): void
    {
        $saas = Entity::factory()->create(['is_client' => false, 'active' => true]);
        $user = User::factory()->create();
        createEntityUser($saas, $user, $rule, isOwner: $owner);

        $test->actingAs($user);
        session(['selected_entity_id' => $saas->id]);
    }

    function callManagerUpdate($test, array $payload)
    {
        $request = Request::create('/panel/manager/whatsapp/x', 'PATCH', $payload);
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(fn () => auth()->user());

        return app(WhatsAppController::class)
            ->update($request, $test->entity);
    }

    function callManagerTest($test, array $payload)
    {
        $request = Request::create('/panel/manager/whatsapp/x/test', 'POST', $payload);
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(fn () => auth()->user());

        return app(WhatsAppController::class)
            ->test($request, $test->entity);
    }

    it('[SEGURANÇA] credenciais ficam criptografadas at rest', function () {
        $raw = DB::table('whatsapp_settings')->where('id', $this->setting->id)->value('credentials');

        expect($raw)->not->toContain('TOK1')
            ->and($raw)->not->toContain('CT1')
            ->and($this->setting->fresh()->credentials['instance_token'])->toBe('TOK1');
    });

    it('[SEGURANÇA] clínica NÃO tem rota de configuração de WhatsApp (removida)', function () {
        expect(Route::has('panel.setting.whatsapp.index'))->toBeFalse()
            ->and(Route::has('panel.setting.whatsapp.update'))->toBeFalse();
    });

    it('[SEGURANÇA] staff do SaaS que não é Admin (Support/Financial) não configura', function () {
        actingAsSaas($this, SaasRule::Support->value);

        expect(fn () => callManagerUpdate($this, [
            'active'         => true, 'confirmation_enabled' => true, 'confirmation_hours_before' => 24,
            'survey_enabled' => true, 'survey_delay_hours' => 2,
        ]))->toThrow(AuthorizationException::class);
    });

    it('admin do SaaS configura a instância de uma clínica; tokens nunca voltam na resposta', function () {
        actingAsSaas($this, SaasRule::Admin->value);

        $response = callManagerUpdate($this, [
            'active'                    => true,
            'confirmation_enabled'      => true,
            'confirmation_hours_before' => 48,
            'survey_enabled'            => false,
            'survey_delay_hours'        => 4,
            'instance_id'               => 'NEWINST',
            'instance_token'            => 'NEWTOK',
            'client_token'              => 'NEWCT',
        ]);

        expect($response->getStatusCode())->toBe(200);

        $body = $response->getData(true);
        expect($body['has_credentials'])->toBeTrue()
            ->and(json_encode($body))->not->toContain('NEWTOK')
            ->and(json_encode($body))->not->toContain('NEWCT');

        $setting = WhatsAppSetting::query()->where('entity_id', $this->entity->id)->first();
        expect($setting->confirmation_hours_before)->toBe(48)
            ->and($setting->survey_enabled)->toBeFalse()
            ->and($setting->credentials['instance_token'])->toBe('NEWTOK')
            ->and($setting->instance_id)->toBe('NEWINST');

        expect(DB::table('audit_logs')->where('event', 'manager.whatsapp_settings.updated')->exists())->toBeTrue();
    });

    it('[SEGURANÇA] update em entity que não é clínica retorna 404', function () {
        actingAsSaas($this, SaasRule::Admin->value);
        $saasEntity   = Entity::query()->where('is_client', false)->firstOrFail();
        $this->entity = $saasEntity;

        expect(fn () => callManagerUpdate($this, [
            'active'         => true, 'confirmation_enabled' => true, 'confirmation_hours_before' => 24,
            'survey_enabled' => true, 'survey_delay_hours' => 2,
        ]))->toThrow(NotFoundHttpException::class);
    });

    it('testar conexão aceita credenciais digitadas ANTES de salvar, sem persistir nada', function () {
        actingAsSaas($this, SaasRule::Admin->value);

        // Clínica virgem: sem WhatsAppSetting algum (o beforeEach cria um
        // para $this->entity — aqui queremos o fluxo "primeira configuração").
        $this->entity = Entity::factory()->create(['is_client' => true]);

        $response = callManagerTest($this, [
            'instance_id'    => 'ADHOC-INST',
            'instance_token' => 'ADHOC-TOK',
            'client_token'   => 'ADHOC-CT',
        ]);

        expect($response->getStatusCode())->toBe(200)
            ->and($response->getData(true)['ok'])->toBeTrue()
            ->and($response->getData(true)['connected'])->toBeTrue();

        // Teste é transiente: nada foi salvo pra clínica.
        expect(WhatsAppSetting::query()->where('entity_id', $this->entity->id)->exists())->toBeFalse();
    });

    it('testar conexão com credenciais parciais falha na validação (all-or-none)', function () {
        actingAsSaas($this, SaasRule::Admin->value);

        expect(fn () => callManagerTest($this, ['instance_id' => 'SO-O-ID']))
            ->toThrow(ValidationException::class);
    });

    it('testar conexão sem credenciais digitadas nem salvas retorna 422', function () {
        actingAsSaas($this, SaasRule::Admin->value);

        $this->entity = Entity::factory()->create(['is_client' => true]);

        $response = callManagerTest($this, []);

        expect($response->getStatusCode())->toBe(422)
            ->and($response->getData(true)['ok'])->toBeFalse();
    });
});

describe('instância GLOBAL do SaaS (padrão pra clínica sem número próprio)', function () {
    function createGlobalSetting(array $overrides = []): WhatsAppSetting
    {
        return WhatsAppSetting::create(array_merge([
            'entity_id'                 => null,
            'credentials'               => ['instance_id' => 'GINST', 'instance_token' => 'GTOK', 'client_token' => 'GCT'],
            'instance_id'               => 'GINST',
            'webhook_token'             => 'global-webhook-token-000000000000000000000000000',
            'active'                    => true,
            'confirmation_enabled'      => false,
            'survey_enabled'            => false,
            'confirmation_hours_before' => 24,
            'survey_delay_hours'        => 2,
        ], $overrides));
    }

    /** Clínica SEM credencial própria (usa a global) + consulta agendada. */
    function createClinicUsingGlobal($test): array
    {
        $entity  = Entity::factory()->create(['is_client' => true, 'name' => 'CLINICA SEM NUMERO']);
        $setting = WhatsAppSetting::create([
            'entity_id'                 => $entity->id,
            'credentials'               => null,
            'webhook_token'             => 'clinic-no-creds-token-00000000000000000000000000',
            'active'                    => true,
            'confirmation_enabled'      => true,
            'confirmation_hours_before' => 24,
            'survey_enabled'            => true,
            'survey_delay_hours'        => 0,
        ]);
        $doctor   = createDoctorForEntity($entity);
        $schedule = Schedule::query()->create([
            'entity_id' => $entity->id,
            'doctor_id' => $doctor->id,
            'full_name' => 'JOANA GLOBAL',
            'cellphone' => '61988887777',
            'date_time' => now()->addHours(5),
            'situation' => ScheduleSituation::Scheduled->value,
            'active'    => true,
        ]);

        return [$entity, $setting, $schedule];
    }

    it('clínica sem credencial própria envia confirmação pela instância global', function () {
        createGlobalSetting();
        [, , $schedule] = createClinicUsingGlobal($this);

        $this->artisan('whatsapp:send-confirmations')->assertSuccessful();

        $message = WhatsAppMessage::query()
            ->where('schedule_id', $schedule->id)
            ->where('kind', 'confirmation')
            ->first();

        expect($message)->not->toBeNull();

        // Job resolve credenciais da global e envia (driver mock).
        (new SendWhatsAppMessageJob((string) $message->id))->handle(app(ZApiClient::class));

        expect($message->fresh()->status)->toBe('sent');
    });

    it('sem global e sem credencial própria, envio falha com trilha', function () {
        [, , $schedule] = createClinicUsingGlobal($this);

        $this->artisan('whatsapp:send-confirmations')->assertSuccessful();

        $message = WhatsAppMessage::query()
            ->where('schedule_id', $schedule->id)->where('kind', 'confirmation')->first();

        // Sem global: comando nem enfileira (filtro), então não há mensagem.
        expect($message)->toBeNull();
    });

    it('webhook GLOBAL casa a resposta com a clínica certa e confirma a consulta', function () {
        Queue::fake([SendWhatsAppMessageJob::class]);
        $global                = createGlobalSetting();
        [$entity, , $schedule] = createClinicUsingGlobal($this);

        // Outbound enviado pela global (pendente de resposta).
        $out = app(WhatsAppService::class)->queueConfirmation(
            WhatsAppSetting::query()->where('entity_id', $entity->id)->first(),
            $schedule,
        );
        $out->update(['status' => 'sent', 'sent_at' => now(), 'zapi_message_id' => 'out-g1']);

        $response = $this->postJson('/api/whatsapp/webhooks/' . $global->webhook_token, [
            'type'       => 'ReceivedCallback',
            'instanceId' => 'GINST',
            'messageId'  => 'in-g-' . uniqid(),
            'fromMe'     => false,
            'phone'      => '5561988887777',
            'text'       => ['message' => '1'],
        ]);

        $response->assertOk();

        expect($schedule->fresh()->situation)->toBe(ScheduleSituation::Confirmed);

        // Inbound ganhou o entity_id da clínica casada (trilha por tenant).
        $inbound = WhatsAppMessage::query()->where('direction', 'in')->latest('created_at')->first();
        expect($inbound->entity_id)->toBe($entity->id);
    });

    it('[SEGURANÇA] webhook global NÃO casa resposta de clínica com número próprio', function () {
        Queue::fake([SendWhatsAppMessageJob::class]);
        $global = createGlobalSetting();

        // $this->entity (beforeEach) TEM credencial própria — a resposta do
        // paciente dela chega pelo webhook DELA, nunca pelo global.
        $out = app(WhatsAppService::class)->queueConfirmation($this->setting, $this->schedule->fresh());
        $out->update(['status' => 'sent', 'sent_at' => now(), 'zapi_message_id' => 'out-own']);

        $this->postJson('/api/whatsapp/webhooks/' . $global->webhook_token, [
            'type'       => 'ReceivedCallback',
            'instanceId' => 'GINST',
            'messageId'  => 'in-x-' . uniqid(),
            'fromMe'     => false,
            'phone'      => '5561999998888',
            'text'       => ['message' => '1'],
        ])->assertOk();

        // Nada casou: consulta continua Scheduled.
        expect($this->schedule->fresh()->situation)->toBe(ScheduleSituation::Scheduled);
    });

    it('updateGlobal salva credenciais criptografadas e é singleton', function () {
        actingAsSaas($this, SaasRule::Admin->value);

        $request = Request::create('/panel/manager/whatsapp/global', 'PATCH', [
            'active'         => true,
            'instance_id'    => 'GNEW',
            'instance_token' => 'GNEWTOK',
            'client_token'   => 'GNEWCT',
        ]);
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(fn () => auth()->user());

        $response = app(WhatsAppController::class)->updateGlobal($request);

        expect($response->getStatusCode())->toBe(200);

        $global = WhatsAppSetting::globalSetting();
        expect($global)->not->toBeNull()
            ->and($global->credentials['instance_token'])->toBe('GNEWTOK')
            ->and(json_encode($response->getData(true)))->not->toContain('GNEWTOK');

        // Segunda chamada atualiza a MESMA linha (singleton).
        $response2 = app(WhatsAppController::class)->updateGlobal($request);
        expect($response2->getStatusCode())->toBe(200)
            ->and(WhatsAppSetting::query()->whereNull('entity_id')->count())->toBe(1);
    });

    it('clear_credentials remove as credenciais da clínica (volta pra "não configurado")', function () {
        actingAsSaas($this, SaasRule::Admin->value);

        // beforeEach deixa $this->setting COM credenciais.
        expect($this->setting->hasCredentials())->toBeTrue();

        $response = callManagerUpdate($this, [
            'active'                    => false,
            'confirmation_enabled'      => true,
            'confirmation_hours_before' => 24,
            'survey_enabled'            => true,
            'survey_delay_hours'        => 2,
            'clear_credentials'         => true,
        ]);

        expect($response->getStatusCode())->toBe(200)
            ->and($response->getData(true)['has_credentials'])->toBeFalse();

        $setting = $this->setting->fresh();
        expect($setting->hasCredentials())->toBeFalse()
            ->and($setting->instance_id)->toBeNull();

        $audit = DB::table('audit_logs')->where('event', 'manager.whatsapp_settings.updated')->latest('id')->first();
        expect($audit)->not->toBeNull();
    });

    it('[SEGURANÇA] staff que não é Admin do SaaS não configura a global', function () {
        actingAsSaas($this, SaasRule::Support->value);

        $request = Request::create('/panel/manager/whatsapp/global', 'PATCH', ['active' => true]);
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(fn () => auth()->user());

        expect(fn () => app(WhatsAppController::class)->updateGlobal($request))
            ->toThrow(AuthorizationException::class);
    });
});
