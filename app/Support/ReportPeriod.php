<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use Illuminate\Support\Str;

/**
 * Filtros de período/ID vindos da query string das telas financeiras.
 *
 * Antes cada controller repassava `from`/`to` crus para o whereBetween/whereDate
 * e um valor inválido (`from=abc`, `from[]=x`) virava erro 500 do PostgreSQL.
 * Aqui: data inválida ou ausente cai no padrão (mês atual), período invertido é
 * trocado e IDs que não são UUID viram null — nunca chegam ao banco.
 */
final class ReportPeriod
{
    /**
     * @return array{0: string, 1: string} [from, to] em Y-m-d
     */
    public static function resolve(mixed $from, mixed $to, ?string $defaultFrom = null, ?string $defaultTo = null): array
    {
        $from = self::validDate($from) ?? $defaultFrom ?? now()->startOfMonth()->toDateString();
        $to   = self::validDate($to) ?? $defaultTo ?? now()->toDateString();

        return $from <= $to ? [$from, $to] : [$to, $from];
    }

    /** Data estritamente Y-m-d existente, a partir de 1900 (2026-02-30 e 0000-01-01 são inválidas); resto → null. */
    public static function validDate(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        // Ano entre 1900 e 9999: '0000-01-01' "passa" no round-trip do formato,
        // mas o PostgreSQL não tem ano 0 (SQLSTATE 22008 → erro 500).
        return $date !== false
            && $date->format('Y-m-d') === $value
            && (int) $date->format('Y') >= 1900
            ? $value
            : null;
    }

    /** UUID válido (ex.: covenant_id do filtro) ou null — evita "invalid input syntax for type uuid". */
    public static function uuidOrNull(mixed $value): ?string
    {
        return is_string($value) && Str::isUuid($value) ? $value : null;
    }

    /** Texto de filtro/enum vindo da query: só string, com trim; arrays e afins → default. */
    public static function text(mixed $value, string $default = ''): string
    {
        return is_string($value) ? trim($value) : $default;
    }
}
