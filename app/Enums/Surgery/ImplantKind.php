<?php

declare(strict_types=1);

namespace App\Enums\Surgery;

/**
 * Tipo do implante registrado na cirurgia: lente intraocular ou outro material implantável (anel, dispositivo...).
 */
enum ImplantKind: string
{
    case Iol   = 'iol';
    case Other = 'other';

    public function label(): string
    {
        return __('surgery_operation.enums.implant_kind.' . $this->value);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
