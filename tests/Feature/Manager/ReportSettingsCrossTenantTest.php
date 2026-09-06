<?php

/**
 * BUGFIX (revisao de seguranca): ReportSettingsController (Manager) só deve
 * gerenciar templates GLOBAIS (entity_id = null). show()/update()/destroy()/
 * preview() faziam route-model binding direto em {report_setting} sem checar
 * isGlobal(), permitindo a um admin SaaS ler/sobrescrever/apagar o template
 * privado de uma clínica específica caso descobrisse o UUID.
 */

use App\Enums\ReportSettingStatus;
use App\Models\{Entity, ReportSetting, User};

/** Sessão mínima para atravessar entity.selected + saas.admin + saas.role nas rotas reais do manager. */
function reportSettingsSaasAdminSession(Entity $saas): array
{
    return [
        'selected_entity_id'        => $saas->id,
        'selected_entity_is_client' => false,
        'selected_entity_user_rule' => 'admin',
    ];
}

function makeReportSetting(?string $entityId, array $overrides = []): ReportSetting
{
    return ReportSetting::create(array_merge([
        'entity_id' => $entityId,
        'title'     => fake()->sentence(3),
        'status'    => ReportSettingStatus::Draft,
    ], $overrides));
}

beforeEach(function () {
    $this->saas  = Entity::factory()->create(['is_client' => false, 'active' => true]);
    $this->admin = User::factory()->create();
    createEntityUser($this->saas, $this->admin, 'admin');

    $this->clinic = Entity::factory()->create(['is_client' => true, 'active' => true]);
});

test('manager não consegue exibir (show) template privado de uma clínica', function () {
    $private = makeReportSetting($this->clinic->id);

    $this->actingAs($this->admin)
        ->withSession(reportSettingsSaasAdminSession($this->saas))
        ->getJson(route('manager.report-settings.show', $private))
        ->assertNotFound();
});

test('manager não consegue atualizar (update) template privado de uma clínica', function () {
    $private = makeReportSetting($this->clinic->id, ['title' => 'Original']);

    $this->actingAs($this->admin)
        ->withSession(reportSettingsSaasAdminSession($this->saas))
        ->putJson(route('manager.report-settings.update', $private), ['title' => 'Hackeado'])
        ->assertNotFound();

    expect($private->fresh()->title)->toBe('Original');
});

test('manager não consegue apagar (destroy) template privado de uma clínica', function () {
    $private = makeReportSetting($this->clinic->id);

    $this->actingAs($this->admin)
        ->withSession(reportSettingsSaasAdminSession($this->saas))
        ->deleteJson(route('manager.report-settings.destroy', $private), [
            'reason' => 'Tentativa de exclusão indevida de template privado.',
        ])
        ->assertNotFound();

    expect(ReportSetting::find($private->id))->not->toBeNull();
});

test('manager não consegue pré-visualizar (preview) template privado de uma clínica', function () {
    $private = makeReportSetting($this->clinic->id);

    $this->actingAs($this->admin)
        ->withSession(reportSettingsSaasAdminSession($this->saas))
        ->get(route('manager.report-settings.preview', $private))
        ->assertNotFound();
});

test('manager continua conseguindo show/update/destroy/preview em template GLOBAL', function () {
    $global = makeReportSetting(null, ['title' => 'Modelo Global']);

    $this->actingAs($this->admin)
        ->withSession(reportSettingsSaasAdminSession($this->saas))
        ->getJson(route('manager.report-settings.show', $global))
        ->assertOk();

    $this->actingAs($this->admin)
        ->withSession(reportSettingsSaasAdminSession($this->saas))
        ->putJson(route('manager.report-settings.update', $global), ['title' => 'Modelo Global Atualizado'])
        ->assertOk();

    expect($global->fresh()->title)->toBe('Modelo Global Atualizado');

    $this->actingAs($this->admin)
        ->withSession(reportSettingsSaasAdminSession($this->saas))
        ->get(route('manager.report-settings.preview', $global))
        ->assertOk();

    $this->actingAs($this->admin)
        ->withSession(reportSettingsSaasAdminSession($this->saas))
        ->deleteJson(route('manager.report-settings.destroy', $global), [
            'reason' => 'Limpeza de template global de teste, sem uso.',
        ])
        ->assertOk();

    expect(ReportSetting::find($global->id))->toBeNull();
});
