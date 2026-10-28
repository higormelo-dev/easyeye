<?php

declare(strict_types=1);

namespace App\Support\Fiscal;

/**
 * Tira de um payload (enviado ou recebido) tudo que parece segredo, antes de gravá-lo em `request_payload` /
 * `response_payload`. O token da Focus nunca entra num corpo (vai só no cabeçalho Basic), então isto é a segunda
 * barreira: se algum dia um campo de credencial aparecer (na resposta da Focus, num hook ecoado, num erro), ele não chega
 * ao banco, à auditoria nem à tela. Recursivo; compara o NOME da chave sem diferenciar maiúsculas.
 */
final class NfsePayloadSanitizer
{
    /** Trechos de nome de chave que indicam segredo. */
    private const SENSITIVE = ['token', 'authorization', 'secret', 'password', 'senha', 'api_key', 'apikey'];

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    public static function clean(array $data): array
    {
        $clean = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitive($key)) {
                continue;
            }

            $clean[$key] = is_array($value) ? self::clean($value) : $value;
        }

        return $clean;
    }

    private static function isSensitive(string $key): bool
    {
        $name = strtolower($key);

        foreach (self::SENSITIVE as $fragment) {
            if (str_contains($name, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
