<?php

declare(strict_types=1);

use App\Enums\{SaasRule, ScheduleSituation};
use App\Http\Controllers\Manager\WhatsAppController;
use App\Jobs\WhatsApp\{ProcessWhatsAppStatusJob, SendWhatsAppMessageJob};
use App\Models\{Entity, Patient, People, Schedule, ScheduleSituationLog, User};
use App\Models\WhatsApp\{WhatsAppMessage, WhatsAppOptOut, WhatsAppSetting};
use App\Services\WhatsApp\{WhatsAppService, WhatsAppTemplates};
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Queue, Route};
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * WhatsApp oficial (Gupshup/Meta): confirmação de consulta + pesquisa de
 * satisfação por template, webhook v3 (texto, botão, status), descadastro
 * (SAIR/VOLTAR), marcação "celular é WhatsApp" e manager. Driver mock (nenhum
 * HTTP sai — o caminho HTTP real está em GupshupProviderTest); o fluxo
 * comando → job → webhook → transição é o de verdade.
 */
beforeEach(function () {
    config()->set('whatsapp.driver', 'mock');

    $this->entity = Entity::factory()->create(['is_client' => true, 'name' => 'CLINICA TESTE WPP']);

    $this->setting = WhatsAppSetting::create([
        'entity_id'                 => $this->entity->id,
        'app_id'                    => 'clinic-app-1',
        'webhook_token'             => 'test-webhook-token-0000000000000000000000000000',
        'webhook_secret'            => 'segredo-do-webhook-da-clinica',
        'active'                    => true,
        'confirmation_enabled'      => true,
        'confirmation_hours_before' => 24,
        'survey_enabled'            => true,
        'survey_delay_hours'        => 0,
    ]);

    $this->doctor = createDoctorForEntity($this->entity);

    $this->schedule = Schedule::query()->create([
        'entity_id'          => $this->entity->id,
        'doctor_id'          => $this->doctor->id,
        'full_name'          => 'MARIA DA SILVA',
        'cellphone'          => '61999998888',
        'cellphone_whatsapp' => true,
        'date_time'          => now()->addHours(5),
        'situation'          => ScheduleSituation::Scheduled->value,
        'active'             => true,
    ]);
});

/** Confirmação enfileirada e enviada de verdade pelo job (driver mock). */
function sentConfirmation($test): WhatsAppMessage
{
    $message = app(WhatsAppService::class)->queueConfirmation($test->setting, $test->schedule->fresh());
    SendWhatsAppMessageJob::dispatchSync((string) $message->id);

    return $message->fresh();
}

/** Corpo v3 da Gupshup (formato Meta + gs_app_id). */
function gupshupEvent(WhatsAppSetting $setting, array $value, ?string $appId = null): array
{
    return [
        'gs_app_id' => $appId ?? $setting->app_id,
        'object'    => 'whatsapp_business_account',
        'entry'     => [[
            'id'      => 'WABA-1',
            'changes' => [['field' => 'messages', 'value' => ['messaging_product' => 'whatsapp', ...$value]]],
        ]],
    ];
}

function postGupshup($test, WhatsAppSetting $setting, array $value, ?string $secret = null, ?string $appId = null)
{
    return $test->withHeaders([WhatsAppSetting::WEBHOOK_SECRET_HEADER => $secret ?? (string) $setting->webhook_secret])
        ->postJson('/api/whatsapp/gupshup/webhook/' . $setting->webhook_token, gupshupEvent($setting, $value, $appId));
}

function inboundText(string $text, string $from = '5561999998888', ?string $id = null, ?string $contextId = null): array
{
    return [
        'contacts' => [['profile' => ['name' => 'Maria'], 'wa_id' => $from]],
        'messages' => [array_filter([
            'from'      => $from,
            'id'        => $id ?? 'wamid.' . uniqid(),
            'timestamp' => (string) now()->timestamp,
            'type'      => 'text',
            'text'      => ['body' => $text],
            'context'   => $contextId ? ['from' => '551133334444', 'id' => $contextId] : null,
        ])],
    ];
}

function inboundButton(string $payload, string $text, string $from = '5561999998888', ?string $contextId = null): array
{
    return [
        'contacts' => [['profile' => ['name' => 'Maria'], 'wa_id' => $from]],
        'messages' => [array_filter([
            'from'      => $from,
            'id'        => 'wamid.' . uniqid(),
            'timestamp' => (string) now()->timestamp,
            'type'      => 'button',
            'button'    => ['payload' => $payload, 'text' => $text],
            'context'   => $contextId ? ['from' => '551133334444', 'id' => $contextId] : null,
        ])],
    ];
}

describe('telefone', function () {
    it('normaliza com DDI 55, aceita 10/11 dígitos e põe o 9º dígito no celular antigo', function () {
        expect(WhatsAppService::normalizePhone('61999998888'))->toBe('5561999998888')
            ->and(WhatsAppService::normalizePhone('(61) 99999-8888'))->toBe('5561999998888')
            ->and(WhatsAppService::normalizePhone('6133334444'))->toBe('556133334444') // fixo: sem 9
            ->and(WhatsAppService::normalizePhone('5561999998888'))->toBe('5561999998888')
            // wa_id da Meta sem o 9º dígito casa com o número cadastrado com o 9.
            ->and(WhatsAppService::normalizePhone('556199998888'))->toBe('5561999998888')
            ->and(WhatsAppService::normalizePhone('+55 61 9999-8888'))->toBe('5561999998888')
            ->and(WhatsAppService::normalizePhone('123'))->toBeNull()
            ->and(WhatsAppService::normalizePhone(null))->toBeNull();
    });

    it('número estrangeiro com "+" fica íntegro (antes era corrompido)', function () {
        expect(WhatsAppService::normalizePhone('+1 (415) 555-2671'))->toBe('14155552671')
            ->and(WhatsAppService::normalizePhone('+351 912 345 678'))->toBe('351912345678')
            ->and(WhatsAppService::normalizePhone('0044 7911 123456'))->toBe('447911123456');
    });

    it('só envia para celular marcado como WhatsApp (agendamento ou cadastro do paciente)', function () {
        $this->schedule->updateQuietly(['cellphone_whatsapp' => false]);
        expect(WhatsAppService::resolveSchedulePhone($this->schedule->fresh()))->toBeNull();

        $person  = People::factory()->create(['cellphone' => '61988887777', 'whatsapp' => true]);
        $patient = Patient::factory()->create(['entity_id' => $this->entity->id, 'person_id' => $person->id]);
        $this->schedule->updateQuietly(['patient_id' => $patient->id]);
        expect(WhatsAppService::resolveSchedulePhone($this->schedule->fresh()))->toBe('5561988887777');

        $person->update(['whatsapp' => false]);
        expect(WhatsAppService::resolveSchedulePhone($this->schedule->fresh()))->toBeNull();
    });
});

describe('whatsapp:send-confirmations', function () {
    it('cria mensagem pending com o template e despacha o job para consulta na janela', function () {
        Queue::fake();

        $this->artisan('whatsapp:send-confirmations')->assertSuccessful();

        $message = WhatsAppMessage::query()->where('kind', 'confirmation')->first();
        expect($message)->not->toBeNull()
            ->and($message->schedule_id)->toBe($this->schedule->id)
            ->and($message->phone)->toBe('5561999998888')
            ->and($message->status)->toBe('pending')
            ->and($message->template)->toBe('easyeye_confirmacao_consulta')
            ->and($message->body)->toContain('Maria')
            ->and($message->body)->toContain('CLINICA TESTE WPP')
            ->and($message->body)->toContain('[Confirmar] [Cancelar]')
            ->and($message->payload['template_key'])->toBe('appointment_confirmation');

        Queue::assertPushed(SendWhatsAppMessageJob::class, 1);
    });

    it('é idempotente: rodar duas vezes não duplica a confirmação', function () {
        Queue::fake();

        $this->artisan('whatsapp:send-confirmations')->assertSuccessful();
        $this->artisan('whatsapp:send-confirmations')->assertSuccessful();

        expect(WhatsAppMessage::query()->where('kind', 'confirmation')->count())->toBe(1);
    });

    it('ignora consultas fora da janela, clínicas inativas e celular sem a marcação WhatsApp', function () {
        Queue::fake();
        $this->schedule->updateQuietly(['date_time' => now()->addDays(10)]);

        $this->artisan('whatsapp:send-confirmations')->assertSuccessful();
        expect(WhatsAppMessage::count())->toBe(0);

        $this->schedule->updateQuietly(['date_time' => now()->addHours(5), 'cellphone_whatsapp' => false]);
        $this->artisan('whatsapp:send-confirmations')->assertSuccessful();
        expect(WhatsAppMessage::count())->toBe(0);

        $this->schedule->updateQuietly(['cellphone_whatsapp' => true]);
        $this->setting->update(['active' => false]);

        $this->artisan('whatsapp:send-confirmations')->assertSuccessful();
        expect(WhatsAppMessage::count())->toBe(0);
    });

    it('paciente descadastrado (SAIR) do número que envia não recebe', function () {
        Queue::fake();
        app(WhatsAppService::class)->optOut($this->setting, '5561999998888');

        $this->artisan('whatsapp:send-confirmations')->assertSuccessful();

        expect(WhatsAppMessage::count())->toBe(0);
        Queue::assertNothingPushed();
    });
});

describe('envio (job + driver mock)', function () {
    it('marca sent com o id do provedor, o app de saída e o template', function () {
        $message = sentConfirmation($this);

        expect($message->status)->toBe('sent')
            ->and($message->provider_message_id)->toStartWith('mock-')
            ->and($message->whatsapp_setting_id)->toBe($this->setting->id)
            ->and($message->sender_app_id)->toBe('clinic-app-1')
            ->and($message->sent_at)->not->toBeNull();
    });

    it('descadastrado depois de enfileirar: suprimida, sem envio e sem voltar à fila', function () {
        $message = app(WhatsAppService::class)->queueConfirmation($this->setting, $this->schedule->fresh());
        app(WhatsAppService::class)->optOut($this->setting, '5561999998888');

        SendWhatsAppMessageJob::dispatchSync((string) $message->id);

        expect($message->fresh()->status)->toBe(WhatsAppMessage::STATUS_SUPPRESSED)
            ->and($message->fresh()->error_code)->toBe('opted_out');
    });

    it('payload dos botões identifica a MENSAGEM (não a consulta nem o paciente)', function () {
        $message  = app(WhatsAppService::class)->queueConfirmation($this->setting, $this->schedule->fresh());
        $template = app(WhatsAppTemplates::class)->build('appointment_confirmation', $message->payload['values'], 'pt_BR', ['message_id' => $message->id]);

        expect(array_column($template->buttons, 'value'))->toBe(["confirm:{$message->id}", "cancel:{$message->id}"])
            ->and($template->bodyParams[0])->toBe('Maria')
            ->and($template->bodyParams[1])->toBe('CLINICA TESTE WPP')
            ->and(json_encode($template->components()))->not->toContain((string) $this->schedule->id);
    });
});

describe('webhook v3 — resposta do paciente', function () {
    it('clique em "Confirmar" (payload) confirma pelo ScheduleService: confirmed_at, log do paciente, ack', function () {
        $out = sentConfirmation($this);

        postGupshup($this, $this->setting, inboundButton("confirm:{$out->id}", 'Confirmar'))->assertOk();

        $schedule = $this->schedule->fresh();
        expect($schedule->situation)->toBe(ScheduleSituation::Confirmed)
            ->and($schedule->confirmed_at)->not->toBeNull();

        $log = ScheduleSituationLog::query()->where('schedule_id', $schedule->id)->first();
        expect($log)->not->toBeNull()
            ->and($log->entity_user_id)->toBeNull() // ação do paciente
            ->and($log->to_situation)->toBe(ScheduleSituation::Confirmed)
            ->and($log->notes)->toBe('Confirmado pelo paciente via WhatsApp');

        $ack = WhatsAppMessage::query()->where('kind', 'ack')->sole();
        expect($out->fresh()->status)->toBe('answered')
            ->and($ack->status)->toBe('sent')
            ->and($ack->body)->toContain('Presença confirmada')
            ->and($ack->schedule_id)->toBe($schedule->id);

        // A entrada ganhou a consulta (trilha do paciente / export LGPD).
        expect(WhatsAppMessage::query()->where('direction', 'in')->value('schedule_id'))->toBe($schedule->id);
    });

    it('clique em "Cancelar" cancela com o motivo traduzido', function () {
        $out = sentConfirmation($this);

        postGupshup($this, $this->setting, inboundButton("cancel:{$out->id}", 'Cancelar'))->assertOk();

        $schedule = $this->schedule->fresh();
        expect($schedule->situation)->toBe(ScheduleSituation::Cancelled)
            ->and($schedule->cancellation_reason)->toBe('Cancelado pelo paciente via WhatsApp');
    });

    it('resposta em texto "1"/"2" segue valendo (casando pelo telefone, inclusive wa_id sem o 9º dígito)', function () {
        sentConfirmation($this);

        postGupshup($this, $this->setting, inboundText('2', from: '556199998888'))->assertOk();

        expect($this->schedule->fresh()->situation)->toBe(ScheduleSituation::Cancelled);
    });

    it('botão sem o nosso payload casa pela mensagem respondida (context.id = id do provedor)', function () {
        $out = sentConfirmation($this);

        postGupshup($this, $this->setting, inboundButton('Confirmar', 'Confirmar', contextId: $out->provider_message_id))->assertOk();

        expect($this->schedule->fresh()->situation)->toBe(ScheduleSituation::Confirmed);
    });

    it('[SEGURANÇA] payload de outra mensagem/telefone não mexe na consulta', function () {
        $out = sentConfirmation($this);

        // Outro telefone tentando usar o payload desta mensagem.
        postGupshup($this, $this->setting, inboundButton("cancel:{$out->id}", 'Cancelar', from: '5561911112222'))->assertOk();

        expect($this->schedule->fresh()->situation)->toBe(ScheduleSituation::Scheduled);
    });

    it('NÃO sobrescreve consulta que já saiu de Scheduled (ex.: já Attended)', function () {
        $out = sentConfirmation($this);
        $this->schedule->updateQuietly(['situation' => ScheduleSituation::Attended->value]);

        postGupshup($this, $this->setting, inboundButton("cancel:{$out->id}", 'Cancelar'))->assertOk();

        expect($this->schedule->fresh()->situation)->toBe(ScheduleSituation::Attended)
            ->and(WhatsAppMessage::query()->where('kind', 'ack')->value('body'))->toContain('já havia sido atualizada');
    });

    it('reentrega do mesmo id é deduplicada (idempotência)', function () {
        sentConfirmation($this);

        postGupshup($this, $this->setting, inboundText('1', id: 'wamid.DUP1'))->assertOk();
        postGupshup($this, $this->setting, inboundText('1', id: 'wamid.DUP1'))->assertOk();

        expect(WhatsAppMessage::query()->where('direction', 'in')->count())->toBe(1)
            ->and(WhatsAppMessage::query()->where('kind', 'ack')->count())->toBe(1);
    });

    it('[SEGURANÇA] token inválido, segredo errado ou app desconhecido → 404', function () {
        $this->postJson('/api/whatsapp/gupshup/webhook/token-que-nao-existe', [])->assertNotFound();

        postGupshup($this, $this->setting, inboundText('1'), secret: 'segredo-errado')->assertNotFound();
        postGupshup($this, $this->setting, inboundText('1'), secret: '')->assertNotFound();
        postGupshup($this, $this->setting, inboundText('1'), appId: 'app-de-outra-clinica')->assertNotFound();

        expect(WhatsAppMessage::query()->where('direction', 'in')->count())->toBe(0);
    });

    it('ignora tipos que o sistema não trata e status "enqueued"', function () {
        postGupshup($this, $this->setting, [
            'messages' => [['from' => '5561999998888', 'id' => 'wamid.IMG', 'type' => 'image', 'image' => ['id' => 'x']]],
            'statuses' => [['id' => 'x', 'gs_id' => 'y', 'status' => 'enqueued']],
        ])->assertOk();

        expect(WhatsAppMessage::query()->where('direction', 'in')->count())->toBe(0);
    });
});

describe('descadastro (SAIR / VOLTAR)', function () {
    it('SAIR descadastra do número que recebeu e confirma; VOLTAR reativa', function () {
        postGupshup($this, $this->setting, inboundText('Sair'))->assertOk();

        expect(WhatsAppOptOut::isSuppressed($this->setting, '5561999998888'))->toBeTrue()
            ->and(WhatsAppMessage::query()->where('kind', 'ack')->latest('created_at')->value('body'))->toContain('VOLTAR');

        postGupshup($this, $this->setting, inboundText('voltar'))->assertOk();

        expect(WhatsAppOptOut::isSuppressed($this->setting, '5561999998888'))->toBeFalse();
    });

    it('PARAR e STOP também descadastram; "Cancelar" (botão) não', function () {
        postGupshup($this, $this->setting, inboundText('PARAR', from: '5561911110000'))->assertOk();
        postGupshup($this, $this->setting, inboundText('stop', from: '5561922220000'))->assertOk();
        postGupshup($this, $this->setting, inboundText('Cancelar', from: '5561933330000'))->assertOk();

        expect(WhatsAppOptOut::query()->pluck('phone')->sort()->values()->all())->toBe(['5561911110000', '5561922220000']);
    });

    it('descadastro vale por número que envia: SAIR no número da clínica não bloqueia o do EasyEye', function () {
        $global = WhatsAppSetting::create([
            'entity_id'      => null, 'app_id' => 'global-app', 'webhook_token' => WhatsAppSetting::generateWebhookToken(),
            'webhook_secret' => 'segredo-global', 'active' => true,
        ]);

        postGupshup($this, $this->setting, inboundText('SAIR'))->assertOk();

        expect(WhatsAppOptOut::isSuppressed($this->setting, '5561999998888'))->toBeTrue()
            ->and(WhatsAppOptOut::isSuppressed($global, '5561999998888'))->toBeFalse();
    });
});

describe('webhook v3 — status de entrega', function () {
    it('sent/delivered/read preenchem wamid e horários; reentrega e ordem trocada não estragam', function () {
        $out = sentConfirmation($this);
        $ts  = (string) now()->subMinute()->timestamp;

        postGupshup($this, $this->setting, ['statuses' => [
            ['gs_id' => $out->provider_message_id, 'id' => 'fc46fadf-uuid', 'meta_msg_id' => 'wamid.OUT1', 'status' => 'read', 'timestamp' => $ts, 'recipient_id' => '5561999998888'],
            ['gs_id' => $out->provider_message_id, 'id' => 'fc46fadf-uuid', 'meta_msg_id' => 'wamid.OUT1', 'status' => 'delivered', 'timestamp' => $ts, 'recipient_id' => '5561999998888'],
            ['gs_id' => $out->provider_message_id, 'id' => 'fc46fadf-uuid', 'meta_msg_id' => 'wamid.OUT1', 'status' => 'sent', 'timestamp' => $ts, 'recipient_id' => '5561999998888'],
        ]])->assertOk();

        $out->refresh();
        expect($out->wa_message_id)->toBe('wamid.OUT1')
            ->and($out->delivered_at)->not->toBeNull()
            ->and($out->read_at)->not->toBeNull()
            ->and($out->status)->toBe('sent');

        // A resposta que cita o wamid (context.id) casa com a mensagem.
        postGupshup($this, $this->setting, inboundText('1', contextId: 'wamid.OUT1'))->assertOk();
        expect($this->schedule->fresh()->situation)->toBe(ScheduleSituation::Confirmed);
    });

    it('failed grava código e motivo e marca a mensagem como falha', function () {
        $out = sentConfirmation($this);

        postGupshup($this, $this->setting, ['statuses' => [
            ['gs_id' => $out->provider_message_id, 'id' => 'x', 'status' => 'failed', 'timestamp' => (string) now()->timestamp, 'code' => 131026, 'reason' => 'Message undeliverable'],
        ]])->assertOk();

        $out->refresh();
        expect($out->status)->toBe('failed')
            ->and($out->failed_at)->not->toBeNull()
            ->and($out->error_code)->toBe('meta_131026')
            ->and($out->error)->toBe('Message undeliverable');
    });

    it('failed por número descadastrado na Gupshup (1012) entra na lista de supressão', function () {
        $out = sentConfirmation($this);

        postGupshup($this, $this->setting, ['statuses' => [
            ['gs_id' => $out->provider_message_id, 'status' => 'failed', 'code' => 1012, 'reason' => 'Number Opted Out'],
        ]])->assertOk();

        expect(WhatsAppOptOut::query()->where('source', WhatsAppOptOut::SOURCE_PROVIDER)->count())->toBe(1);
    });

    it('[SEGURANÇA] status de mensagem enviada por OUTRO app é ignorado', function () {
        $out   = sentConfirmation($this);
        $other = WhatsAppSetting::create([
            'entity_id'     => Entity::factory()->create(['is_client' => true])->id, 'app_id' => 'other-app',
            'webhook_token' => WhatsAppSetting::generateWebhookToken(), 'webhook_secret' => 'outro', 'active' => true,
        ]);

        (new ProcessWhatsAppStatusJob((string) $other->id, ['gs_id' => $out->provider_message_id, 'status' => 'failed', 'code' => 131026]))
            ->withFakeQueueInteractions()
            ->handle(app(WhatsAppService::class));

        expect($out->fresh()->status)->toBe('sent');
    });
});

describe('pesquisa de satisfação', function () {
    it('whatsapp:send-surveys envia após o delay e o botão de nota registra o score', function () {
        $this->schedule->updateQuietly([
            'situation' => ScheduleSituation::Attended->value,
            'date_time' => now()->subHours(3),
        ]);

        $this->artisan('whatsapp:send-surveys')->assertSuccessful();

        $survey = WhatsAppMessage::query()->where('kind', 'survey')->sole();
        expect($survey->status)->toBe('sent') // fila síncrona: o job já enviou
            ->and($survey->template)->toBe('easyeye_pesquisa_satisfacao')
            ->and($survey->body)->toContain('1 a 5');

        postGupshup($this, $this->setting, inboundButton("survey:{$survey->id}:5", '5 - Excelente'))->assertOk();

        $survey->refresh();
        expect($survey->status)->toBe('answered')
            ->and($survey->survey_score)->toBe(5);
    });

    it('nota em texto ("4") e pelo rótulo do botão também valem; nota inválida pede de novo', function () {
        $service = app(WhatsAppService::class);
        $survey  = $service->queueSurvey($this->setting, $this->schedule->fresh());
        SendWhatsAppMessageJob::dispatchSync((string) $survey->id);

        $inbound = WhatsAppMessage::create([
            'entity_id' => $this->entity->id, 'whatsapp_setting_id' => $this->setting->id, 'direction' => 'in', 'kind' => 'reply',
            'phone'     => '5561999998888', 'body' => 'nota 10!', 'status' => 'received',
        ]);

        expect($service->handleInbound($this->setting, $inbound))->toContain('1 a 5')
            ->and($survey->fresh()->survey_score)->toBeNull()
            ->and($survey->fresh()->status)->toBe('sent'); // continua aguardando

        $inbound->update(['body' => '3 - Regular']);
        $service->handleInbound($this->setting, $inbound);

        expect($survey->fresh()->survey_score)->toBe(3);
    });

    it('[SEGURANÇA] botão de nota não vale para mensagem de confirmação', function () {
        $out = sentConfirmation($this);

        postGupshup($this, $this->setting, inboundButton("survey:{$out->id}:1", '1 - Péssimo'))->assertOk();

        expect($out->fresh()->survey_score)->toBeNull();
    });
});

describe('configurações no MANAGER (exclusivas do dono do SaaS)', function () {
    function actingAsSaas($test, string $rule, bool $owner = false): void
    {
        $saas = Entity::factory()->create(['is_client' => false, 'active' => true]);
        $user = User::factory()->create();
        createEntityUser($saas, $user, $rule, isOwner: $owner);

        $test->actingAs($user);
        session(['selected_entity_id' => $saas->id]);
    }

    function callManager(string $method, array $payload, ...$args)
    {
        $request = Request::create('/panel/manager/whatsapp/x', 'POST', $payload);
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(fn () => auth()->user());

        return app(WhatsAppController::class)->{$method}($request, ...$args);
    }

    $toggles = [
        'active'         => true, 'confirmation_enabled' => true, 'confirmation_hours_before' => 24,
        'survey_enabled' => true, 'survey_delay_hours' => 2,
    ];

    it('[SEGURANÇA] segredo do webhook cifrado at rest e fora da auditoria', function () {
        $raw = DB::table('whatsapp_settings')->where('id', $this->setting->id)->value('webhook_secret');

        expect($raw)->not->toContain('segredo-do-webhook')
            ->and($this->setting->fresh()->webhook_secret)->toBe('segredo-do-webhook-da-clinica');

        $audit = DB::table('audit_logs')->where('auditable_id', $this->setting->id)->get();
        expect($audit)->not->toBeEmpty()
            ->and($audit->pluck('new_values')->implode(' '))->not->toContain('webhook_token')
            ->and($audit->pluck('new_values')->implode(' '))->not->toContain('webhook_secret')
            ->and($audit->pluck('new_values')->implode(' '))->not->toContain('test-webhook-token');
    });

    it('[SEGURANÇA] clínica NÃO tem rota de configuração de WhatsApp', function () {
        expect(Route::has('panel.setting.whatsapp.index'))->toBeFalse()
            ->and(Route::has('panel.setting.whatsapp.update'))->toBeFalse()
            ->and(Route::has('whatsapp.webhooks'))->toBeFalse(); // webhook da Z-API removido
    });

    it('[SEGURANÇA] staff do SaaS que não é Admin (Support/Financial) não configura nem testa', function () use ($toggles) {
        actingAsSaas($this, SaasRule::Support->value);

        expect(fn () => callManager('update', $toggles, $this->entity))->toThrow(AuthorizationException::class)
            ->and(fn () => callManager('test', [], $this->entity))->toThrow(AuthorizationException::class)
            ->and(fn () => callManager('updateGlobal', ['active' => true]))->toThrow(AuthorizationException::class);

        actingAsSaas($this, SaasRule::Financial->value);
        expect(fn () => callManager('testGlobal', []))->toThrow(AuthorizationException::class);
    });

    it('admin associa o app próprio da clínica: webhook registrado, segredo novo, nada de segredo na resposta nem na auditoria', function () use ($toggles) {
        actingAsSaas($this, SaasRule::Admin->value);
        $before = $this->setting->webhook_secret;

        $response = callManager('update', [...$toggles, 'confirmation_hours_before' => 48, 'survey_enabled' => false, 'app_id' => 'nova-app-0001'], $this->entity);

        expect($response->getStatusCode())->toBe(200);

        $body     = $response->getData(true);
        $settings = WhatsAppSetting::query()->where('entity_id', $this->entity->id)->first();

        expect($body['has_app'])->toBeTrue()
            ->and($body['webhook_ok'])->toBeTrue()
            ->and($body['app_id'])->toBe('nova-app-0001')
            ->and(json_encode($body))->not->toContain((string) $settings->webhook_secret)
            ->and($settings->confirmation_hours_before)->toBe(48)
            ->and($settings->survey_enabled)->toBeFalse()
            ->and($settings->webhook_secret)->not->toBe($before)
            ->and($settings->webhook_subscribed_at)->not->toBeNull();

        $audit = DB::table('audit_logs')->where('event', 'manager.whatsapp_settings.updated')->sole();
        expect($audit->new_values)->toContain('nova-app-0001')
            ->and($audit->new_values)->not->toContain((string) $settings->webhook_secret);
    });

    it('um app atende uma configuração só (validação)', function () use ($toggles) {
        actingAsSaas($this, SaasRule::Admin->value);
        $other = Entity::factory()->create(['is_client' => true]);

        expect(fn () => callManager('update', [...$toggles, 'app_id' => 'clinic-app-1'], $other))
            ->toThrow(ValidationException::class);
    });

    it('[SEGURANÇA] update em entity que não é clínica retorna 404', function () use ($toggles) {
        actingAsSaas($this, SaasRule::Admin->value);
        $saasEntity = Entity::query()->where('is_client', false)->firstOrFail();

        expect(fn () => callManager('update', $toggles, $saasEntity))->toThrow(NotFoundHttpException::class);
    });

    it('verificar app aceita App ID digitado ANTES de salvar, sem persistir nada', function () {
        actingAsSaas($this, SaasRule::Admin->value);
        $virgin = Entity::factory()->create(['is_client' => true]);

        $response = callManager('test', ['app_id' => 'adhoc-app'], $virgin);

        expect($response->getStatusCode())->toBe(200)
            ->and($response->getData(true))->toBe(['ok' => true, 'healthy' => true])
            ->and(WhatsAppSetting::query()->where('entity_id', $virgin->id)->exists())->toBeFalse();
    });

    it('verificar sem app digitado, próprio nem global retorna 422; App ID inválido falha na validação', function () {
        actingAsSaas($this, SaasRule::Admin->value);
        $virgin = Entity::factory()->create(['is_client' => true]);

        expect(callManager('test', [], $virgin)->getStatusCode())->toBe(422)
            ->and(fn () => callManager('test', ['app_id' => 'app com espaço'], $virgin))->toThrow(ValidationException::class);
    });

    it('mensagem de teste usa o template de teste; celular inválido → 422', function () {
        actingAsSaas($this, SaasRule::Admin->value);

        expect(callManager('test', ['phone' => '(61) 99999-8888'], $this->entity)->getData(true))->toBe(['ok' => true, 'sent' => true])
            ->and(callManager('test', ['phone' => '12'], $this->entity)->getStatusCode())->toBe(422);
    });

    it('clear_app remove o app próprio (clínica volta para o número do EasyEye)', function () use ($toggles) {
        actingAsSaas($this, SaasRule::Admin->value);

        $response = callManager('update', [...$toggles, 'clear_app' => true], $this->entity);

        expect($response->getStatusCode())->toBe(200)
            ->and($response->getData(true)['has_app'])->toBeFalse();

        $setting = $this->setting->fresh();
        expect($setting->hasApp())->toBeFalse()
            ->and($setting->webhook_secret)->toBeNull();
    });

    it('index: driver vazio também mostra o aviso de simulação; nada de segredo nas props', function () {
        actingAsSaas($this, SaasRule::Admin->value);
        config(['whatsapp.driver' => '']);

        $response = $this->get(route('manager.whatsapp.index'));
        $response->assertOk();

        $props = $response->viewData('page')['props'];
        expect($props['simulated'])->toBeTrue()
            ->and($props['driver'])->toBe('mock')
            ->and($props['partner']['configured'])->toBeFalse()
            ->and(json_encode($props))->not->toContain('segredo-do-webhook-da-clinica')
            ->and(collect($props['templates'])->pluck('name'))->toContain('easyeye_confirmacao_consulta');
    })->skip(fn () => ! Route::has('manager.whatsapp.index'), 'rota do manager ausente');
});

it('manager vê os modelos: texto para a Meta igual ao cadastro (§6 do doc), prévia, rodapé, botões e variáveis em pt_BR e en', function () {
    actingAsSaas($this, SaasRule::Admin->value);

    $props     = $this->get(route('manager.whatsapp.index'))->assertOk()->viewData('page')['props'];
    $templates = collect($props['templates'])->keyBy('key');
    $confirm   = $templates['appointment_confirmation'];

    expect(count($props['templates']))->toBe(count(config('whatsapp.templates')))
        ->and($props['templateLanguages'])->toBe(config('whatsapp.template_languages'))
        ->and($confirm['group'])->toBe('patients')
        ->and($confirm['texts']['pt_BR']['meta'])->toBe('Olá, {{1}}! Lembrete de {{2}}: sua consulta está marcada para {{3}} com {{4}}. Podemos confirmar sua presença?')
        ->and($confirm['texts']['pt_BR']['preview'])->toContain('Olá, Maria!')->not->toContain(':first_name')
        ->and($confirm['texts']['pt_BR']['footer'])->toBe('Para não receber mais avisos, responda SAIR.')
        ->and(array_column($confirm['texts']['pt_BR']['buttons'], 'label'))->toBe(['Confirmar', 'Cancelar'])
        ->and(array_column($confirm['texts']['en']['buttons'], 'label'))->toBe(['Confirm', 'Cancel'])
        ->and($confirm['texts']['pt_BR']['params'][2])->toMatchArray(['placeholder' => '{{3}}', 'key' => 'when'])
        ->and($templates['verification_code']['group'])->toBe('registration')
        ->and(array_column($templates['verification_code']['texts']['pt_BR']['buttons'], 'type'))->toBe(['otp'])
        ->and($templates['saas_dunning_reminder']['group'])->toBe('saas')
        ->and($templates['saas_dunning_reminder']['texts']['pt_BR']['meta'])->toBe('EasyEye: a assinatura de {{1}} ({{2}}) vence em {{3}}. Você pode pagar pelo painel, em Minha assinatura.')
        ->and($templates['saas_dunning_reminder']['texts']['pt_BR']['buttons'][0])->toBe(['type' => 'url', 'label' => 'Abrir o EasyEye']);

    // Nenhum ":variavel" sobrando em nenhum texto (toda variável mapeada no config).
    foreach ($props['templates'] as $tpl) {
        foreach ($tpl['texts'] as $text) {
            expect($text['meta'])->not->toMatch('/:[a-z_]+/')
                ->and($text['preview'])->not->toMatch('/:[a-z_]+/');
        }
    }
})->skip(fn () => ! Route::has('manager.whatsapp.index'), 'rota do manager ausente');

describe('app GLOBAL do EasyEye (padrão pra clínica sem número próprio)', function () {
    function createGlobalSetting(array $overrides = []): WhatsAppSetting
    {
        return WhatsAppSetting::create(array_merge([
            'entity_id'                 => null,
            'app_id'                    => 'global-app',
            'webhook_token'             => 'global-webhook-token-000000000000000000000000000',
            'webhook_secret'            => 'segredo-global',
            'active'                    => true,
            'confirmation_enabled'      => false,
            'survey_enabled'            => false,
            'confirmation_hours_before' => 24,
            'survey_delay_hours'        => 2,
        ], $overrides));
    }

    /** Clínica SEM app próprio (usa o global) + consulta agendada. */
    function createClinicUsingGlobal(): array
    {
        $entity  = Entity::factory()->create(['is_client' => true, 'name' => 'CLINICA SEM NUMERO']);
        $setting = WhatsAppSetting::create([
            'entity_id'                 => $entity->id,
            'app_id'                    => null,
            'webhook_token'             => 'clinic-no-app-token-00000000000000000000000000',
            'active'                    => true,
            'confirmation_enabled'      => true,
            'confirmation_hours_before' => 24,
            'survey_enabled'            => true,
            'survey_delay_hours'        => 0,
        ]);
        $doctor   = createDoctorForEntity($entity);
        $schedule = Schedule::query()->create([
            'entity_id'          => $entity->id,
            'doctor_id'          => $doctor->id,
            'full_name'          => 'JOANA GLOBAL',
            'cellphone'          => '61988887777',
            'cellphone_whatsapp' => true,
            'date_time'          => now()->addHours(5),
            'situation'          => ScheduleSituation::Scheduled->value,
            'active'             => true,
        ]);

        return [$entity, $setting, $schedule];
    }

    it('precedência: clínica sem app próprio envia pelo global; com app próprio, pelo dela', function () {
        $global                        = createGlobalSetting();
        [$entity, $setting, $schedule] = createClinicUsingGlobal();

        $this->artisan('whatsapp:send-confirmations')->assertSuccessful();

        $viaGlobal = WhatsAppMessage::query()->where('schedule_id', $schedule->id)->sole();
        $viaOwn    = WhatsAppMessage::query()->where('schedule_id', $this->schedule->id)->sole();

        expect($viaGlobal->status)->toBe('sent')
            ->and($viaGlobal->whatsapp_setting_id)->toBe($global->id)
            ->and($viaGlobal->sender_app_id)->toBe('global-app')
            ->and($viaGlobal->entity_id)->toBe($entity->id)
            ->and($viaOwn->whatsapp_setting_id)->toBe($this->setting->id)
            ->and($viaOwn->sender_app_id)->toBe('clinic-app-1')
            ->and($setting->sendingSetting()->id)->toBe($global->id);
    });

    it('sem global e sem app próprio, o comando nem enfileira', function () {
        [, , $schedule] = createClinicUsingGlobal();

        $this->artisan('whatsapp:send-confirmations')->assertSuccessful();

        expect(WhatsAppMessage::query()->where('schedule_id', $schedule->id)->exists())->toBeFalse();
    });

    it('webhook GLOBAL casa a resposta com a clínica certa pelo payload e confirma a consulta', function () {
        $global                = createGlobalSetting();
        [$entity, , $schedule] = createClinicUsingGlobal();

        $this->artisan('whatsapp:send-confirmations')->assertSuccessful();
        $out = WhatsAppMessage::query()->where('schedule_id', $schedule->id)->sole();

        postGupshup($this, $global, inboundButton("confirm:{$out->id}", 'Confirmar', from: '5561988887777'))->assertOk();

        expect($schedule->fresh()->situation)->toBe(ScheduleSituation::Confirmed);

        // Inbound ganhou o entity_id da clínica casada (trilha por tenant).
        $inbound = WhatsAppMessage::query()->where('direction', 'in')->sole();
        expect($inbound->entity_id)->toBe($entity->id)
            ->and($inbound->whatsapp_setting_id)->toBe($global->id);
    });

    it('[SEGURANÇA] webhook global NÃO casa resposta de mensagem que saiu pelo número da clínica', function () {
        $global = createGlobalSetting();
        $out    = sentConfirmation($this); // saiu pelo app próprio da clínica

        postGupshup($this, $global, inboundButton("confirm:{$out->id}", 'Confirmar'))->assertOk();
        postGupshup($this, $global, inboundText('1'))->assertOk();

        expect($this->schedule->fresh()->situation)->toBe(ScheduleSituation::Scheduled);
    });

    it('updateGlobal associa o app global (singleton), registra o webhook e não devolve segredo', function () {
        actingAsSaas($this, SaasRule::Admin->value);

        $response = callManager('updateGlobal', ['active' => true, 'app_id' => 'global-new']);

        expect($response->getStatusCode())->toBe(200)
            ->and($response->getData(true)['webhook_ok'])->toBeTrue();

        $global = WhatsAppSetting::globalSetting();
        expect($global->app_id)->toBe('global-new')
            ->and($global->webhook_secret)->not->toBeEmpty()
            ->and(json_encode($response->getData(true)))->not->toContain((string) $global->webhook_secret);

        callManager('updateGlobal', ['active' => false]);
        expect(WhatsAppSetting::query()->whereNull('entity_id')->count())->toBe(1)
            ->and(WhatsAppSetting::globalSetting()->active)->toBeFalse()
            ->and(WhatsAppSetting::globalSetting()->app_id)->toBe('global-new');
    });
});

describe('migration Z-API → Gupshup', function () {
    it('preserva zapi_message_id em provider_message_id, apaga credenciais Z-API, troca o token e limpa a auditoria', function () {
        $migration = require database_path('migrations/2026_10_08_000000_migrate_whatsapp_to_gupshup.php');
        $migration->down();

        $settingId = (string) Str::uuid();
        DB::table('whatsapp_settings')->insert([
            'id'            => $settingId, 'entity_id' => null, 'credentials' => encrypt(['instance_token' => 'TOKEN-VELHO']), 'instance_id' => 'INST',
            'webhook_token' => 'token-velho-vazado', 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('whatsapp_messages')->insert([
            'id'   => (string) Str::uuid(), 'direction' => 'in', 'kind' => 'reply', 'phone' => '5561999998888',
            'body' => '1', 'status' => 'received', 'zapi_message_id' => 'ZAPI-123', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('audit_logs')->insert([
            'id'    => (string) Str::uuid(), 'auditable_type' => WhatsAppSetting::class, 'auditable_id' => $settingId,
            'event' => 'created', 'new_values' => json_encode(['webhook_token' => 'token-velho-vazado', 'active' => true]), 'created_at' => now(),
        ]);

        $migration->up();

        expect(DB::table('whatsapp_messages')->where('provider_message_id', 'ZAPI-123')->exists())->toBeTrue()
            ->and(Schema::hasColumn('whatsapp_settings', 'credentials'))->toBeFalse()
            ->and(DB::table('whatsapp_settings')->where('id', $settingId)->value('webhook_token'))->not->toBe('token-velho-vazado')
            ->and(DB::table('audit_logs')->where('auditable_id', $settingId)->value('new_values'))->not->toContain('token-velho-vazado')
            ->and(DB::table('audit_logs')->where('auditable_id', $settingId)->value('new_values'))->toContain('active');
    });
});
