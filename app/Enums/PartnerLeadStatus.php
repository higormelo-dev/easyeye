<?php

namespace App\Enums;

enum PartnerLeadStatus: string
{
    case New       = 'new';
    case Contacted = 'contacted';
    case Trial     = 'trial';
    case Converted = 'converted';
    case Lost      = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::New       => 'Novo',
            self::Contacted => 'Contactado',
            self::Trial     => 'Em Trial',
            self::Converted => 'Convertido',
            self::Lost      => 'Perdido',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::New       => 'bg-secondary',
            self::Contacted => 'bg-info',
            self::Trial     => 'bg-warning text-dark',
            self::Converted => 'bg-success',
            self::Lost      => 'bg-danger',
        };
    }

    public function isActive(): bool
    {
        return in_array($this, [self::New, self::Contacted, self::Trial]);
    }
}
