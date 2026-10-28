<?php

declare(strict_types=1);

namespace App\Support\Fiscal;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Links e caminhos que a Focus devolve (espelho da nota, PDF, XML) viram `href` no Manager e na clínica e destino de download
 * do servidor: nada que a API (ou quem a imitar no webhook) mande entra sem passar por aqui.
 *
 *  - `url` / `url_danfse`: só https, host dentro de `fiscal.allowed_url_hosts` (padrões com `*`), sem usuário/senha na URL,
 *    sem porta e com tamanho razoável. `javascript:`, `data:`, http e host estranho são DESCARTADOS (o campo some do payload
 *    e da nota) e o descarte vai ao log com o campo e o host — nunca com a URL inteira;
 *  - `caminho_xml_*`: só `/arquivos/...` relativo, sem `..` (o mesmo formato que FocusNfeClient::download aceita).
 */
final class NfseUrlGuard
{
    private const URL_FIELDS = ['url', 'url_danfse'];

    private const PATH_FIELDS = ['caminho_xml_nota_fiscal', 'caminho_xml_cancelamento'];

    public static function isAllowedUrl(mixed $url): bool
    {
        if (! is_string($url) || $url === '' || strlen($url) > 2000) {
            return false;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($host === '' || preg_match('/^[a-z0-9.-]+$/', $host) !== 1) {
            return false;
        }

        foreach ((array) config('fiscal.allowed_url_hosts', []) as $pattern) {
            if (Str::is(strtolower((string) $pattern), $host)) {
                return true;
            }
        }

        return false;
    }

    /** O link, se for permitido; senão null (para a saída: linha já gravada com URL ruim nunca vira href). */
    public static function safeUrl(?string $url): ?string
    {
        return self::isAllowedUrl($url) ? $url : null;
    }

    public static function isAllowedPath(mixed $path): bool
    {
        return is_string($path) && preg_match('#^/arquivos/[A-Za-z0-9_\-./]+$#', $path) === 1 && ! str_contains($path, '..');
    }

    /**
     * Devolve o payload sem os links/caminhos inválidos (e loga o descarte).
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public static function clean(array $payload, string $ref): array
    {
        foreach (self::URL_FIELDS as $field) {
            if (array_key_exists($field, $payload) && $payload[$field] !== null && ! self::isAllowedUrl($payload[$field])) {
                Log::warning('nfse.sync.url_rejected', ['ref' => $ref, 'field' => $field, 'host' => is_string($payload[$field]) ? (string) parse_url($payload[$field], PHP_URL_HOST) : null]);

                unset($payload[$field]);
            }
        }

        foreach (self::PATH_FIELDS as $field) {
            if (array_key_exists($field, $payload) && $payload[$field] !== null && ! self::isAllowedPath($payload[$field])) {
                Log::warning('nfse.sync.path_rejected', ['ref' => $ref, 'field' => $field]);

                unset($payload[$field]);
            }
        }

        return $payload;
    }
}
