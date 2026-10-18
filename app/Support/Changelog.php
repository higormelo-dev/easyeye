<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Str;
use Mews\Purifier\Facades\Purifier;

/**
 * Página "Novidades": lê resources/changelog/{pt_BR,en}.md.
 *
 * Contrato do arquivo: cada versão abre com `## 1.2.3 — data` (o `v` é opcional),
 * versões novas no topo — a PRIMEIRA ocorrência é a versão atual. O pt_BR é a
 * fonte da verdade da versão (o en é a tradução). O badge do menu aparece
 * enquanto `users.changelog_seen_version` for diferente da versão atual.
 *
 * O conteúdo é do repositório, mas o HTML sai em modo seguro mesmo assim:
 * Markdown sem HTML cru e sem links perigosos, depois o purificador do projeto.
 */
final class Changelog
{
    private const string FALLBACK_LOCALE = 'pt_BR';

    private const string VERSION_PATTERN = '/^##\s+v?(\d+\.\d+\.\d+)\b/m';

    /** Primeira versão `## x.y.z` do texto (a mais nova), ou null se não houver título de versão. */
    public static function versionFromMarkdown(string $markdown): ?string
    {
        return preg_match(self::VERSION_PATTERN, $markdown, $match) === 1 ? $match[1] : null;
    }

    /** Versão atual do produto (pt_BR; en como reserva). */
    public static function latestVersion(): ?string
    {
        foreach ([self::FALLBACK_LOCALE, 'en'] as $locale) {
            $markdown = self::read($locale);

            if ($markdown !== null && ($version = self::versionFromMarkdown($markdown)) !== null) {
                return $version;
            }
        }

        return null;
    }

    /** Markdown do idioma pedido (pt_BR quando não há arquivo para ele); null se nenhum arquivo existe. */
    public static function markdown(?string $locale): ?string
    {
        return self::read($locale ?? self::FALLBACK_LOCALE) ?? self::read(self::FALLBACK_LOCALE);
    }

    /** HTML pronto para exibir (já purificado); null quando não há conteúdo. */
    public static function html(?string $locale): ?string
    {
        $markdown = self::markdown($locale);

        return $markdown === null || trim($markdown) === '' ? null : self::render($markdown);
    }

    /**
     * Markdown → HTML em modo seguro: HTML cru descartado, links perigosos
     * (javascript:, data:...) neutralizados e, por fim, o purificador do projeto.
     */
    public static function render(string $markdown): string
    {
        $html = Str::markdown($markdown, [
            'html_input'         => 'strip',
            'allow_unsafe_links' => false,
        ]);

        return Purifier::clean($html, 'medical');
    }

    /** O usuário ainda não abriu a versão atual das Novidades? */
    public static function hasUnseen(?User $user): bool
    {
        $latest = self::latestVersion();

        return $latest !== null && $user?->changelog_seen_version !== $latest;
    }

    private static function read(string $locale): ?string
    {
        // Só o código de idioma vira nome de arquivo: nada de caminho vindo de fora.
        if (preg_match('/^[A-Za-z_]{2,10}$/', $locale) !== 1) {
            return null;
        }

        $path = resource_path("changelog/{$locale}.md");

        if (! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return $contents === false ? null : $contents;
    }
}
