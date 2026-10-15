<?php

declare(strict_types=1);

/**
 * Manager → WhatsApp: as chaves do convite de retorno (ADR 0002, item 9). A chave da CLÍNICA (`recall_enabled`,
 * nasce desligada) e a PAUSA GLOBAL (kill-switch do EasyEye, com motivo e "N clínicas e M retornos afetados"). Só o
 * Admin do SaaS mexe; tudo fica na auditoria.
 */

use App\Domains\Recall\Services\{RecallInviteGuard, RecallKillSwitch};
use App\Enums\SaasRule;
use App\Models\{AuditLog, Entity, User};
use App\Models\SubscriptionSetting;
use App\Models\WhatsApp\WhatsAppSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

beforeEach(function () {
    Notification::fake();
    config()->set('whatsapp.driver', 'mock');
    $this->travelTo(Carbon::parse('2026-02-01 10:00:00'));
});

function rimSaas(object $test, string $rule = SaasRule::Admin->value): User
{
    $saas = Entity::factory()->create(['is_client' => false, 'active' => true]);
    $user = User::factory()->create(['name' => 'Dono do SaaS']);
    createEntityUser($saas, $user, $rule);

    $test->actingAs($user);
    session(['selected_entity_id' => $saas->id]);

    return $user;
}

/** Clínica com o convite ligado nos dois níveis da clínica e um retorno vencido (para a pausa ter o que afetar). */
function rimClinic(object $test, bool $recallEnabled = true): array
{
    ['entity' => $entity, 'doctor' => $doctor] = rcfClinic(recall: true);
    $sender                                    = rcfInviteChannel($entity, [], ['recall_enabled' => $recallEnabled]);
    $patient                                   = rcfInvitePatient($entity);
    rcfOverdueRecall($entity, $patient, $doctor);

    return [$entity, $sender, $patient];
}

// ── a chave da clínica ──────────────────────────────────────────────────────

it('o Manager liga e desliga o convite de retorno da clínica; a linha de WhatsApp nasce com ele desligado', function () {
    rimSaas($this);
    $clinic = Entity::factory()->create(['is_client' => true, 'active' => true, 'name' => 'CLINICA RECALL']);

    $payload = ['active' => true, 'confirmation_enabled' => false, 'confirmation_hours_before' => 24, 'survey_enabled' => false, 'survey_delay_hours' => 2];

    $this->patchJson(route('manager.whatsapp.update', $clinic->id), $payload)->assertOk();

    $setting = WhatsAppSetting::query()->where('entity_id', $clinic->id)->sole();
    expect($setting->recall_enabled)->toBeFalse();

    $this->patchJson(route('manager.whatsapp.update', $clinic->id), $payload + ['recall_enabled' => true])->assertOk();
    expect($setting->fresh()->recall_enabled)->toBeTrue();

    // Quem não manda o campo (formulário antigo) não desliga sem querer.
    $this->patchJson(route('manager.whatsapp.update', $clinic->id), $payload)->assertOk();
    expect($setting->fresh()->recall_enabled)->toBeTrue();

    $this->patchJson(route('manager.whatsapp.update', $clinic->id), $payload + ['recall_enabled' => false])->assertOk();
    expect($setting->fresh()->recall_enabled)->toBeFalse();

    expect(AuditLog::query()->where('event', 'manager.whatsapp_settings.updated')->count())->toBeGreaterThanOrEqual(3);
});

it('a linha GLOBAL do EasyEye nunca liga o convite de retorno', function () {
    rimSaas($this);

    $this->patchJson(route('manager.whatsapp.global.update'), ['active' => true, 'app_id' => 'global-recall-app'])->assertOk();

    expect(WhatsAppSetting::globalSetting()->recall_enabled)->toBeFalse();
});

it('o Manager mostra a chave e os números de convite de cada clínica', function () {
    [$entity] = rimClinic($this);
    rimSaas($this);

    $props = $this->get(route('manager.whatsapp.index', ['search' => $entity->name]))->assertOk()->viewData('page')['props'];
    $row   = collect($props['clinics']['data'])->firstWhere('id', (string) $entity->id);

    expect($row['setting']['recall_enabled'])->toBeTrue()
        ->and($row['stats'])->toMatchArray(['recall_sent' => 0, 'recall_answered' => 0])
        ->and($props['recall'])->toBe(['paused' => null, 'impact' => ['clinics' => 1, 'recalls' => 1]])
        ->and($props['routes'])->toMatchArray(['recall_pause' => route('manager.whatsapp.recall.pause'), 'recall_resume' => route('manager.whatsapp.recall.resume')]);
});

it('a clínica sem a chave do Manager não entra no impacto da pausa', function () {
    rimClinic($this, recallEnabled: false);
    rimSaas($this);

    expect(app(RecallKillSwitch::class)->impact())->toBe(['clinics' => 0, 'recalls' => 0]);
});

// ── a pausa global ──────────────────────────────────────────────────────────

it('pausar exige motivo, registra quem e quando, mostra o impacto e fica na auditoria', function () {
    [$entity, , $patient] = rimClinic($this);
    $user                 = rimSaas($this);

    $this->postJson(route('manager.whatsapp.recall.pause'), ['reason' => 'curto'])->assertStatus(422)->assertJsonValidationErrors('reason');
    expect(app(RecallKillSwitch::class)->isPaused())->toBeFalse();

    $this->postJson(route('manager.whatsapp.recall.pause'), ['reason' => 'Número de WhatsApp queimado na Meta'])
        ->assertOk()
        ->assertJsonPath('impact', ['clinics' => 1, 'recalls' => 1])
        ->assertJsonPath('paused.reason', 'Número de WhatsApp queimado na Meta')
        ->assertJsonPath('paused.by', (string) $user->id)
        ->assertJsonPath('paused.by_name', $user->name);

    $state = app(RecallKillSwitch::class)->state();

    expect($state['reason'])->toBe('Número de WhatsApp queimado na Meta')
        ->and($state['at'])->toBe('2026-02-01T10:00:00-03:00')
        ->and(AuditLog::query()->where('event', 'manager.whatsapp_recall.paused')->sole()->reason)->toBe('Número de WhatsApp queimado na Meta')
        ->and(app(RecallInviteGuard::class)->channelBlocker((string) $entity->id)?->value)->toBe('paused_global')
        ->and($patient)->not->toBeNull();
});

it('liberar remove a pausa e audita; cada clínica volta a depender das próprias chaves', function () {
    [$entity] = rimClinic($this);
    rimSaas($this);

    $this->postJson(route('manager.whatsapp.recall.pause'), ['reason' => 'Modelo reprovado pela Meta'])->assertOk();
    $this->postJson(route('manager.whatsapp.recall.resume'))->assertOk()->assertJsonPath('paused', null);

    expect(app(RecallKillSwitch::class)->isPaused())->toBeFalse()
        ->and(app(RecallInviteGuard::class)->channelBlocker((string) $entity->id))->toBeNull()
        ->and(AuditLog::query()->where('event', 'manager.whatsapp_recall.resumed')->exists())->toBeTrue();
});

it('pausar duas vezes mantém uma só pausa (a última prevalece)', function () {
    rimSaas($this);

    $this->postJson(route('manager.whatsapp.recall.pause'), ['reason' => 'Primeiro motivo da pausa'])->assertOk();
    $this->postJson(route('manager.whatsapp.recall.pause'), ['reason' => 'Segundo motivo da pausa'])->assertOk();

    expect(SubscriptionSetting::query()->where('key', RecallKillSwitch::KEY)->count())->toBe(1)
        ->and(app(RecallKillSwitch::class)->state()['reason'])->toBe('Segundo motivo da pausa');
});

it('só o Admin do SaaS pausa/libera; suporte e financeiro do SaaS e clínica recebem 403', function (string $rule) {
    rimSaas($this, $rule);

    $this->postJson(route('manager.whatsapp.recall.pause'), ['reason' => 'Número queimado na Meta'])->assertForbidden();
    $this->postJson(route('manager.whatsapp.recall.resume'))->assertForbidden();

    expect(app(RecallKillSwitch::class)->isPaused())->toBeFalse();
})->with([SaasRule::Support->value, SaasRule::Financial->value]);

it('usuário de clínica não alcança as rotas do Manager', function () {
    ['entity' => $clinic] = rcfClinic(recall: true);
    $user                 = User::factory()->create();
    $eu                   = createEntityUser($clinic, $user, 'admin');

    $this->actingAs($user)->withSession(panelSession($eu))->postJson(route('manager.whatsapp.recall.pause'), ['reason' => 'Número queimado na Meta'])->assertForbidden();

    expect(app(RecallKillSwitch::class)->isPaused())->toBeFalse();
});

it('a pausa vale na hora para o envio já enfileirado (é lida sem cache)', function () {
    [$entity, , $patient] = rimClinic($this);
    $user                 = rimSaas($this);

    $guard = app(RecallInviteGuard::class);
    expect($guard->channelBlocker((string) $entity->id))->toBeNull();

    $this->postJson(route('manager.whatsapp.recall.pause'), ['reason' => 'Número de WhatsApp queimado na Meta'])->assertOk();

    // Mesma instância do guard, sem limpar nada: a pausa é vista na próxima consulta.
    expect($guard->channelBlocker((string) $entity->id)?->value)->toBe('paused_global')
        ->and($user)->not->toBeNull()
        ->and($patient)->not->toBeNull()
        ->and(Str::length(RecallKillSwitch::auditId()))->toBe(36);
});
