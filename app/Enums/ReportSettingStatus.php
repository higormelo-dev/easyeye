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
            self::Draft     => 'bg-warning text-dark',
            self::Published => 'bg-success',
            self::Archived  => 'bg-secondary',
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
