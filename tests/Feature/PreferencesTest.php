<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Models\{Entity, User, UserPreference};
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * PATCH /panel/preferences — bag de preferências pessoais (UserPreference).
 *
 * Foco: a chave nova `medical_record_layout` (prontuário personalizado por
 * médico — modo default + layout de seções). A rota vive no grupo panel
 * (auth+verified+entity.selected+2fa), então os testes usam a sessão padrão
 * de painel via panelSession().
 */
beforeEach(function () {
    $this->entity     = Entity::factory()->create(['is_client' => true]);
    $this->user       = User::factory()->create();
    $this->entityUser = createEntityUser($this->entity, $this->user, ClientRule::Doctor->value);
});

function patchPreferences($test, array $payload)
{
    return $test->actingAs($test->user)
        ->withSession(panelSession($test->entityUser))
        ->patchJson(route('panel.preferences.update'), $payload);
}

it('persiste medical_record_layout completo (modo default + layout custom)', function () {
    $layout = [
        'default_mode' => 'custom',
        'custom'       => [
            'left'   => ['dinamica', 'estatica', 'av_sem_tono', 'cromatica_ppc_cover'],
            'right'  => ['fundoscopia', 'biomicroscopia', 'adicao', 'av_com', 'obs_geral'],
            'hidden' => ['cromatica_ppc_cover'],
        ],
    ];

    patchPreferences($this, ['medical_record_layout' => $layout])
        ->assertOk()
        ->assertJsonPath('data.medical_record_layout.default_mode', 'custom')
        ->assertJsonPath('data.medical_record_layout.custom.left.0', 'dinamica')
        ->assertJsonPath('data.medical_record_layout.custom.hidden.0', 'cromatica_ppc_cover');

    expect(UserPreference::valueFor($this->user->fresh(), 'medical_record_layout')['default_mode'])->toBe('custom');
});

it('aceita só o modo default sem layout custom (ex.: médico escolhe "texto livre" como padrão)', function () {
    patchPreferences($this, ['medical_record_layout' => ['default_mode' => 'free']])
        ->assertOk()
        ->assertJsonPath('data.medical_record_layout.default_mode', 'free');
});

it('rejeita modo inválido (422)', function () {
    patchPreferences($this, ['medical_record_layout' => ['default_mode' => 'hackermode']])
        ->assertUnprocessable();
});

it('rejeita chave de seção desconhecida no layout (422) — client não grava chave arbitrária', function () {
    patchPreferences($this, ['medical_record_layout' => [
        'default_mode' => 'custom',
        'custom'       => ['left' => ['secao_que_nao_existe']],
    ]])->assertUnprocessable();
});

it('merge parcial preserva as outras preferências do bag', function () {
    UserPreference::mergeFor($this->user, ['dashboard_widget_order' => ['a', 'b']]);

    patchPreferences($this, ['medical_record_layout' => ['default_mode' => 'free']])
        ->assertOk()
        ->assertJsonPath('data.dashboard_widget_order.0', 'a')
        ->assertJsonPath('data.medical_record_layout.default_mode', 'free');
});
