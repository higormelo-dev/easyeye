<?php

declare(strict_types=1);

/**
 * A conversa do convite de retorno pelo WhatsApp (ADR 0002, item 9): os botões "Quero agendar" / "Já agendei", o texto
 * livre que NÃO decide nada, e o SAIR — que descadastra o número E retira a autorização de lembretes (VOLTAR não a
 * devolve). Driver mock; o webhook é o de verdade (Gupshup v3), a fila é síncrona.
 */

use App\Domains\Recall\Enums\InviteOrigin;
use App\Domains\Recall\Models\PatientRecallEvent;
use App\Domains\Recall\Services\{RecallInviteGuard, RecallInviteService};
use App\Enums\ConsentType;
use App\Jobs\WhatsApp\ProcessWhatsAppStatusJob;
use App\Models\{PatientConsent, User};
use App\Models\WhatsApp\{WhatsAppMessage, WhatsAppOptOut, WhatsAppSetting};
use App\Services\ConsentService;
use App\Services\WhatsApp\WhatsAppService;
use Carbon\{Carbon, CarbonImmutable};
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\{DB, Notification};
use Illuminate\Support\Str;

beforeEach(function () {
    Notification::fake();
    $this->travelTo(Carbon::parse('2026-02-01 10:00:00'));

    ['entity' => $this->entity, 'doctor' => $this->doctor] = rcfClinic(recall: true);

    $this->sender  = rcfInviteChannel($this->entity);
    $this->patient = rcfInvitePatient($this->entity);
    $this->recall  = rcfOverdueRecall($this->entity, $this->patient, $this->doctor);
    $this->now     = CarbonImmutable::parse('2026-02-01 10:00:00');

    // Fila síncrona + driver mock: o convite já nasce ENVIADO.
    $this->invite  = app(RecallInviteService::class)->inviteManual((string) $this->entity->id, (string) $this->recall->id, null, $this->now);
    $this->message = WhatsAppMessage::query()->where('recall_invite_id', $this->invite->id)->where('direction', 'out')->sole();
});

/** O que o paciente manda ao número pelo webhook v3 da Gupshup. */
function riwPost(object $test, array $message, ?WhatsAppSetting $setting = null): void
{
    $setting ??= $test->sender;

    $test->withHeaders([WhatsAppSetting::WEBHOOK_SECRET_HEADER => (string) $setting->webhook_secret])
        ->postJson('/api/whatsapp/gupshup/webhook/' . $setting->webhook_token, [
            'gs_app_id' => $setting->app_id,
            'object'    => 'whatsapp_business_account',
            'entry'     => [[
                'id'      => 'WABA-1',
                'changes' => [['field' => 'messages', 'value' => [
                    'messaging_product' => 'whatsapp',
                    'contacts'          => [['profile' => ['name' => 'Maria'], 'wa_id' => $message['from']]],
                    'messages'          => [$message + ['id' => 'wamid.' . uniqid(), 'timestamp' => (string) now()->timestamp]],
                ]]],
            ]],
        ])
        ->assertOk();
}

function riwButton(object $test, string $payload, string $text, string $from = '5511988887777'): void
{
    riwPost($test, ['from' => $from, 'type' => 'button', 'button' => ['payload' => $payload, 'text' => $text]]);
}

function riwText(object $test, string $text, string $from = '5511988887777'): void
{
    riwPost($test, ['from' => $from, 'type' => 'text', 'text' => ['body' => $text]]);
}

/** A resposta automática que o sistema mandou (a última). */
function riwAck(): ?string
{
    return WhatsAppMessage::query()->where('kind', 'ack')->latest('created_at')->latest('id')->value('body');
}

// ── botões ──────────────────────────────────────────────────────────────────

it('"Quero agendar": a onda guarda a resposta, a mensagem fica respondida e cada retorno ganha o evento', function () {
    riwButton($this, "recall_book:{$this->message->id}", 'Quero agendar');

    $invite = $this->invite->fresh();
    $event  = PatientRecallEvent::query()->withoutGlobalScopes()->where('recall_id', $this->recall->id)->where('event', 'invite_responded')->sole();

    expect($invite->response?->value)->toBe('wants_to_schedule')
        ->and($invite->responded_at)->not->toBeNull()
        ->and($this->message->fresh()->status)->toBe('answered')
        ->and($this->message->fresh()->answered_at)->not->toBeNull()
        ->and($event->actor_label)->toBe('patient')
        ->and($event->meta)->toBe(['invite_id' => (string) $this->invite->id, 'response' => 'wants_to_schedule'])
        ->and(riwAck())->toBe(trans('whatsapp.patient.recall_book', [], 'pt_BR'));

    // O retorno em si não mudou: quem agenda é a equipe.
    expect(DB::table('patient_recalls')->where('id', $this->recall->id)->value('lifecycle'))->toBe('open');
});

it('"Já agendei": registra a resposta diferente, e tocar o mesmo botão de novo não repete o evento', function () {
    riwButton($this, "recall_done:{$this->message->id}", 'Já agendei');
    riwButton($this, "recall_done:{$this->message->id}", 'Já agendei');

    expect($this->invite->fresh()->response?->value)->toBe('already_scheduled')
        ->and(PatientRecallEvent::query()->withoutGlobalScopes()->where('event', 'invite_responded')->count())->toBe(1)
        ->and(riwAck())->toBe(trans('whatsapp.patient.recall_done', [], 'pt_BR'));

    riwButton($this, "recall_book:{$this->message->id}", 'Quero agendar');

    expect($this->invite->fresh()->response?->value)->toBe('wants_to_schedule')
        ->and(PatientRecallEvent::query()->withoutGlobalScopes()->where('event', 'invite_responded')->count())->toBe(2);
});

it('o botão de OUTRO número, ou com o id de uma mensagem que não existe, não faz nada e orienta', function () {
    riwButton($this, "recall_book:{$this->message->id}", 'Quero agendar', from: '5511999990000');

    expect($this->invite->fresh()->response)->toBeNull()
        ->and(riwAck())->toBe(trans('whatsapp.patient.use_buttons', [], 'pt_BR'));

    riwButton($this, 'recall_book:' . Str::uuid(), 'Quero agendar');

    expect($this->invite->fresh()->response)->toBeNull();
});

it('botão de confirmação/pesquisa apontando para o convite (ou o contrário) não vale', function () {
    riwButton($this, "confirm:{$this->message->id}", 'Confirmar');
    riwButton($this, "survey:{$this->message->id}:5", '5 - Excelente');

    expect($this->invite->fresh()->response)->toBeNull()
        ->and($this->message->fresh()->status)->toBe('sent');
});

it('o botão só vale por 30 dias depois do envio: depois, o clique é ignorado em silêncio', function () {
    DB::table('whatsapp_messages')->where('id', $this->message->id)->update(['sent_at' => now()->subDays(31)]);
    $acks = WhatsAppMessage::query()->where('kind', 'ack')->count();

    riwButton($this, "recall_book:{$this->message->id}", 'Quero agendar');

    expect($this->invite->fresh()->response)->toBeNull()
        ->and(WhatsAppMessage::query()->where('kind', 'ack')->count())->toBe($acks);
});

it('texto livre ("sim", "1") ou citação do convite não decide nada: só orienta a tocar nos botões', function () {
    riwText($this, 'sim');

    expect($this->invite->fresh()->response)->toBeNull()
        ->and($this->message->fresh()->status)->toBe('sent')
        ->and(riwAck())->toBeNull(); // o convite não é candidata de texto livre (só confirmação/pesquisa são): silêncio

    $this->message->update(['wa_message_id' => 'wamid.convite']);

    riwPost($this, ['from' => '5511988887777', 'type' => 'text', 'text' => ['body' => 'quero sim'], 'context' => ['from' => '551133334444', 'id' => 'wamid.convite']]);

    expect($this->invite->fresh()->response)->toBeNull()
        ->and(riwAck())->toBe(trans('whatsapp.patient.recall_use_buttons', [], 'pt_BR'));
});

// ── SAIR / VOLTAR ───────────────────────────────────────────────────────────

it('SAIR descadastra o número E retira a autorização de lembretes; VOLTAR reativa o número, não a autorização', function () {
    $other = rcfInvitePatient($this->entity, 'OUTRA PESSOA', '11977770000'); // celular diferente: não é afetada

    riwText($this, 'SAIR');

    $consent = PatientConsent::query()->withoutGlobalScopes()->where('patient_id', $this->patient->id)->sole();

    expect(WhatsAppOptOut::query()->where('whatsapp_setting_id', $this->sender->id)->where('phone', '5511988887777')->exists())->toBeTrue()
        ->and($consent->status)->toBe('revoked')
        ->and($consent->revoked_at)->not->toBeNull()
        ->and($consent->revoked_by)->toBeNull()
        ->and($consent->revocation_reason)->toBe(trans('consent.revoked.whatsapp_opt_out', [], 'pt_BR'))
        ->and(app(ConsentService::class)->hasActiveConsent($other, ConsentType::CareReminders))->toBeTrue();

    riwText($this, 'VOLTAR');

    $guard = app(RecallInviteGuard::class);

    expect(WhatsAppOptOut::query()->where('phone', '5511988887777')->exists())->toBeFalse()
        ->and($guard->hasConsent((string) $this->entity->id, (string) $this->patient->id))->toBeFalse()
        ->and(array_map(fn ($b) => $b->value, $guard->decide((string) $this->entity->id, (string) $this->patient->id, InviteOrigin::Manual, CarbonImmutable::now()->addDays(40))->blockers))->toContain('no_consent');
});

it('o SAIR no app PRÓPRIO da clínica só retira a autorização dos pacientes dela', function () {
    ['entity' => $otherClinic] = rcfClinic(recall: true);
    $otherSender               = rcfInviteChannel($otherClinic);
    $otherPatient              = rcfInvitePatient($otherClinic, 'MESMO CELULAR', '11988887777'); // mesmo número em OUTRA clínica

    riwText($this, 'SAIR');

    expect(app(ConsentService::class)->hasActiveConsent($this->patient, ConsentType::CareReminders))->toBeFalse()
        ->and(PatientConsent::query()->withoutGlobalScopes()->where('patient_id', $otherPatient->id)->where('status', 'granted')->exists())->toBeTrue()
        ->and($otherSender->id)->not->toBe($this->sender->id);
});

it('o SAIR no número GLOBAL do EasyEye retira a autorização só das clínicas que enviam por ele, não das que usam app próprio', function () {
    $global = WhatsAppSetting::create([
        'entity_id' => null, 'app_id' => 'global-app', 'webhook_token' => Str::random(48), 'webhook_secret' => 'segredo-global',
        'active'    => true, 'confirmation_enabled' => false, 'survey_enabled' => false,
    ]);

    // A clínica do cenário-base tem app PRÓPRIO (`$this->sender`): o paciente dela não escreveu para o número dela.
    ['entity' => $viaGlobal] = rcfClinic(recall: true);
    WhatsAppSetting::create([
        'entity_id' => $viaGlobal->id, 'app_id' => null, 'webhook_token' => Str::random(48), 'webhook_secret' => 'segredo-sem-app',
        'active'    => true, 'confirmation_enabled' => false, 'survey_enabled' => false, 'recall_enabled' => true,
    ]);
    $viaGlobalPatient = rcfInvitePatient($viaGlobal, 'MESMO CELULAR', '11988887777');

    riwPost($this, ['from' => '5511988887777', 'type' => 'text', 'text' => ['body' => 'parar']], $global);

    expect(WhatsAppOptOut::query()->where('whatsapp_setting_id', $global->id)->where('phone', '5511988887777')->exists())->toBeTrue()
        ->and(app(ConsentService::class)->hasActiveConsent($viaGlobalPatient, ConsentType::CareReminders))->toBeFalse()
        ->and(app(ConsentService::class)->hasActiveConsent($this->patient, ConsentType::CareReminders))->toBeTrue();
});

it('o descadastro vindo do provedor (Meta) também retira a autorização, com o motivo próprio', function () {
    app(WhatsAppService::class)->optOut($this->sender, '5511988887777', WhatsAppOptOut::SOURCE_PROVIDER);

    $consent = PatientConsent::query()->withoutGlobalScopes()->where('patient_id', $this->patient->id)->sole();

    expect($consent->status)->toBe('revoked')
        ->and($consent->revocation_reason)->toBe(trans('consent.revoked.provider_opt_out', [], 'pt_BR'));
});

it('o SAIR não toca em outros tipos de consentimento do mesmo paciente', function () {
    app(ConsentService::class)->grantRequired($this->patient, Auth::user() ?? createEntityUser($this->entity, User::factory()->create(), 'admin'));

    riwText($this, 'SAIR');

    expect(PatientConsent::query()->withoutGlobalScopes()->where('patient_id', $this->patient->id)->where('consent_type', 'data_collection')->where('status', 'granted')->exists())->toBeTrue()
        ->and(PatientConsent::query()->withoutGlobalScopes()->where('patient_id', $this->patient->id)->where('consent_type', 'sensitive_data')->where('status', 'granted')->exists())->toBeTrue();
});

// ── o convite que já saiu e o que o painel mostra ───────────────────────────

it('falha transitória do WhatsApp reportada pelo status marca o convite como pulado (não conta nos tetos)', function () {
    DB::table('whatsapp_messages')->where('id', $this->message->id)->update(['status' => 'sent', 'provider_message_id' => 'gs-recall-1']);

    $job = new ProcessWhatsAppStatusJob((string) $this->sender->id, [
        'gs_id' => 'gs-recall-1', 'id' => null, 'meta_msg_id' => null, 'status' => 'failed', 'timestamp' => (string) now()->timestamp, 'code' => 1003, 'reason' => 'saldo',
    ]);
    app()->call([$job, 'handle']);

    expect($this->message->fresh()->status)->toBe('skipped');
});
