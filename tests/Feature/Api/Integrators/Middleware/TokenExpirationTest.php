<?php

use Carbon\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

describe('Middleware ApiCheckTokenExpiration', function () {
    it('blocks request with token expired by date', function () {
        $ctx = setupIntegrator();

        // Criar token já expirado por data
        $token = $ctx['integratorUser']->createToken(
            'integrator-token',
            ['integrator_id:' . $ctx['integrator']->id],
            Carbon::now()->subHour()
        );

        $this->getJson('/api/integrators/v1/patients', [
            'Authorization' => 'Bearer ' . $token->plainTextToken,
        ])->assertUnauthorized()
            ->assertJsonPath('message', __('auth.token_expired'));
    });

    it('blocks request with token expired by inactivity (4+ business days)', function () {
        $ctx = setupIntegrator();

        // Token criado há 6 dias úteis sem uso
        $token = $ctx['integratorUser']->createToken(
            'integrator-token',
            ['integrator_id:' . $ctx['integrator']->id],
            Carbon::now()->addDays(7)
        );

        // Simular último uso há 6 dias atrás (mais de 3 dias úteis)
        $accessToken            = PersonalAccessToken::findToken($token->plainTextToken);
        $accessToken->last_used_at = Carbon::now()->subDays(6);
        $accessToken->save();

        $this->getJson('/api/integrators/v1/patients', [
            'Authorization' => 'Bearer ' . $token->plainTextToken,
        ])->assertUnauthorized()
            ->assertJsonPath('message', __('auth.token_expired_inactivity'));
    });

    it('auto-renews token that expires within 1 business day', function () {
        Carbon::setTestNow(Carbon::parse('2025-03-10 10:00:00')); // segunda

        $ctx = setupIntegrator();

        // Token que expira amanhã (terça) = 1 dia útil
        $expiresAt = Carbon::parse('2025-03-11 10:00:00');
        $token = $ctx['integratorUser']->createToken(
            'integrator-token',
            ['integrator_id:' . $ctx['integrator']->id],
            $expiresAt
        );

        $oldExpiresAt = $expiresAt->copy();

        $this->getJson('/api/integrators/v1/patients', [
            'Authorization' => 'Bearer ' . $token->plainTextToken,
        ])->assertOk();

        // Verificar que o token foi renovado
        $accessToken = PersonalAccessToken::findToken($token->plainTextToken);
        expect($accessToken->expires_at->gt($oldExpiresAt))->toBeTrue();

        Carbon::setTestNow();
    });

    it('allows request with active valid token', function () {
        $ctx = setupIntegrator();

        $this->getJson('/api/integrators/v1/patients', $ctx['headers'])
            ->assertOk();
    });

    it('blocks request when integrator is deactivated', function () {
        $ctx = setupIntegrator();
        $ctx['integrator']->update(['active' => false]);

        $this->getJson('/api/integrators/v1/patients', $ctx['headers'])
            ->assertUnauthorized();
    });

    it('blocks request when integrator user is deactivated', function () {
        $ctx = setupIntegrator();
        $ctx['integratorUser']->update(['active' => false]);

        $this->getJson('/api/integrators/v1/patients', $ctx['headers'])
            ->assertUnauthorized();
    });

    it('blocks request when entity is deactivated', function () {
        $ctx = setupIntegrator();
        $ctx['entity']->update(['active' => false]);

        $this->getJson('/api/integrators/v1/patients', $ctx['headers'])
            ->assertUnauthorized();
    });
});
