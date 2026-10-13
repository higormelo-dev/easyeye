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

it('aceita a seção Tonometria (separada de A/V sem correção) e A/V com correção na coluna esquerda', function () {
    $layout = [
        'default_mode' => 'custom',
        'custom'       => [
            'left'   => ['cromatica_ppc_cover', 'av_sem_tono', 'av_com', 'tonometria', 'dinamica', 'estatica'],
            'right'  => ['adicao', 'biomicroscopia', 'fundoscopia', 'obs_geral'],
            'hidden' => ['tonometria'],
        ],
    ];

    patchPreferences($this, ['medical_record_layout' => $layout])
        ->assertOk()
        ->assertJsonPath('data.medical_record_layout.custom.left.3', 'tonometria')
        ->assertJsonPath('data.medical_record_layout.custom.hidden.0', 'tonometria');
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

/**
 * Pedal USB do Gerenciador de Imagens (Fase 5 / 7a) — eye_images_pedal_shortcuts
 * {capture_key, advance_key}. Allowlist vem de config('eye_image_capture.pedal.allowed_keys')
 * — nunca pode aceitar Escape/ArrowLeft/ArrowRight (colidem com os atalhos já
 * existentes do visualizador em EyeImages/Index.vue).
 */
it('persiste capture_key e advance_key do pedal quando ambos estão na allowlist', function () {
    patchPreferences($this, ['eye_images_pedal_shortcuts' => ['capture_key' => 'Enter', 'advance_key' => ' ']])
        ->assertOk()
        ->assertJsonPath('data.eye_images_pedal_shortcuts.capture_key', 'Enter')
        ->assertJsonPath('data.eye_images_pedal_shortcuts.advance_key', ' ');

    expect(UserPreference::valueFor($this->user->fresh(), 'eye_images_pedal_shortcuts')['capture_key'])->toBe('Enter');
});

it('aceita só capture_key informado, sem exigir advance_key junto', function () {
    patchPreferences($this, ['eye_images_pedal_shortcuts' => ['capture_key' => 'PageUp']])
        ->assertOk()
        ->assertJsonPath('data.eye_images_pedal_shortcuts.capture_key', 'PageUp');
});

it('aceita capture_key e advance_key nulos (desliga o pedal) sem disparar "devem ser diferentes"', function () {
    patchPreferences($this, ['eye_images_pedal_shortcuts' => ['capture_key' => null, 'advance_key' => null]])
        ->assertOk()
        ->assertJsonPath('data.eye_images_pedal_shortcuts.capture_key', null)
        ->assertJsonPath('data.eye_images_pedal_shortcuts.advance_key', null);
});

it('[GAP] rejeita capture_key === advance_key (422) — pedal com 1 botão só ficaria ambíguo', function () {
    patchPreferences($this, ['eye_images_pedal_shortcuts' => ['capture_key' => 'Enter', 'advance_key' => 'Enter']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['eye_images_pedal_shortcuts.advance_key']);
});

it('rejeita tecla fora da allowlist (422)', function () {
    patchPreferences($this, ['eye_images_pedal_shortcuts' => ['capture_key' => 'A', 'advance_key' => ' ']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['eye_images_pedal_shortcuts.capture_key']);
});

it('[SEGURANÇA] nunca aceita Escape/ArrowLeft/ArrowRight — colidem com os atalhos do visualizador', function () {
    foreach (['Escape', 'ArrowLeft', 'ArrowRight'] as $key) {
        patchPreferences($this, ['eye_images_pedal_shortcuts' => ['capture_key' => $key, 'advance_key' => ' ']])
            ->assertUnprocessable();

        patchPreferences($this, ['eye_images_pedal_shortcuts' => ['capture_key' => 'Enter', 'advance_key' => $key]])
            ->assertUnprocessable();
    }

    expect(UserPreference::valueFor($this->user->fresh(), 'eye_images_pedal_shortcuts'))->toBeNull();
});
