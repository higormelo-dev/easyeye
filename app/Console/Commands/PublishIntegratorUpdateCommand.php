<?php

namespace App\Console\Commands;

use App\Services\IntegratorUpdatePublisher;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Publica um build do EasyEye Integrator para auto-atualização.
 *
 * A assinatura ed25519 é gerada OFFLINE, na máquina que guarda a chave
 * privada (scripts/sign-update.sh no repositório do integrator imprime o
 * sha256, a assinatura em base64 e esta linha de comando pronta). O SaaS
 * nunca vê a chave privada — só armazena a assinatura para o cliente
 * verificar contra a chave pública embutida no binário.
 */
class PublishIntegratorUpdateCommand extends Command
{
    protected $signature = 'integrator:publish-update
        {file : Caminho local do instalador (ex.: EasyEye-Integrator-0.2.0-x86.msi)}
        {--release-version= : Versão semver do build (ex.: 0.2.0)}
        {--platform=windows : Plataforma (windows|linux|macos, como o Rust reporta)}
        {--arch= : Arquitetura (x86|x86_64|aarch64, como o Rust reporta)}
        {--signature= : Assinatura ed25519 em base64 sobre os bytes do digest SHA-256}
        {--keep-previous : Não desativar as versões anteriores da mesma plataforma/arch}';

    protected $description = 'Publica um instalador do integrador no S3 e registra o manifesto de auto-atualização';

    public function handle(IntegratorUpdatePublisher $publisher): int
    {
        $file      = (string) $this->argument('file');
        $version   = (string) $this->option('release-version');
        $platform  = (string) $this->option('platform');
        $arch      = (string) $this->option('arch');
        $signature = (string) $this->option('signature');

        if (! is_file($file)) {
            $this->error("Arquivo não encontrado: {$file}");

            return self::FAILURE;
        }
        foreach (['release-version' => $version, 'arch' => $arch, 'signature' => $signature] as $name => $value) {
            if ($value === '') {
                $this->error("--{$name} é obrigatório.");

                return self::FAILURE;
            }
        }

        try {
            $update = $publisher->publish(
                localPath: $file,
                fileName: basename($file),
                version: $version,
                platform: $platform,
                arch: $arch,
                signature: $signature,
                keepPrevious: (bool) $this->option('keep-previous'),
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Publicado: {$platform}/{$arch} {$version} (sha256 {$update->sha256})");

        return self::SUCCESS;
    }
}
