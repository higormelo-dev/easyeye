<?php

use App\Models\IntegratorUpdate;
use App\Services\IntegratorUpdatePublisher;
use Illuminate\Support\Facades\Storage;

/**
 * Keypair de teste — isolado da chave de produção (services.integrator_
 * updates.public_key aponta pra ela nos beforeEach abaixo). Assinar de
 * verdade com a privada correspondente, em vez de uma string dummy, é o que
 * torna estes testes uma checagem real da verificação criptográfica
 * (sodium_crypto_sign_verify_detached), não só do formato.
 */
function testUpdateKeypair(): array
{
    static $keypair = null;

    if ($keypair === null) {
        $kp      = sodium_crypto_sign_keypair();
        $keypair = [
            'secret' => sodium_crypto_sign_secretkey($kp),
            'public' => sodium_crypto_sign_publickey($kp),
        ];
    }

    return $keypair;
}

/**
 * Assina os BYTES CRUS do digest SHA-256 de $localPath com a privada de
 * teste — mesma convenção do scripts/sign-update.sh do repositório do
 * integrator (openssl pkeyutl -sign -rawin sobre o digest binário, não a
 * string hex nem o arquivo inteiro).
 */
function signInstaller(string $localPath): string
{
    $digest    = hex2bin(hash_file('sha256', $localPath));
    $signature = sodium_crypto_sign_detached($digest, testUpdateKeypair()['secret']);

    return base64_encode($signature);
}

/**
 * Assinatura com formato válido (base64 de 64 bytes) mas sem significado
 * criptográfico — só para popular registros via forceCreate() nos testes
 * abaixo que não passam pelo publisher (então nunca são reverificados).
 */
function formatOnlySignature(): string
{
    return base64_encode(str_repeat('a', 64));
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
        config(['services.integrator_updates.public_key' => bin2hex(testUpdateKeypair()['public'])]);
    });

    it('publishes a new build and registers the manifest', function () {
        $file = fakeInstallerPath();

        $this->artisan('integrator:publish-update', [
            'file'              => $file,
            '--release-version' => '1.0.0',
            '--platform'        => 'windows',
            '--arch'            => 'x86_64',
            '--signature'       => signInstaller($file),
        ])->assertExitCode(0);

        $update = IntegratorUpdate::where('version', '1.0.0')
            ->where('platform', 'windows')
            ->where('arch', 'x86_64')
            ->first();

        expect($update)->not->toBeNull()
            ->and($update->active)->toBeTrue()
            ->and($update->sha256)->toBe(hash_file('sha256', $file));

        Storage::disk('s3')->assertExists($update->archive);

        @unlink($file);
    });

    it('defaults --platform to windows when not provided', function () {
        $file = fakeInstallerPath();

        $this->artisan('integrator:publish-update', [
            'file'              => $file,
            '--release-version' => '1.0.0',
            '--arch'            => 'x86_64',
            '--signature'       => signInstaller($file),
        ])->assertExitCode(0);

        expect(IntegratorUpdate::where('version', '1.0.0')->first()->platform)->toBe('windows');

        @unlink($file);
    });

    it('fails with a non-zero exit code when the file does not exist', function () {
        $this->artisan('integrator:publish-update', [
            'file'              => '/tmp/caminho-que-nao-existe-' . uniqid() . '.msi',
            '--release-version' => '1.0.0',
            '--arch'            => 'x86_64',
            '--signature'       => formatOnlySignature(),
        ])->assertExitCode(1);

        expect(IntegratorUpdate::count())->toBe(0);
    });

    it('fails when --release-version is missing', function () {
        $file = fakeInstallerPath();

        $this->artisan('integrator:publish-update', [
            'file'        => $file,
            '--arch'      => 'x86_64',
            '--signature' => signInstaller($file),
        ])->assertExitCode(1);

        expect(IntegratorUpdate::count())->toBe(0);

        @unlink($file);
    });

    it('fails when --arch is missing', function () {
        $file = fakeInstallerPath();

        $this->artisan('integrator:publish-update', [
            'file'              => $file,
            '--release-version' => '1.0.0',
            '--signature'       => signInstaller($file),
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

    it('fails with a non-zero exit code when the signature has valid format but does not match the file', function () {
        $file       = fakeInstallerPath();
        $otherFile  = fakeInstallerPath('conteudo-completamente-diferente');
        $mismatched = signInstaller($otherFile);

        $this->artisan('integrator:publish-update', [
            'file'              => $file,
            '--release-version' => '1.0.0',
            '--arch'            => 'x86_64',
            '--signature'       => $mismatched,
        ])->assertExitCode(1);

        expect(IntegratorUpdate::count())->toBe(0);

        @unlink($file);
        @unlink($otherFile);
    });

    it('deactivates previous builds of the same platform/arch by default', function () {
        $previous = IntegratorUpdate::forceCreate([
            'version'   => '1.0.0',
            'platform'  => 'windows',
            'arch'      => 'x86_64',
            'archive'   => 'integrator-updates/1.0.0/old.msi',
            'sha256'    => str_repeat('ab', 32),
            'signature' => formatOnlySignature(),
            'active'    => true,
        ]);
        $file = fakeInstallerPath();

        $this->artisan('integrator:publish-update', [
            'file'              => $file,
            '--release-version' => '1.1.0',
            '--platform'        => 'windows',
            '--arch'            => 'x86_64',
            '--signature'       => signInstaller($file),
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
            'signature' => formatOnlySignature(),
            'active'    => true,
        ]);
        $file = fakeInstallerPath();

        $this->artisan('integrator:publish-update', [
            'file'              => $file,
            '--release-version' => '1.1.0',
            '--platform'        => 'windows',
            '--arch'            => 'x86_64',
            '--signature'       => signInstaller($file),
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
            'signature' => formatOnlySignature(),
            'active'    => true,
        ]);
        $file = fakeInstallerPath();

        $this->artisan('integrator:publish-update', [
            'file'              => $file,
            '--release-version' => '1.1.0',
            '--platform'        => 'windows',
            '--arch'            => 'x86_64',
            '--signature'       => signInstaller($file),
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
            '--signature'       => signInstaller($file1),
        ])->assertExitCode(0);
        @unlink($file1);

        $file2 = fakeInstallerPath('conteudo-v1-recompilado');

        $this->artisan('integrator:publish-update', [
            'file'              => $file2,
            '--release-version' => '1.0.0',
            '--platform'        => 'windows',
            '--arch'            => 'x86_64',
            '--signature'       => signInstaller($file2),
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
// do Manager) — testado diretamente para os ramos de validação de
// assinatura: formato E verificação criptográfica de verdade.
// ---------------------------------------------------------------------------
describe('IntegratorUpdatePublisher', function () {
    beforeEach(function () {
        Storage::fake('s3');
        config(['services.integrator_updates.public_key' => bin2hex(testUpdateKeypair()['public'])]);
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

    it('throws InvalidArgumentException when the signature has valid format but was produced for a different file', function () {
        $file      = fakeInstallerPath('conteudo-real');
        $otherFile = fakeInstallerPath('conteudo-de-outro-arquivo');

        expect(fn () => app(IntegratorUpdatePublisher::class)->publish(
            localPath: $file,
            fileName: basename($file),
            version: '1.0.0',
            platform: 'windows',
            arch: 'x86_64',
            signature: signInstaller($otherFile), // assina o digest ERRADO de propósito
        ))->toThrow(InvalidArgumentException::class);

        expect(IntegratorUpdate::count())->toBe(0);

        @unlink($file);
        @unlink($otherFile);
    });

    it('throws InvalidArgumentException when the configured public key is the all-zero placeholder', function () {
        config(['services.integrator_updates.public_key' => str_repeat('0', 64)]);
        $file = fakeInstallerPath();

        expect(fn () => app(IntegratorUpdatePublisher::class)->publish(
            localPath: $file,
            fileName: basename($file),
            version: '1.0.0',
            platform: 'windows',
            arch: 'x86_64',
            signature: signInstaller($file),
        ))->toThrow(InvalidArgumentException::class);

        expect(IntegratorUpdate::count())->toBe(0);

        @unlink($file);
    });

    /**
     * Vetor de conhecido-bom (known-answer) copiado LITERALMENTE do teste
     * `accepts_a_signature_produced_by_the_signing_script` em
     * integrator/src/updater/mod.rs — mesmo arquivo (bytes exatos), mesma
     * assinatura real (gerada com scripts/sign-update.sh e a chave privada
     * de produção), mesma chave pública embutida no binário Rust
     * (UPDATE_PUBLIC_KEY_HEX). Prova que sodium_crypto_sign_verify_detached
     * (aqui) e ed25519_dalek::VerifyingKey::verify_strict (lá) concordam
     * byte a byte pra essa tripla real — não é só "minha implementação
     * aceita o que ela mesma assinou", é interoperabilidade cruzada
     * comprovada com o cliente de verdade.
     */
    it('accepts a real signature produced by the integrator repo signing script against the real embedded public key', function () {
        config(['services.integrator_updates.public_key' => 'c62b4de90f8cacd4bf0f4d408a2dec22f80c181f19e0f64156124a00aedbe2b9']);

        $file = fakeInstallerPath("dummy msi payload\n");
        expect(hash_file('sha256', $file))
            ->toBe('61ed817144fba1fdd7e3a4e68811cbd0558a7d75c02cfe47a342fea081b3e218');

        $update = app(IntegratorUpdatePublisher::class)->publish(
            localPath: $file,
            fileName: basename($file),
            version: '9.9.9',
            platform: 'windows',
            arch: 'x86_64',
            signature: 'Yt7GwAggyQNqUnT1b4hZkDBKmO0BtHOm4uuuO2UKXhI1oj4ciZCbwtDN3QIVVblEmJVf/bxnLVM8wW3HNDqGCg==',
        );

        expect($update->version)->toBe('9.9.9');

        @unlink($file);
    });
});
