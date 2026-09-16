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
    ): IntegratorUpdate {
        $signatureBytes = base64_decode($signature, true);

        if ($signatureBytes === false || strlen($signatureBytes) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new InvalidArgumentException(
                'Assinatura inválida: esperada ed25519 em base64 (64 bytes decodificados).',
            );
        }

        $sha256 = hash_file('sha256', $localPath);

        $this->verifySignature($sha256, $signatureBytes);

        $path = sprintf('integrator-updates/%s/%s', $version, $fileName);

        Storage::disk('s3')->put($path, fopen($localPath, 'rb'));

        return DB::transaction(function () use ($version, $platform, $arch, $path, $sha256, $signature, $keepPrevious) {
            if (! $keepPrevious) {
                IntegratorUpdate::query()
                    ->where('platform', $platform)
                    ->where('arch', $arch)
                    ->update(['active' => false]);
            }

            return IntegratorUpdate::updateOrCreate(
                ['platform' => $platform, 'arch' => $arch, 'version' => $version],
                ['archive' => $path, 'sha256' => $sha256, 'signature' => $signature, 'active' => true],
            );
        });
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
