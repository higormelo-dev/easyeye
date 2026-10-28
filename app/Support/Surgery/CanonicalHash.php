<?php

declare(strict_types=1);

namespace App\Support\Surgery;

use BackedEnum;
use DateTimeInterface;

/**
 * Hash SHA-256 de um conteúdo estruturado, independente da ordem das chaves.
 *
 * Mapas (chaves de texto) são ordenados por chave, recursivamente; listas
 * preservam a ordem (a ordem de uma lista É conteúdo: itens do checklist,
 * colírios). Escalares viram texto — `1`, `'1'` e `true` NÃO se confundem:
 * o tipo entra na serialização. O resultado é o que fica gravado como
 * `content_hash` dos documentos e fechamentos congelados; mudar o algoritmo
 * invalida hashes antigos, por isso `VERSION` acompanha quem grava.
 */
final class CanonicalHash
{
    public const VERSION = 1;

    /** @param array<array-key, mixed>|string|int|float|bool|null $value */
    public static function of(mixed $value): string
    {
        return hash('sha256', self::canonicalJson($value));
    }

    /** @param array<array-key, mixed>|string|int|float|bool|null $value */
    public static function canonicalJson(mixed $value): string
    {
        return json_encode(
            self::normalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );
    }

    private static function normalize(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(\DATE_ATOM);
        }

        if (! is_array($value)) {
            return $value;
        }

        $normalized = array_map(self::normalize(...), $value);

        if (array_is_list($normalized)) {
            return $normalized;
        }

        ksort($normalized, SORT_STRING);

        return $normalized;
    }
}
