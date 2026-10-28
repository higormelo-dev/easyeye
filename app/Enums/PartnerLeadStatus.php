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
        return __('partners.lead_status.' . $this->value);
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::New       => 'badge-soft-secondary rounded fs-13 fw-medium',
            self::Contacted => 'badge-soft-info rounded text-info border border-info fs-13 fw-medium',
            self::Trial     => 'badge-soft-warning rounded text-warning border border-warning fs-13 fw-medium',
            self::Converted => 'badge-soft-success rounded text-success border border-success fs-13 fw-medium',
            self::Lost      => 'badge-soft-danger rounded text-danger border border-danger fs-13 fw-medium',
        };
    }

    public function isActive(): bool
    {
        return in_array($this, [self::New, self::Contacted, self::Trial]);
    }
}
