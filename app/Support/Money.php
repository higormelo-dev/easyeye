<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Aritmética de dinheiro em centavos inteiros.
 *
 * As colunas são decimal(12,2) (o PostgreSQL devolve string, ex.: "123.45");
 * cálculos de repasse (percentual, somas) acontecem em centavos para não
 * acumular erro de ponto flutuante — o total de um fechamento é sempre a soma
 * exata dos itens arredondados.
 */
final class Money
{
    /** "123.45" | 123.45 | null → 12345 */
    public static function toCents(string|int|float|null $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        return (int) round(((float) $value) * 100);
    }

    /** 12345 → "123.45" (formato de coluna decimal, sem separador de milhar). */
    public static function fromCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs  = abs($cents);

        return sprintf('%s%d.%02d', $sign, intdiv($abs, 100), $abs % 100);
    }

    /**
     * Percentual (ex.: "60.00") de um valor em centavos, com meio centavo
     * arredondado para cima (half-up) em aritmética inteira.
     */
    public static function percentageOf(int $cents, string|int|float $percentage): int
    {
        $basisPoints = (int) round(((float) $percentage) * 100); // 60.00% → 6000
        $product     = $cents * $basisPoints;                     // centavos × pontos-base
        $sign        = $product < 0 ? -1 : 1;

        return $sign * intdiv(abs($product) + 5000, 10000);
    }
}
