<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Services\Transport;

use App\Domains\Tiss\Exceptions\TissEndpointNotAllowedException;

/**
 * O endereço do webservice vem do administrador da clínica: sem esta trava ele poderia fazer o servidor chamar a
 * rede interna (SSRF). Vale para o envio e para o teste de conexão (TissHttpClientFactory).
 *
 *  - só http/https, sem usuário/senha na URL (segredo fica nos campos cifrados);
 *  - o host precisa resolver só para IP público; os IPs verificados são devolvidos para fixar a conexão
 *    (CURLOPT_RESOLVE), então trocar o DNS entre a verificação e a conexão não leva a outro destino.
 *
 * Vale em todo ambiente (o servidor de teste também é público). A rede interna só é liberada fora de produção e com
 * TISS_ALLOW_PRIVATE_ENDPOINTS=true (simulador de operadora na máquina do desenvolvedor, testes automatizados).
 */
final class TissEndpointGuard
{
    /**
     * @return list<string> IPs verificados (vazio com a rede interna liberada)
     *
     * @throws TissEndpointNotAllowedException
     */
    public static function check(string $url): array
    {
        $parts  = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host   = trim((string) ($parts['host'] ?? ''), '[]');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new TissEndpointNotAllowedException('invalid');
        }

        if (self::privateNetworkAllowed()) {
            return [];
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : (gethostbynamel($host) ?: []);

        if ($ips === []) {
            throw new TissEndpointNotAllowedException('unresolvable');
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
                throw new TissEndpointNotAllowedException('private');
            }
        }

        return array_values($ips);
    }

    public static function privateNetworkAllowed(): bool
    {
        return ! app()->isProduction() && (bool) config('tiss.transport.http.allow_private_endpoints');
    }
}
