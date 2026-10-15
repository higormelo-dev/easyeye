<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Support;

/**
 * dm_UF do TISS é o código IBGE numérico da UF (35 = SP), não a sigla.
 */
final class IbgeUf
{
    private const CODES = [
        'RO' => '11', 'AC' => '12', 'AM' => '13', 'RR' => '14', 'PA' => '15', 'AP' => '16', 'TO' => '17',
        'MA' => '21', 'PI' => '22', 'CE' => '23', 'RN' => '24', 'PB' => '25', 'PE' => '26', 'AL' => '27', 'SE' => '28', 'BA' => '29',
        'MG' => '31', 'ES' => '32', 'RJ' => '33', 'SP' => '35',
        'PR' => '41', 'SC' => '42', 'RS' => '43',
        'MS' => '50', 'MT' => '51', 'GO' => '52', 'DF' => '53',
    ];

    /** Null quando a sigla é desconhecida (mais seguro que um código inválido). */
    public static function code(?string $abbreviation): ?string
    {
        return self::CODES[mb_strtoupper(trim((string) $abbreviation))] ?? null;
    }
}
