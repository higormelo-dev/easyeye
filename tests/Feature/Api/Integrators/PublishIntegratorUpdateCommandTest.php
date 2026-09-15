<?php

use App\Models\IntegratorUpdate;
use App\Services\IntegratorUpdatePublisher;
use Illuminate\Support\Facades\Storage;

/**
 * Assinatura ed25519 "válida" do ponto de vista do publisher: base64 de
 * exatamente 64 bytes. O servidor só valida o FORMATO (o cliente desktop é
 * quem verifica a assinatura de verdade contra a chave pública embutida).
 */
function validUpdateSignature(): string
{
    return base64_encode(str_repeat("\x01", 64));
}

/**
 * Cria um arquivo local real (fora do Storage::fake) para servir de
 * {file} do comando — is_file()/hash_file() operam no filesystem real,
 * não no disco fake do S3.
 */
function fakeInstallerPath(string $contents = 'conteudo-fake-do-instalador'): string
{
    $path = tempnam(sys_get_temp_dir(), 'integrator-update-test-');
    file_put_contents($path, $contents);

    return $path;
}

// ---------------------------------------------------------------------------
// artisan integrator:publish-update
// ---------------------------------------------------------------------------
describe('artisan integrator:publish-update', function () {
    beforeEach(function () {
        Storage::fake('s3');
    });

    it('publishes a new build and registers the manifest', function () {
        $file = fakeInstallerPath();

        $this->artisan('integrator:publish-update', [
            'file'              => $file,
            '--release-version' => '1.0.0',
            '--platform'        => 'windows',
            '--arch'            => 'x86_64',
            '--signature'       => validUpdateSignature(),
        ])->assertExitCode(0);

        $update = IntegratorUpdate::where('version', '1.0.0')
            ->where('platform', 'windows')
            ->where('arch', 'x86_64')
            ->first();

        expect($update)->not->toBeNull()
            ->and($update->active)->toBeTrue()
            ->and($update->sha256)->toBe(hash_file('sha256', $file))
            ->and($update->signature)->toBe(validUpdateSignature());

        Storage::disk('s3')->assertExists($update->archive);

        @unlink($file);
    });

    it('defaults --platform to windows when not provided', function () {
        $file = fakeInstallerPath();

        $this->artisan('integrator:publish-update', [
            'file'              => $file,
            '--release-version' => '1.0.0',
            '--arch'            => 'x86_64',
            '--signature'       => validUpdateSignature(),
        ])->assertExitCode(0);

        expect(IntegratorUpdate::where('version', '1.0.0')->first()->platform)->toBe('windows');

        @unlink($file);
    });

    it('fails with a non-zero exit code when the file does not exist', function () {
        $this->artisan('integrator:publish-update', [
            'file'              => '/tmp/caminho-que-nao-existe-' . uniqid() . '.msi',
            '--release-version' => '1.0.0',
            '--arch'            => 'x86_64',
            '--signature'       => validUpdateSignature(),
        ])->assertExitCode(1);

        expect(IntegratorUpdate::count())->toBe(0);
    });

    it('fails when --release-version is missing', function () {
        $file = fakeInstallerPath();

        $this->artisan('integrator:publish-update', [
            'file'        => $file,
            '--arch'      => 'x86_64',
            '--signature' => validUpdateSignature(),
        ])->assertExitCode(1);

        expect(IntegratorUpdate::count())->toBe(0);

        @unlink($file);
    });

    it('fails when --arch is missing', function () {
        $file = fakeInstallerPath();

        $this->artisan('integrator:publish-update', [
            'file'              => $file,
            '--release-version' => '1.0.0',
            '--signature'       => validUpdateSignature(),
        ])->assertExitCode(1);

        expect(IntegratorUpdate::count())->toBe(0);

        @unlink($file);
    });

    it('fails when --signature is missing', function () {
        $file = fakeInstallerPath();

        $this->artisan('integrator:publish-update', [
            'file'              => $file,
            '--release-version' => '1.0.0',
            '--arch'            => 'x86_64',
        ])->assertExitCode(1);

        expect(IntegratorUpdate::count())->toBe(0);

        @unlink($file);
    });

    it('fails with a non-zero exit code when the signature is not valid base64 of 64 bytes', function () {
        $file = fakeInstallerPath();

        $this->artisan('integrator:publish-update', [
            'file'              => $file,
            '--release-version' => '1.0.0',
            '--arch'            => 'x86_64',
            '--signature'       => 'assinatura-invalida-curta-demais',
        ])->assertExitCode(1);

        expect(IntegratorUpdate::count())->toBe(0);

        @unlink($file);
    });

    it('deactivates previous builds of the same platform/arch by default', function () {
        $previous = IntegratorUpdate::forceCreate([
            'version'   => '1.0.0',
            'platform'  => 'windows',
            'arch'      => 'x86_64',
            'archive'   => 'integrator-updates/1.0.0/old.msi',
            'sha256'    => str_repeat('ab', 32),
            'signature' => validUpdateSignature(),
            'active'    => true,
        ]);
        $file = fakeInstallerPath();

        $this->artisan('integrator:publish-update', [
            'file'              => $file,
            '--release-version' => '1.1.0',
            '--platform'        => 'windows',
            '--arch'            => 'x86_64',
            '--signature'       => validUpdateSignature(),
        ])->assertExitCode(0);

        expect($previous->fresh()->active)->toBeFalse()
            ->and(IntegratorUpdate::where('version', '1.1.0')->first()->active)->toBeTrue();

        @unlink($file);
    });

    it('keeps previous builds active when --keep-previous is used', function () {
        $previous = IntegratorUpdate::forceCreate([
            'version'   => '1.0.0',
            'platform'  => 'windows',
            'arch'      => 'x86_64',
            'archive'   => 'integrator-updates/1.0.0/old.msi',
            'sha256'    => str_repeat('ab', 32),
            'signature' => validUpdateSignature(),
            'active'    => true,
        ]);
        $file = fakeInstallerPath();

        $this->artisan('integrator:publish-update', [
            'file'              => $file,
            '--release-version' => '1.1.0',
            '--platform'        => 'windows',
            '--arch'            => 'x86_64',
            '--signature'       => validUpdateSignature(),
            '--keep-previous'   => true,
        ])->assertExitCode(0);

        expect($previous->fresh()->active)->toBeTrue();

        @unlink($file);
    });

    it('does not deactivate builds of a different platform/arch', function () {
        $other = IntegratorUpdate::forceCreate([
            'version'   => '1.0.0',
            'platform'  => 'linux',
            'arch'      => 'x86_64',
            'archive'   => 'integrator-updates/1.0.0/old-linux',
            'sha256'    => str_repeat('cd', 32),
            'signature' => validUpdateSignature(),
            'active'    => true,
        ]);
        $file = fakeInstallerPath();

        $this->artisan('integrator:publish-update', [
            'file'              => $file,
            '--release-version' => '1.1.0',
            '--platform'        => 'windows',
            '--arch'            => 'x86_64',
            '--signature'       => validUpdateSignature(),
        ])->assertExitCode(0);

        expect($other->fresh()->active)->toBeTrue();

        @unlink($file);
    });

    it('republishing the same version/platform/arch updates the existing record instead of duplicating', function () {
        $file1 = fakeInstallerPath('conteudo-v1');

        $this->artisan('integrator:publish-update', [
            'file'              => $file1,
            '--release-version' => '1.0.0',
            '--platform'        => 'windows',
            '--arch'            => 'x86_64',
            '--signature'       => validUpdateSignature(),
        ])->assertExitCode(0);
        @unlink($file1);

        $file2 = fakeInstallerPath('conteudo-v1-recompilado');

        $this->artisan('integrator:publish-update', [
            'file'              => $file2,
            '--release-version' => '1.0.0',
            '--platform'        => 'windows',
            '--arch'            => 'x86_64',
            '--signature'       => validUpdateSignature(),
        ])->assertExitCode(0);

        expect(IntegratorUpdate::where('version', '1.0.0')
            ->where('platform', 'windows')
            ->where('arch', 'x86_64')
            ->count())->toBe(1);

        $update = IntegratorUpdate::where('version', '1.0.0')->first();
        expect($update->sha256)->toBe(hash_file('sha256', $file2));

        @unlink($file2);
    });
});

// ---------------------------------------------------------------------------
// IntegratorUpdatePublisher (chamado tanto pelo Command quanto pela página
// do Manager) — testado diretamente para os ramos de validação de assinatura.
// ---------------------------------------------------------------------------
describe('IntegratorUpdatePublisher', function () {
    beforeEach(function () {
        Storage::fake('s3');
    });

    it('throws InvalidArgumentException when the signature is not valid base64', function () {
        $file = fakeInstallerPath();

        expect(fn () => app(IntegratorUpdatePublisher::class)->publish(
            localPath: $file,
            fileName: basename($file),
            version: '1.0.0',
            platform: 'windows',
            arch: 'x86_64',
            signature: 'not-valid-base64-!!!',
        ))->toThrow(InvalidArgumentException::class);

        expect(IntegratorUpdate::count())->toBe(0);

        @unlink($file);
    });

    it('throws InvalidArgumentException when the decoded signature is not exactly 64 bytes', function () {
        $file = fakeInstallerPath();

        expect(fn () => app(IntegratorUpdatePublisher::class)->publish(
            localPath: $file,
            fileName: basename($file),
            version: '1.0.0',
            platform: 'windows',
            arch: 'x86_64',
            signature: base64_encode('too-short-to-be-ed25519'),
        ))->toThrow(InvalidArgumentException::class);

        expect(IntegratorUpdate::count())->toBe(0);

        @unlink($file);
    });
});
