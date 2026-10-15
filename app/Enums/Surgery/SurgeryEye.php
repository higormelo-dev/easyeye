<?php

declare(strict_types=1);

namespace App\Enums\Surgery;

/**
 * Olho operado. `ao` = ambos (cirurgia simultânea bilateral, rara).
 */
enum SurgeryEye: string
{
    case Right = 'od';
    case Left  = 'oe';
    case Both  = 'ao';

    public function label(): string
    {
        return __('surgery_enums.surgery_eye.' . $this->value);
    }

    /** Sigla do olho no idioma do usuário (pt: OD/OE/AO; en: OD/OS/OU) — a mesma sigla do rótulo longo. */
    public function short(): string
    {
        return __('surgery_enums.surgery_eye_short.' . $this->value);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return array<string, string> valor => rótulo no idioma atual */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
