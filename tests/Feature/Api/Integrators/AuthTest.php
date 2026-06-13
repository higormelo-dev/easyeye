<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

// ── signin ────────────────────────────────────────────────────────────────────

describe('POST /api/integrators/signin', function () {
    beforeEach(function () {
        $ctx = setupIntegrator();

        $this->entity         = $ctx['entity'];
        $this->integrator     = $ctx['integrator'];
        $this->integratorUser = $ctx['integratorUser'];

        // Garantir senha conhecida
        $this->integratorUser->update(['password' => Hash::make('Senha@123')]);
        $this->integratorUser->refresh();
    });

    it('returns token on valid credentials', function () {
        $this->postJson('/api/integrators/signin', [
            'email'    => $this->integratorUser->email,
            'password' => 'Senha@123',
            'code'     => $this->integrator->code,
        ])->assertOk();
    });

    it('returns 401 for wrong password', function () {
        $this->postJson('/api/integrators/signin', [
            'email'    => $this->integratorUser->email,
            'password' => 'SenhaErrada',
            'code'     => $this->integrator->code,
        ])->assertUnauthorized()
            ->assertJsonFragment(['valid' => false]);
    });

    it('returns 401 when email does not exist', function () {
        $this->postJson('/api/integrators/signin', [
            'email'    => 'inexistente@email.com',
            'password' => 'Senha@123',
            'code'     => $this->integrator->code,
        ])->assertUnauthorized()
            ->assertJsonFragment(['valid' => false]);
    });

    it('returns 401 when user is inactive', function () {
        $this->integratorUser->update(['active' => false]);

        $this->postJson('/api/integrators/signin', [
            'email'    => $this->integratorUser->email,
            'password' => 'Senha@123',
            'code'     => $this->integrator->code,
        ])->assertUnauthorized()
            ->assertJsonFragment(['valid' => false]);
    });

    it('returns 401 when integrator is inactive', function () {
        $this->integrator->update(['active' => false]);

        $this->postJson('/api/integrators/signin', [
            'email'    => $this->integratorUser->email,
            'password' => 'Senha@123',
            'code'     => $this->integrator->code,
        ])->assertUnauthorized()
            ->assertJsonFragment(['valid' => false]);
    });

    it('returns 401 when integrator code does not match', function () {
        $this->postJson('/api/integrators/signin', [
            'email'    => $this->integratorUser->email,
            'password' => 'Senha@123',
            'code'     => 'CODIGO-INVALIDO',
        ])->assertUnauthorized()
            ->assertJsonFragment(['valid' => false]);
    });

    it('accepts short code format (EI-1 instead of EI-0000000001)', function () {
        // Extrai a parte numérica sem zero-padding: EI-0000000003 → EI-3
        $numericPart = (int) substr($this->integrator->code, strpos($this->integrator->code, '-') + 1);
        $shortCode   = 'EI-' . $numericPart;

        $this->postJson('/api/integrators/signin', [
            'email'    => $this->integratorUser->email,
            'password' => 'Senha@123',
            'code'     => $shortCode,
        ])->assertOk();
    });

    it('accepts code in lower-case', function () {
        $this->postJson('/api/integrators/signin', [
            'email'    => $this->integratorUser->email,
            'password' => 'Senha@123',
            'code'     => mb_strtolower($this->integrator->code),
        ])->assertOk();
    });
});

// ── check-token ───────────────────────────────────────────────────────────────

describe('POST /api/integrators/check-token', function () {
    beforeEach(function () {
        $ctx = setupIntegrator();

        $this->integratorUser = $ctx['integratorUser'];
        $this->integrator     = $ctx['integrator'];
    });

    it('returns valid=true for a valid active token', function () {
        $token = $this->integratorUser->createToken(
            'integrator-token',
            ['integrator_id:' . $this->integrator->id],
            Carbon::now()->addDays(7)
        );

        $this->postJson('/api/integrators/check-token', [
            'token' => $token->plainTextToken,
        ])->assertOk()
            ->assertJsonFragment(['valid' => true, 'renewed' => false]);
    });

    it('returns 400 when no token is provided', function () {
        $this->postJson('/api/integrators/check-token', [])
            ->assertBadRequest()
            ->assertJsonFragment(['valid' => false]);
    });

    it('returns 401 for an invalid token string', function () {
        $this->postJson('/api/integrators/check-token', [
            'token' => 'token-invalido-qualquer',
        ])->assertUnauthorized()
            ->assertJsonFragment(['valid' => false]);
    });

    it('returns 401 for an expired token', function () {
        $token = $this->integratorUser->createToken(
            'integrator-token',
            ['integrator_id:' . $this->integrator->id],
            Carbon::now()->subDay()  // já expirado
        );

        $this->postJson('/api/integrators/check-token', [
            'token' => $token->plainTextToken,
        ])->assertUnauthorized()
            ->assertJsonFragment(['valid' => false]);
    });

    it('returns 401 when integrator is inactive', function () {
        $this->integrator->update(['active' => false]);

        $token = $this->integratorUser->createToken(
            'integrator-token',
            ['integrator_id:' . $this->integrator->id],
            Carbon::now()->addDays(7)
        );

        $this->postJson('/api/integrators/check-token', [
            'token' => $token->plainTextToken,
        ])->assertUnauthorized()
            ->assertJsonFragment(['valid' => false]);
    });

    it('returns 401 when integrator user is inactive', function () {
        $this->integratorUser->update(['active' => false]);

        $token = $this->integratorUser->createToken(
            'integrator-token',
            ['integrator_id:' . $this->integrator->id],
            Carbon::now()->addDays(7)
        );

        $this->postJson('/api/integrators/check-token', [
            'token' => $token->plainTextToken,
        ])->assertUnauthorized()
            ->assertJsonFragment(['valid' => false]);
    });

    it('returns renewed=true when token expires within 1 business day', function () {
        // Expira amanhã (segunda → terça)
        Carbon::setTestNow(Carbon::parse('2025-03-10 10:00:00')); // segunda
        $expiresAt = Carbon::parse('2025-03-11 10:00:00');         // terça

        $token = $this->integratorUser->createToken(
            'integrator-token',
            ['integrator_id:' . $this->integrator->id],
            $expiresAt
        );

        $this->postJson('/api/integrators/check-token', [
            'token' => $token->plainTextToken,
        ])->assertOk()
            ->assertJsonFragment(['valid' => true, 'renewed' => true]);

        Carbon::setTestNow();
    });
});

// ── signout ───────────────────────────────────────────────────────────────────

describe('DELETE /api/integrators/signout', function () {
    it('revokes token on signout', function () {
        $ctx = setupIntegrator();

        $this->deleteJson('/api/integrators/signout', [], $ctx['headers'])
            ->assertOk()
            ->assertJsonFragment(['message' => 'Token revoked successfully.']);

        // Token deve ter sido deletado
        [$id] = explode('|', $ctx['token']);
        expect(PersonalAccessToken::find($id))->toBeNull();
    });

    it('returns 401 when signing out without token', function () {
        $this->deleteJson('/api/integrators/signout')
            ->assertUnauthorized();
    });
});
