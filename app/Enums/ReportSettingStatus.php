<?php

namespace App\Enums;

enum ReportSettingStatus: string
{
    case Draft     = 'draft';
    case Published = 'published';
    case Archived  = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft     => __('report_settings.status.draft'),
            self::Published => __('report_settings.status.published'),
            self::Archived  => __('report_settings.status.archived'),
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Draft     => 'badge-soft-warning rounded text-warning border border-warning fs-13 fw-medium',
            self::Published => 'badge-soft-success rounded text-success border border-success fs-13 fw-medium',
            self::Archived  => 'badge-soft-secondary rounded fs-13 fw-medium',
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Draft     => $target === self::Published,
            self::Published => $target === self::Archived,
            self::Archived  => $target === self::Published,
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
