<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\IntegratorUpdate;
use Illuminate\Support\Facades\{DB, Storage};
use InvalidArgumentException;

/**
 * Publica um build do EasyEye Integrator para auto-atualização — lógica
 * compartilhada entre o comando `integrator:publish-update` (CLI) e a página
 * do Manager (upload via navegador).
 *
 * A assinatura ed25519 chega PRONTA (gerada offline por
 * scripts/sign-update.sh, no repositório do integrator, com a chave privada
 * que nunca passa pelo SaaS). Além do cliente desktop verificar de novo
 * antes de instalar, este serviço agora TAMBÉM verifica criptograficamente
 * (services.integrator_updates.public_key — mesmo valor de
 * UPDATE_PUBLIC_KEY_HEX embutido no binário Rust) antes de publicar: um
 * admin que cole a assinatura de um build errado é barrado aqui, não só
 * descoberto depois no cliente. A mensagem assinada é os BYTES CRUS do
 * digest SHA-256 do arquivo — não a string hex, não o arquivo inteiro
 * (mesma convenção de scripts/sign-update.sh: `openssl dgst -sha256
 * -binary` + `openssl pkeyutl -sign -rawin`).
 */
class IntegratorUpdatePublisher
{
    public const PLATFORMS = ['windows', 'linux', 'macos'];

    public const ARCHS = ['x86', 'x86_64', 'aarch64'];

    /**
     * @param string $localPath caminho de um arquivo local (upload já movido
     *                          ou caminho passado no CLI)
     *
     * @throws InvalidArgumentException quando a assinatura não tem o formato
     *                                  de uma ed25519 (base64 de 64 bytes),
     *                                  quando a chave pública configurada é
     *                                  inválida, ou quando a assinatura não
     *                                  verifica contra o SHA-256 do arquivo
     */
    public function publish(
        string $localPath,
        string $fileName,
        string $version,
        string $platform,
        string $arch,
        string $signature,
        bool $keepPrevious = false,
        ?array $metadata = null,
        ?string $manifestSignature = null,
    ): IntegratorUpdate {
        if ($metadata === null || $manifestSignature === null) {
            throw new InvalidArgumentException('manifest_v2_required');
        }
        $signatureBytes = base64_decode($signature, true);

        if ($signatureBytes === false || strlen($signatureBytes) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new InvalidArgumentException(
                'Assinatura inválida: esperada ed25519 em base64 (64 bytes decodificados).',
            );
        }

        $sha256 = hash_file('sha256', $localPath);

        $this->verifySignature($sha256, $signatureBytes);

        if (! in_array($platform, self::PLATFORMS, true) || ! in_array($arch, self::ARCHS, true) || ! preg_match('/^\d+\.\d+\.\d+(?:-[A-Za-z0-9.-]+)?$/', $version) || ! preg_match('/^[A-Za-z0-9._-]{1,200}$/', $fileName) || str_contains($fileName, '..')) {
            throw new InvalidArgumentException('release_target_or_filename_invalid');
        }
        $size = filesize($localPath);

        if (! $size || $size > 268435456) {
            throw new InvalidArgumentException('release_size_invalid');
        }

        if ($metadata !== null) {
            $metadata = app(IntegratorUpdateManifest::class)->validate($metadata, (string) $manifestSignature);

            foreach (['version' => $version, 'platform' => $platform, 'arch' => $arch, 'sha256' => $sha256, 'size_bytes' => $size, 'asset_signature' => $signature] as $key => $value) {
                if ($metadata[$key] !== $value) {
                    throw new InvalidArgumentException('manifest_artifact_mismatch');
                }
            }
        }
        $channel = $metadata['channel'] ?? 'stable';
        $cohort  = $metadata['cohort'] ?? 'all';
        $path    = sprintf('integrator-updates/%s/%s/%s/%s/%s', $platform, $arch, $version, $sha256, $fileName);

        return DB::transaction(function () use ($localPath, $version, $platform, $arch, $path, $sha256, $signature, $size, $keepPrevious, $metadata, $manifestSignature, $channel, $cohort) {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('select pg_advisory_xact_lock(hashtext(?))', ['artifact:' . $platform . ':' . $arch . ':' . $version]);
                DB::select('select pg_advisory_xact_lock(hashtext(?))', ['cohort:' . $platform . ':' . $arch . ':' . $channel . ':' . $cohort]);
            }

            if (IntegratorUpdate::where('platform', $platform)->where('arch', $arch)->where('version', $version)->where('sha256', '!=', $sha256)->exists()) {
                throw new InvalidArgumentException('release_immutable_conflict');
            }
            $old = IntegratorUpdate::where('platform', $platform)->where('arch', $arch)->where('version', $version)->where('channel', $channel)->where('cohort', $cohort)->first();

            if ($old) {
                if (! hash_equals($old->sha256, $sha256) || $old->metadata !== $metadata || $old->manifest_signature !== $manifestSignature) {
                    throw new InvalidArgumentException('release_immutable_conflict');
                }$this->verifyObject($old->archive, $sha256, $size);

                return $old;
            }

            if ($metadata !== null && IntegratorUpdate::where('platform', $platform)->where('arch', $arch)->where('channel', $channel)->where('cohort', $cohort)->where('sequence', '>=', $metadata['sequence'])->exists()) {
                throw new InvalidArgumentException('release_sequence_replayed');
            }
            $disk = Storage::disk('s3');

            if (! $disk->exists($path)) {
                $stream = fopen($localPath, 'rb');

                try {
                    if (! $disk->put($path, $stream, ['visibility' => 'private'])) {
                        throw new InvalidArgumentException('release_storage_write_failed');
                    }
                } finally {
                    fclose($stream);
                }
            }
            $this->verifyObject($path, $sha256, $size);

            if (! $keepPrevious) {
                IntegratorUpdate::where('platform', $platform)->where('arch', $arch)->where('channel', $channel)->where('cohort', $cohort)->update(['active' => false]);
            }

            return IntegratorUpdate::create(['platform' => $platform, 'arch' => $arch, 'version' => $version, 'archive' => $path, 'sha256' => $sha256, 'signature' => $signature, 'active' => true, 'metadata' => $metadata, 'manifest_signature' => $manifestSignature, 'release_id' => $metadata['release_id'] ?? null, 'sequence' => $metadata['sequence'] ?? null, 'channel' => $channel, 'cohort' => $cohort]);
        });
    }

    private function verifyObject(string $path, string $hash, int $size): void
    {
        $stream = Storage::disk('s3')->readStream($path);

        if (! is_resource($stream)) {
            throw new InvalidArgumentException('release_storage_unavailable');
        }

        try {
            $ctx        = hash_init('sha256');
            $count      = 0;
            $started    = hrtime(true);
            $emptyReads = 0;
            stream_set_timeout($stream, 10);

            while (! feof($stream)) {
                if (hrtime(true) - $started > 15_000_000_000) {
                    throw new InvalidArgumentException('release_storage_read_timeout');
                }$chunk = fread($stream, 65536);

                if ($chunk === '' && ! feof($stream) && ++$emptyReads > 2) {
                    throw new InvalidArgumentException('release_storage_read_stalled');
                }

                if ($chunk === false) {
                    throw new InvalidArgumentException('release_storage_read_failed');
                }$count += strlen($chunk);

                if ($count > $size) {
                    throw new InvalidArgumentException('release_storage_size_mismatch');
                }hash_update($ctx, $chunk);
            }

            if ($count !== $size || ! hash_equals($hash, hash_final($ctx))) {
                throw new InvalidArgumentException('release_storage_hash_mismatch');
            }
        } finally {
            fclose($stream);
        }
    }

    /**
     * Verifica a assinatura ed25519 contra o SHA-256 recém-calculado do
     * arquivo, usando a chave pública configurada (services.integrator_
     * updates.public_key). Fail-closed: chave ausente/malformada/zerada
     * recusa a publicação, mesma postura de `verify_signature` em
     * integrator/src/updater/mod.rs (lá, a placeholder toda-zero também
     * vira KeyNotConfigured, nunca um "pula a checagem").
     *
     * @throws InvalidArgumentException
     */
    private function verifySignature(string $sha256Hex, string $signatureBytes): void
    {
        $publicKeyHex   = (string) config('services.integrator_updates.public_key');
        $publicKeyBytes = @hex2bin(trim($publicKeyHex));

        if (
            $publicKeyBytes === false
            || strlen($publicKeyBytes) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || $publicKeyBytes === str_repeat("\0", SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)
        ) {
            throw new InvalidArgumentException(
                'services.integrator_updates.public_key não está configurada corretamente '
                . '(esperado hex de 32 bytes, não pode ser a chave zerada) — publicação recusada.',
            );
        }

        // Mensagem assinada = bytes CRUS do digest, nunca a string hex.
        $digestBytes = hex2bin($sha256Hex);

        if (! sodium_crypto_sign_verify_detached($signatureBytes, $digestBytes, $publicKeyBytes)) {
            throw new InvalidArgumentException(
                'Assinatura ed25519 não confere com o SHA-256 do arquivo enviado — publicação recusada.',
            );
        }
    }
}
