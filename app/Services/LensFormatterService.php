<?php

namespace App\Services;

/**
 * Formata componentes de refração óptica seguindo convenção oftalmológica:
 *   - Esférico: sinal explícito (+1.25 / -2.50 / 0.00), step 0.25
 *   - Cilíndrico: sempre negativo por convenção (-1.25), step 0.25
 *   - Eixo: inteiro 0..180 com sufixo º
 *
 * Substitui o endpoint legado `patients.formatlense` do smart_oftal.
 */
class LensFormatterService
{
    public const KIND_SPHERICAL = 'spherical';

    public const KIND_CYLINDRICAL = 'cylindrical';

    public const KIND_AXIS = 'axis';

    public function format(string $kind, ?string $value): string
    {
        if (blank($value)) {
            return '';
        }

        $normalized = trim(str_replace([',', '°', 'º', ' '], ['.', '', '', ''], $value));

        return match ($kind) {
            self::KIND_SPHERICAL   => $this->formatDiopter($normalized, signed: true),
            self::KIND_CYLINDRICAL => $this->formatDiopter($normalized, signed: false),
            self::KIND_AXIS        => $this->formatAxis($normalized),
            default                => $value,
        };
    }

    /**
     * @param bool $signed true → mostra sinal explícito (esférico). false → força negativo (cilíndrico).
     */
    private function formatDiopter(string $raw, bool $signed): string
    {
        if (! is_numeric($raw)) {
            return $raw;
        }

        // arredonda ao step de 0.25 dioptria
        $rounded = round(((float) $raw) * 4) / 4;

        if ($rounded === 0.0) {
            return '0.00';
        }

        $abs = number_format(abs($rounded), 2, '.', '');

        if ($signed) {
            return ($rounded > 0 ? '+' : '-') . $abs;
        }

        // cilíndrico em convenção negativa
        return '-' . $abs;
    }

    private function formatAxis(string $raw): string
    {
        if (! is_numeric($raw)) {
            return $raw;
        }

        $int = (int) round((float) $raw);
        $int = max(0, min(180, $int));

        return $int . 'º';
    }
}
