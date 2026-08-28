<?php

/**
 * Páginas do fluxo de 2FA (Security/TwoFactorSetup e Security/TwoFactorVerify)
 * redesenhadas no padrão da tela "Confirme seu WhatsApp" (Auth/VerifyPhone.vue).
 *
 * Cobre:
 *  - verify: renderiza o componente com TODAS as chaves de tradução que o
 *    template usa (título/subtítulo, parágrafos, placeholders, botões, feedback);
 *  - setup: renderiza com secret/qr_svg/otpauth (Key URI padrão lida por
 *    Google/Microsoft Authenticator) e com as chaves do template;
 *  - verify.store com código inválido → 422 JSON e a sessão continua bloqueada;
 *  - verify.store com payload malformado → 422 com errors.code (shape distinto);
 *  - throttle 6/min → 7ª tentativa responde 429 (a UI mostra "aguarde");
 *  - confirm devolve `redirect` (destino do botão "Já guardei. Continuar");
 *  - paridade pt_BR/en do arquivo de tradução;
 *  - todas as rotas exigem autenticação;
 *  - TODO (backend): usuário já inscrito + sessão não verificada não pode
 *    re-inscrever via /setup nem liberar o painel (bypass pré-existente).
 */

use App\Models\{Entity, User};
use App\Services\Security\TwoFactorService;
use PragmaRX\Google2FA\Google2FA;

/** Chaves que o template Security/TwoFactorVerify.vue consome via prop `t`. */
const TWO_FACTOR_VERIFY_KEYS = [
    'verify_title', 'verify_subtitle', 'verify_hint_totp', 'verify_hint_recovery', 'verify_help',
    'code_placeholder', 'recovery_code_placeholder', 'code_aria_label', 'recovery_code_aria_label',
    'btn_verify', 'verify_use_recovery', 'verify_use_totp', 'btn_logout',
    'verified', 'invalid_code', 'network_error', 'too_many_attempts', 'session_expired',
];

/** Chaves que o template Security/TwoFactorSetup.vue consome via prop `t`. */
const TWO_FACTOR_SETUP_KEYS = [
    'setup_title', 'setup_subtitle', 'setup_intro', 'setup_help', 'setup_step_1', 'setup_step_2',
    'manual_secret', 'btn_copy_secret', 'secret_copied', 'code_placeholder', 'code_aria_label',
    'btn_confirm', 'btn_regenerate', 'regenerated', 'enabled',
    'recovery_title', 'recovery_subtitle', 'recovery_intro', 'recovery_warning',
    'btn_copy', 'btn_download', 'btn_done', 'copied', 'copy_failed', 'downloaded',
    'btn_logout', 'invalid_code', 'network_error', 'too_many_attempts', 'session_expired',
];

function twoFactorPagesSession(User $user): array
{
    $entity = Entity::factory()->create(['is_client' => true, 'requires_two_factor' => true]);
    createEntityUser($entity, $user, 'admin');

    return [
        'selected_entity_id'        => $entity->id,
        'selected_entity_is_client' => true,
        'selected_entity_user_rule' => 'admin',
    ];
}

function userWithConfirmedTwoFactor(): array
{
    $user    = User::factory()->create();
    $service = app(TwoFactorService::class);
    $setup   = $service->generateSecret($user);
    $result  = $service->confirm($user, (new Google2FA())->getCurrentOtp($setup['secret']));

    return [$user->fresh(), $setup['secret'], $result['recovery_codes']];
}

// ── Renderização das páginas ─────────────────────────────────────────────────

test('verify renderiza Security/TwoFactorVerify com as traduções usadas no template', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withSession(twoFactorPagesSession($user))
        ->get(route('security.two-factor.verify'));

    $response->assertOk();

    $page = $response->viewData('page');
    expect($page['component'])->toBe('Security/TwoFactorVerify');
    expect($page['props']['appName'])->toBe(config('app.name'));

    foreach (TWO_FACTOR_VERIFY_KEYS as $key) {
        expect($page['props']['t'])->toHaveKey($key);
        expect($page['props']['t'][$key])->toBeString()->not->toBe('');
    }

    // A tela de verify nunca expõe o secret/otpauth.
    expect(array_keys($page['props']))->not->toContain('secret')->not->toContain('otpauth');
});

test('setup renderiza Security/TwoFactorSetup com secret, QR e URI otpauth', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withSession(twoFactorPagesSession($user))
        ->get(route('security.two-factor.setup'));

    $response->assertOk();

    $page  = $response->viewData('page');
    $props = $page['props'];

    expect($page['component'])->toBe('Security/TwoFactorSetup');
    expect($props['secret'])->toMatch('/^[A-Z2-7]{32}$/');
    expect($props['qr_svg'])->toContain('<svg');
    expect($props['otpauth'])
        ->toStartWith('otpauth://totp/')
        ->toContain('secret=' . $props['secret'])
        ->toContain('issuer=');

    foreach (TWO_FACTOR_SETUP_KEYS as $key) {
        expect($props['t'])->toHaveKey($key);
        expect($props['t'][$key])->toBeString()->not->toBe('');
    }
});

test('setup reaproveita o secret pendente ao recarregar a página', function () {
    $user    = User::factory()->create();
    $session = twoFactorPagesSession($user);

    $first  = $this->actingAs($user)->withSession($session)->get(route('security.two-factor.setup'));
    $second = $this->actingAs($user)->withSession($session)->get(route('security.two-factor.setup'));

    expect($second->viewData('page')['props']['secret'])
        ->toBe($first->viewData('page')['props']['secret']);
});

test('regenerar secret redireciona para o setup com um secret novo', function () {
    $user    = User::factory()->create();
    $session = twoFactorPagesSession($user);

    $before = $this->actingAs($user)->withSession($session)
        ->get(route('security.two-factor.setup'))
        ->viewData('page')['props']['secret'];

    $this->actingAs($user)->withSession($session)
        ->post(route('security.two-factor.setup.store'))
        ->assertRedirect(route('security.two-factor.setup'));

    $after = $this->actingAs($user)->withSession($session)
        ->get(route('security.two-factor.setup'))
        ->viewData('page')['props']['secret'];

    expect($after)->toMatch('/^[A-Z2-7]{32}$/')->not->toBe($before);
});

// ── Confirmação do setup ─────────────────────────────────────────────────────

test('confirm devolve recovery codes e o redirect do botão "Já guardei. Continuar"', function () {
    $user    = User::factory()->create();
    $session = twoFactorPagesSession($user);

    $setup = app(TwoFactorService::class)->generateSecret($user);
    $code  = (new Google2FA())->getCurrentOtp($setup['secret']);

    $response = $this->actingAs($user)
        ->withSession($session)
        ->postJson(route('security.two-factor.confirm'), ['code' => $code]);

    $response->assertOk()->assertJsonStructure(['message', 'recovery_codes', 'redirect']);
    expect($response->json('recovery_codes'))->toHaveCount(10);
    expect($response->json('redirect'))->toBe(route('panel.dashboard'));

    foreach ($response->json('recovery_codes') as $recoveryCode) {
        expect($recoveryCode)->toMatch('/^[A-Z0-9]{4}-[A-Z0-9]{4}$/');
    }
});

test('confirm com código malformado responde 422 com errors.code', function () {
    $user = User::factory()->create();
    app(TwoFactorService::class)->generateSecret($user);

    $this->actingAs($user)
        ->withSession(twoFactorPagesSession($user))
        ->postJson(route('security.two-factor.confirm'), ['code' => '12'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['code']);

    expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse();
});

// ── Verificação no login ─────────────────────────────────────────────────────

test('verify.store com código inválido responde 422 e mantém a sessão bloqueada', function () {
    [$user]  = userWithConfirmedTwoFactor();
    $session = twoFactorPagesSession($user);

    $this->actingAs($user)
        ->withSession($session)
        ->postJson(route('security.two-factor.verify.store'), ['code' => '000000'])
        ->assertStatus(422)
        ->assertJsonStructure(['message'])
        ->assertJson(['message' => __('manager_hardening.two_factor_invalid')]);

    // Sessão continua sem two_factor_verified_at → painel segue redirecionando.
    $this->get(route('panel.dashboard'))->assertRedirect(route('security.two-factor.verify'));
});

test('verify.store com payload malformado responde 422 com errors.code', function () {
    [$user] = userWithConfirmedTwoFactor();

    $this->actingAs($user)
        ->withSession(twoFactorPagesSession($user))
        ->postJson(route('security.two-factor.verify.store'), ['code' => '12'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['code']);
});

test('verify.store bloqueia após 6 tentativas por minuto (429)', function () {
    [$user]  = userWithConfirmedTwoFactor();
    $session = twoFactorPagesSession($user);

    foreach (range(1, 6) as $i) {
        $this->actingAs($user)
            ->withSession($session)
            ->postJson(route('security.two-factor.verify.store'), ['code' => '000000'])
            ->assertStatus(422);
    }

    $this->actingAs($user)
        ->withSession($session)
        ->postJson(route('security.two-factor.verify.store'), ['code' => '000000'])
        ->assertStatus(429)
        ->assertHeader('Retry-After');
});

test('verify.store aceita o código do app e devolve o redirect para o painel', function () {
    [$user, $secret] = userWithConfirmedTwoFactor();

    $response = $this->actingAs($user)
        ->withSession(twoFactorPagesSession($user))
        ->postJson(route('security.two-factor.verify.store'), [
            'code' => (new Google2FA())->getCurrentOtp($secret),
        ]);

    $response->assertOk()
        ->assertJson(['message' => __('two_factor.verified')])
        ->assertJsonPath('redirect', route('panel.dashboard'));

    $this->get(route('panel.dashboard'))->assertOk();
});

test('verify.store aceita recovery code no formato XXXX-XXXX e o consome', function () {
    [$user, , $recoveryCodes] = userWithConfirmedTwoFactor();
    $session                  = twoFactorPagesSession($user);

    $this->actingAs($user)
        ->withSession($session)
        ->postJson(route('security.two-factor.verify.store'), ['code' => $recoveryCodes[0]])
        ->assertOk();

    // Uso único: o mesmo código não vale uma segunda vez.
    $this->actingAs($user->fresh())
        ->withSession($session)
        ->postJson(route('security.two-factor.verify.store'), ['code' => $recoveryCodes[0]])
        ->assertStatus(422);
});

// ── Traduções ────────────────────────────────────────────────────────────────

test('two_factor.php tem as mesmas chaves em pt_BR e en', function () {
    $ptBr = require lang_path('pt_BR/two_factor.php');
    $en   = require lang_path('en/two_factor.php');

    expect(array_diff(array_keys($ptBr), array_keys($en)))->toBe([]);
    expect(array_diff(array_keys($en), array_keys($ptBr)))->toBe([]);

    foreach (array_merge(TWO_FACTOR_VERIFY_KEYS, TWO_FACTOR_SETUP_KEYS) as $key) {
        expect($ptBr)->toHaveKey($key);
        expect($en)->toHaveKey($key);
    }
});

// ── Autenticação ─────────────────────────────────────────────────────────────

test('rotas de 2FA exigem autenticação', function () {
    $this->get(route('security.two-factor.setup'))->assertRedirect(route('login'));
    $this->get(route('security.two-factor.verify'))->assertRedirect(route('login'));
    $this->post(route('security.two-factor.setup.store'))->assertRedirect(route('login'));

    $this->postJson(route('security.two-factor.confirm'), ['code' => '123456'])->assertUnauthorized();
    $this->postJson(route('security.two-factor.verify.store'), ['code' => '123456'])->assertUnauthorized();
});

// ── Regressão pendente (correção no backend) ─────────────────────────────────

/**
 * Achado crítico PRÉ-EXISTENTE em TwoFactorController::setup/regenerateSecret/
 * confirm (commit 11f1f3d): um usuário JÁ inscrito, com a sessão ainda sem
 * `two_factor_verified_at`, consegue abrir GET /setup (que regenera o secret e
 * apaga a inscrição), escanear o QR novo e confirmar — liberando o painel só
 * com a senha. A correção é no controller (fora do escopo da reescrita do
 * frontend); este teste fica como TODO e passa a valer quando o `->todo()`
 * for removido junto com a correção.
 */
test('usuário inscrito sem sessão verificada não re-inscreve o 2FA nem libera o painel', function () {
    [$user, $secret] = userWithConfirmedTwoFactor();
    $session         = twoFactorPagesSession($user);
    $rawSecretBefore = $user->getRawOriginal('two_factor_secret');

    // GET/POST setup não podem tocar em uma inscrição confirmada sem sessão verificada.
    $this->actingAs($user)->withSession($session)
        ->get(route('security.two-factor.setup'))
        ->assertRedirect(route('security.two-factor.verify'));

    $this->actingAs($user->fresh())->withSession($session)
        ->post(route('security.two-factor.setup.store'))
        ->assertRedirect(route('security.two-factor.verify'));

    // confirm com TOTP válido não pode marcar a sessão como verificada.
    $confirm = $this->actingAs($user->fresh())->withSession($session)
        ->postJson(route('security.two-factor.confirm'), [
            'code' => (new Google2FA())->getCurrentOtp($secret),
        ]);
    expect($confirm->status())->toBeIn([302, 403]);

    $fresh = $user->fresh();
    expect($fresh->getRawOriginal('two_factor_secret'))->toBe($rawSecretBefore);
    expect($fresh->hasTwoFactorEnabled())->toBeTrue();

    $this->actingAs($fresh)->withSession($session)
        ->get(route('panel.dashboard'))
        ->assertRedirect(route('security.two-factor.verify'));
})->todo();
