<?php

declare(strict_types=1);

/**
 * EE-14 (auditoria de encerramento 11/10/2026): actions.report_settings.* —
 * usado pelas mensagens flash do manager (app/Http/Controllers/Manager/
 * ReportSettingsController.php) e pelo rótulo do menu (PanelNavigation.php)
 * — tinha 34 chaves faltando em lang/en/actions.php desde o EE-06 (nunca
 * corrigido até agora). Sem a chave, __() devolve a chave literal
 * ("actions.report_settings.saved") em vez de texto em inglês.
 */

use App\Enums\DocumentationType;
use App\Models\{Entity, User};

function reportSettingsLocalizationSaasAdminSession(Entity $saas): array
{
    return [
        'selected_entity_id'        => $saas->id,
        'selected_entity_is_client' => false,
        'selected_entity_user_rule' => 'admin',
    ];
}

beforeEach(function () {
    $this->saas  = Entity::factory()->create(['is_client' => false, 'active' => true]);
    $this->admin = User::factory()->create();
    createEntityUser($this->saas, $this->admin, 'admin');
});

it('EE-14: criar modelo global (manager) devolve mensagem traduzida, pt_BR por padrão e en com locale', function () {
    $payload = [
        'title'    => 'Modelo de teste',
        'contents' => [['type' => DocumentationType::Report->value, 'label' => 'Padrão', 'content' => '<p>x</p>']],
    ];

    $this->actingAs($this->admin)
        ->withSession(reportSettingsLocalizationSaasAdminSession($this->saas))
        ->postJson(route('manager.report-settings.store'), $payload)
        ->assertOk()
        ->assertJson(['message' => 'Modelo salvo com sucesso.']);

    $this->actingAs($this->admin)
        ->withSession(reportSettingsLocalizationSaasAdminSession($this->saas) + ['locale' => 'en'])
        ->postJson(route('manager.report-settings.store'), $payload)
        ->assertOk()
        ->assertJson(['message' => 'Template saved successfully.']);
});
