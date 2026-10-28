<?php

declare(strict_types=1);

namespace App\Services;

use NumberFormatter;

/**
 * F7 — Soletra inteiros em PT-BR para uso em atestados (paridade smart_oftal).
 *
 * Centraliza dependência de `intl/NumberFormatter` em um único ponto, evitando
 * que múltiplos services (atestado médico, futuras receitas com dosagem por
 * extenso, etc.) reimplementem o fallback.
 */
class DayExtensionService
{
    /**
     * Tabela base PT-BR (até 365 — limite legal de afastamento contínuo).
     *
     * @var array<int, string>
     */
    private const UNITS = [
        1 => 'um', 2 => 'dois', 3 => 'três', 4 => 'quatro', 5 => 'cinco',
        6 => 'seis', 7 => 'sete', 8 => 'oito', 9 => 'nove',
    ];

    /** @var array<int, string> */
    private const TEEN = [
        10 => 'dez', 11 => 'onze', 12 => 'doze', 13 => 'treze', 14 => 'catorze',
        15 => 'quinze', 16 => 'dezesseis', 17 => 'dezessete', 18 => 'dezoito', 19 => 'dezenove',
    ];

    /** @var array<int, string> */
    private const TENS = [
        2 => 'vinte', 3 => 'trinta', 4 => 'quarenta', 5 => 'cinquenta',
        6 => 'sessenta', 7 => 'setenta', 8 => 'oitenta', 9 => 'noventa',
    ];

    /** @var array<int, string> */
    private const HUNDREDS = [
        1 => 'cento', 2 => 'duzentos', 3 => 'trezentos', 4 => 'quatrocentos',
        5 => 'quinhentos', 6 => 'seiscentos', 7 => 'setecentos',
        8 => 'oitocentos', 9 => 'novecentos',
    ];

    /**
     * Soletra um inteiro positivo (1..999). Retorna `'zero'` para valores ≤ 0.
     *
     * Prioriza `intl/NumberFormatter` (qualidade ICU) e cai em fallback PT-BR
     * manual quando a extensão não está disponível (e.g. CI sem ext-intl).
     * Cobertura suficiente para `days` de atestado (1..365).
     */
    public function spell(int $value): string
    {
        if ($value <= 0) {
            return 'zero';
        }

        if (class_exists(NumberFormatter::class)) {
            $formatter = new NumberFormatter('pt_BR', NumberFormatter::SPELLOUT);
            $formatted = $formatter->format($value);

            if (is_string($formatted) && $formatted !== '' && ! ctype_digit($formatted)) {
                return $formatted;
            }
        }

        return $this->spellPtBr($value);
    }

    /**
     * Fallback determinístico PT-BR para inteiros 1..999.
     */
    private function spellPtBr(int $n): string
    {
        if ($n === 100) {
            return 'cem';
        }

        if ($n < 10) {
            return self::UNITS[$n];
        }

        if ($n < 20) {
            return self::TEEN[$n];
        }

        if ($n < 100) {
            $tens = intdiv($n, 10);
            $rest = $n % 10;

            return $rest === 0
                ? self::TENS[$tens]
                : self::TENS[$tens] . ' e ' . self::UNITS[$rest];
        }

        $hundreds = intdiv($n, 100);
        $rest     = $n % 100;

        return $rest === 0
            ? self::HUNDREDS[$hundreds]
            : self::HUNDREDS[$hundreds] . ' e ' . $this->spellPtBr($rest);
    }

    /**
     * Rótulo composto para preview client-side: "3 (três) dia(s)".
     */
    public function format(int $days): string
    {
        $clean = max(1, $days);
        $unit  = $clean === 1 ? 'dia' : 'dias';
        $spelt = $this->spell($clean);

        return sprintf('%d (%s) %s', $clean, $spelt, $unit);
    }
}
