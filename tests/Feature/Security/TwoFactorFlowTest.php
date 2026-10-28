<?php

/**
 * Fluxo completo de MFA quando o ADMIN DA CLÍNICA obriga 2FA na empresa
 * (entities.requires_two_factor = true):
 *
 *   1. Usuário sem 2FA → painel redireciona para a página de SETUP (QR).
 *   2. Confirma o código do app autenticador (TOTP padrão RFC 6238 —
 *      Google/Microsoft Authenticator) → recebe recovery codes → sessão
 *      marcada como verificada → painel liberado.
 *   3. Logout invalida a sessão → próximo login cai na página de VERIFY
 *      (digitar o código) → código válido libera; inválido não.
 *   4. Recovery code também libera (perda do aparelho).
 *
 * O código "do app" é gerado com a própria lib Google2FA a partir do
 * secret — exatamente o que Microsoft/Google Authenticator fazem.
 */

use App\Models\{Entity, User};
use App\Services\Security\TwoFactorService;
use PragmaRX\Google2FA\Google2FA;

function clinicRequiringTwoFactor(User $user): array
{
    $entity = Entity::factory()->create(['is_client' => true, 'requires_two_factor' => true]);
    createEntityUser($entity, $user, 'admin');

    return [
        'selected_entity_id'        => $entity->id,
        'selected_entity_is_client' => true,
        'selected_entity_user_rule' => 'admin',
    ];
}

test('empresa com 2FA obrigatório manda usuário sem 2FA para o setup', function () {
    $user    = User::factory()->create();
    $session = clinicRequiringTwoFactor($user);

    $this->actingAs($user)
        ->withSession($session)
        ->get(route('panel.dashboard'))
        ->assertRedirect(route('security.two-factor.setup'));

    $response = $this->actingAs($user)
        ->withSession($session)
        ->get(route('security.two-factor.setup'));

    $response->assertOk();

    $page = $response->viewData('page');
    expect($page['component'])->toBe('Security/TwoFactorSetup');
    expect($page['props']['secret'])->toMatch('/^[A-Z2-7]{32}$/');
    expect($page['props']['qr_svg'])->toContain('<svg');

    // URI padrão Key URI — lida por Google/Microsoft Authenticator e afins.
    $uri = $page['props']['otpauth'];
    expect($uri)->toStartWith('otpauth://totp/')
        ->toContain('issuer=')
        ->toContain('algorithm=SHA1')
        ->toContain('digits=6')
        ->toContain('period=30');
});

test('setup: código do app confirma, devolve recovery codes e libera o painel na mesma sessão', function () {
    $user    = User::factory()->create();
    $session = clinicRequiringTwoFactor($user);

    $setup = app(TwoFactorService::class)->generateSecret($user);
    $code  = (new Google2FA())->getCurrentOtp($setup['secret']);

    $response = $this->actingAs($user)
        ->withSession($session)
        ->postJson(route('security.two-factor.confirm'), ['code' => $code]);

    $response->assertOk()->assertJsonStructure(['message', 'recovery_codes']);
    expect($response->json('recovery_codes'))->toHaveCount(10);
    expect($user->fresh()->hasTwoFactorEnabled())->toBeTrue();

    // Sessão desta request ficou verificada → painel abre sem pedir código de novo.
    $this->get(route('panel.dashboard'))->assertOk();
});

test('setup: código errado não habilita 2FA', function () {
    $user    = User::factory()->create();
    $session = clinicRequiringTwoFactor($user);

    app(TwoFactorService::class)->generateSecret($user);

    $this->actingAs($user)
        ->withSession($session)
        ->postJson(route('security.two-factor.confirm'), ['code' => '000000'])
        ->assertStatus(422);

    expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse();
});

test('depois de configurado, novo login cai na página de digitar o código', function () {
    $user    = User::factory()->create();
    $session = clinicRequiringTwoFactor($user);

    $service = app(TwoFactorService::class);
    $setup   = $service->generateSecret($user);
    $service->confirm($user, (new Google2FA())->getCurrentOtp($setup['secret']));

    // Nova sessão (login seguinte): sem two_factor_verified_at.
    $this->actingAs($user->fresh())
        ->withSession($session)
        ->get(route('panel.dashboard'))
        ->assertRedirect(route('security.two-factor.verify'));

    $response = $this->actingAs($user->fresh())
        ->withSession($session)
        ->get(route('security.two-factor.verify'));

    $response->assertOk();
    expect($response->viewData('page')['component'])->toBe('Security/TwoFactorVerify');
});

test('verify: código do app libera; código errado não', function () {
    $user    = User::factory()->create();
    $session = clinicRequiringTwoFactor($user);

    $service = app(TwoFactorService::class);
    $setup   = $service->generateSecret($user);
    $service->confirm($user, (new Google2FA())->getCurrentOtp($setup['secret']));

    $this->actingAs($user->fresh())
        ->withSession($session)
        ->postJson(route('security.two-factor.verify.store'), ['code' => '000000'])
        ->assertStatus(422);

    $ok = $this->actingAs($user->fresh())
        ->withSession($session)
        ->postJson(route('security.two-factor.verify.store'), [
            'code' => (new Google2FA())->getCurrentOtp($setup['secret']),
        ]);

    $ok->assertOk()->assertJsonStructure(['message', 'redirect']);

    $this->get(route('panel.dashboard'))->assertOk();
});

test('verify: recovery code libera quando o usuário perdeu o aparelho', function () {
    $user    = User::factory()->create();
    $session = clinicRequiringTwoFactor($user);

    $service = app(TwoFactorService::class);
    $setup   = $service->generateSecret($user);
    $result  = $service->confirm($user, (new Google2FA())->getCurrentOtp($setup['secret']));

    $this->actingAs($user->fresh())
        ->withSession($session)
        ->postJson(route('security.two-factor.verify.store'), ['code' => $result['recovery_codes'][0]])
        ->assertOk();

    $this->get(route('panel.dashboard'))->assertOk();
});
