<?php

namespace App\Enums;

enum PaperSize: string
{
    case A4     = 'A4';
    case A5     = 'A5';
    case Letter = 'Letter';
    case Legal  = 'Legal';

    public function label(): string
    {
        return match ($this) {
            self::A4     => 'A4 (210 × 297 mm)',
            self::A5     => 'A5 (148 × 210 mm)',
            self::Letter => 'Letter (215,9 × 279,4 mm)',
            self::Legal  => 'Legal (215,9 × 355,6 mm)',
        };
    }

    /** Dimensões em mm [largura, altura] — útil para geração de PDF. */
    public function dimensions(): array
    {
        return match ($this) {
            self::A4     => [210, 297],
            self::A5     => [148, 210],
            self::Letter => [216, 279],
            self::Legal  => [216, 356],
        };
    }

    /** Retorna os valores aceitos para usar em regras de validação. */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
