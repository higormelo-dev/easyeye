<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

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
     * Divide um valor em `parts` parcelas inteiras que somam exatamente o
     * total; o resto (em centavos) vai, um a um, para as primeiras parcelas.
     * Rateio determinístico: a ordem das parcelas é a do chamador.
     *
     * @return list<int>
     */
    public static function allocate(int $cents, int $parts): array
    {
        if ($parts < 1) {
            throw new InvalidArgumentException('Money::allocate precisa de ao menos 1 parcela.');
        }

        $sign = $cents < 0 ? -1 : 1;
        $abs  = abs($cents);
        $base = intdiv($abs, $parts);
        $rest = $abs % $parts;

        return array_map(fn (int $index) => $sign * ($base + ($index < $rest ? 1 : 0)), range(0, $parts - 1));
    }

    /**
     * cents × numerator ÷ denominator com meio centavo para cima (half-up),
     * sem ponto flutuante — ex.: regra de valor fixo proporcional ao recebido
     * (fixo × recebido ÷ esperado). Inteiro puro nos valores reais de clínica;
     * bcmath só se o produto passar do limite de 64 bits.
     */
    public static function proportion(int $cents, int $numerator, int $denominator): int
    {
        if ($denominator <= 0) {
            throw new InvalidArgumentException('Money::proportion precisa de denominador positivo.');
        }

        if ($cents === 0 || $numerator === 0) {
            return 0;
        }

        $sign         = ($cents < 0) !== ($numerator < 0) ? -1 : 1;
        $absCents     = abs($cents);
        $absNumerator = abs($numerator);

        if ($absCents <= intdiv(PHP_INT_MAX - $denominator, 2 * $absNumerator)) {
            return $sign * intdiv($absCents * $absNumerator * 2 + $denominator, 2 * $denominator);
        }

        $scaled = bcadd(bcmul(bcmul((string) $absCents, (string) $absNumerator), '2'), (string) $denominator);

        return $sign * (int) bcdiv($scaled, bcmul('2', (string) $denominator), 0);
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
