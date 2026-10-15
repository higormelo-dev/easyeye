<?php

declare(strict_types=1);

namespace App\Enums\Surgery;

/**
 * Anestesia planejada/usada na cirurgia oftalmológica.
 */
enum AnesthesiaType: string
{
    case Topical           = 'topical';
    case TopicalSedation   = 'topical_sedation';
    case Peribulbar        = 'peribulbar';
    case Retrobulbar       = 'retrobulbar';
    case SubTenon          = 'sub_tenon';
    case LocalInfiltration = 'local_infiltration';
    case General           = 'general';

    public function label(): string
    {
        return __('surgery_enums.anesthesia_type.' . $this->value);
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
