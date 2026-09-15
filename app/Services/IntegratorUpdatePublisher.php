<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\IntegratorUpdate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Publica um build do EasyEye Integrator para auto-atualização — lógica
 * compartilhada entre o comando `integrator:publish-update` (CLI) e a página
 * do Manager (upload via navegador).
 *
 * A assinatura ed25519 chega PRONTA (gerada offline por
 * scripts/sign-update.sh, no repositório do integrator, com a chave privada
 * que nunca passa pelo SaaS). O cliente desktop rejeita qualquer download
 * cuja assinatura não verifique contra a chave pública embutida no binário,
 * então validar o formato aqui evita publicar uma atualização impossível de
 * instalar.
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
     *                                  de uma ed25519 (base64 de 64 bytes)
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
        $decoded = base64_decode($signature, true);
        if ($decoded === false || strlen($decoded) !== 64) {
            throw new InvalidArgumentException(
                'Assinatura inválida: esperada ed25519 em base64 (64 bytes decodificados).',
            );
        }

        $sha256 = hash_file('sha256', $localPath);
        $path   = sprintf('integrator-updates/%s/%s', $version, $fileName);

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
}
