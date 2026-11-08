<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Services\Transport;

use App\Domains\Tiss\Exceptions\{TissCredentialIncompleteException, TissEndpointNotAllowedException};
use App\Domains\Tiss\Models\TissEntityOperatorCredential;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\{Crypt, Http, Storage};
use Throwable;

/**
 * Cliente HTTP autenticado com a credencial TISS da clínica: o mesmo para o envio (HttpTissTransportClient) e o
 * teste de conexão da tela de credenciais — o teste vale porque usa exatamente o que o envio usa.
 *
 *  - TLS: verifica o certificado do servidor conforme `ssl_enabled`;
 *  - certificado do cliente (mTLS): arquivo no disco privado `tiss-certificates`. PKCS#12 (.pfx/.p12, o comum no
 *    ICP-Brasil A1) ou PEM com a chave à parte; a senha do certificado vale para a chave;
 *  - usuário + senha (Basic) e/ou token (Bearer), decifrados só aqui, na hora da requisição;
 *  - destino: TissEndpointGuard (só IP público em produção, IP fixado na conexão) e sem seguir redirecionamento,
 *    que levaria a requisição (e a autenticação) a um endereço não verificado.
 */
final class TissHttpClientFactory
{
    public const DISK = 'tiss-certificates';

    /**
     * @throws TissEndpointNotAllowedException   destino recusado (nada sai)
     * @throws TissCredentialIncompleteException certificado salvo não está no disco (nada sai)
     */
    public static function make(TissEntityOperatorCredential $credential, string $url, int $timeout, int $connectTimeout): PendingRequest
    {
        $options = ['allow_redirects' => false, ...self::tlsOptions($credential)];
        $ips     = TissEndpointGuard::check($url);
        $host    = (string) parse_url($url, PHP_URL_HOST);

        if ($ips !== [] && filter_var($host, FILTER_VALIDATE_IP) === false) {
            $port            = parse_url($url, PHP_URL_PORT) ?: (strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https' ? 443 : 80);
            $options['curl'] = ($options['curl'] ?? []) + [CURLOPT_RESOLVE => [$host . ':' . $port . ':' . implode(',', $ips)]];
        }

        $request = Http::timeout($timeout)
            ->connectTimeout($connectTimeout)
            ->withOptions($options);

        if (filled($credential->username) && filled($credential->password_encrypted)) {
            $request = $request->withBasicAuth((string) $credential->username, self::decrypt((string) $credential->password_encrypted));
        }

        if (filled($credential->token_encrypted)) {
            $request = $request->withToken(self::decrypt((string) $credential->token_encrypted));
        }

        return $request;
    }

    /**
     * Opções do Guzzle para TLS e certificado do cliente. Pública para teste (nenhuma rede envolvida).
     *
     * @return array<string, mixed>
     */
    public static function tlsOptions(TissEntityOperatorCredential $credential): array
    {
        $options = ['verify' => (bool) $credential->ssl_enabled];

        if (blank($credential->certificate_path)) {
            return $options;
        }

        $disk     = Storage::disk(self::DISK);
        $password = filled($credential->certificate_password_encrypted)
            ? self::decrypt((string) $credential->certificate_password_encrypted)
            : null;
        $withPassword = fn (string $path): string|array => $password !== null ? [$path, $password] : $path;

        foreach (array_filter([(string) $credential->certificate_path, (string) $credential->private_key_path]) as $file) {
            if (! $disk->exists($file)) {
                throw new TissCredentialIncompleteException((string) __('tiss_credentials.errors.certificate_missing'));
            }
        }

        // PKCS#12 é gravado como .p12: o Guzzle já usa o tipo P12 pela extensão.
        $options['cert'] = $withPassword($disk->path((string) $credential->certificate_path));

        if (filled($credential->private_key_path)) {
            $options['ssl_key'] = $withPassword($disk->path((string) $credential->private_key_path));
        }

        return $options;
    }

    public static function isPkcs12(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['pfx', 'p12'], true);
    }

    private static function decrypt(string $value): string
    {
        try {
            return Crypt::decryptString($value);
        } catch (Throwable) {
            // Compatibilidade com bases antigas que podem armazenar em texto puro.
            return $value;
        }
    }
}
