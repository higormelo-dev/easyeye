<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Enums;

enum TissGuideType: string
{
    case Consultation = 'consultation';
    case Sadt         = 'sadt';

    public function xmlTag(): string
    {
        return match ($this) {
            self::Consultation => 'guiaConsulta',
            self::Sadt         => 'guiaSP-SADT',
        };
    }
}
