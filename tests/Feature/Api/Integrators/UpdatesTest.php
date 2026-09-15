<?php

use App\Models\IntegratorUpdate;
use Illuminate\Support\Facades\Storage;

describe('GET /api/integrators/v1/updates', function () {
    beforeEach(function () {
        $this->ctx = setupIntegrator();
        Storage::fake('s3');
    });

    function makeUpdate(array $overrides = []): IntegratorUpdate
    {
        // forceCreate: permite fixar created_at (fora do $fillable) nos
        // testes de ordenação.
        return IntegratorUpdate::forceCreate([
            'version'   => '0.2.0',
            'platform'  => 'windows',
            'arch'      => 'x86',
            'archive'   => 'integrator-updates/0.2.0/EasyEye-Integrator-0.2.0-x86.msi',
            'sha256'    => str_repeat('ab', 32),
            'signature' => base64_encode(str_repeat("\x01", 64)),
            'active'    => true,
            ...$overrides,
        ]);
    }

    it('returns null data when nothing is published', function () {
        $this->getJson('/api/integrators/v1/updates?platform=windows&arch=x86', $this->ctx['headers'])
            ->assertOk()
            ->assertExactJson(['data' => null]);
    });

    it('returns the manifest for the matching platform and arch', function () {
        $update = makeUpdate();

        $this->getJson('/api/integrators/v1/updates?platform=windows&arch=x86', $this->ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.version', '0.2.0')
            ->assertJsonPath('data.platform', 'windows')
            ->assertJsonPath('data.arch', 'x86')
            ->assertJsonPath('data.sha256', $update->sha256)
            ->assertJsonPath('data.signature', $update->signature)
            ->assertJsonStructure(['data' => ['url']]);
    });

    it('does not return a build for another platform or arch', function () {
        makeUpdate(['platform' => 'windows', 'arch' => 'x86_64']);

        $this->getJson('/api/integrators/v1/updates?platform=windows&arch=x86', $this->ctx['headers'])
            ->assertOk()
            ->assertExactJson(['data' => null]);
    });

    it('ignores deactivated builds', function () {
        makeUpdate(['active' => false]);

        $this->getJson('/api/integrators/v1/updates?platform=windows&arch=x86', $this->ctx['headers'])
            ->assertOk()
            ->assertExactJson(['data' => null]);
    });

    it('returns the most recently published active build', function () {
        makeUpdate(['version' => '0.1.0', 'created_at' => now()->subDay()]);
        makeUpdate(['version' => '0.2.0']);

        $this->getJson('/api/integrators/v1/updates?platform=windows&arch=x86', $this->ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.version', '0.2.0');
    });

    it('validates platform and arch as required', function () {
        $this->getJson('/api/integrators/v1/updates', $this->ctx['headers'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['platform', 'arch']);
    });

    it('returns 401 without authentication', function () {
        $this->getJson('/api/integrators/v1/updates?platform=windows&arch=x86')
            ->assertUnauthorized();
    });
});
