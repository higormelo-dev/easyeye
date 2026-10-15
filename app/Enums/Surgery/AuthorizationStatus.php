<?php

declare(strict_types=1);

namespace App\Enums\Surgery;

/**
 * Estado ATUAL da autorização do caso (desnormalizado de surgical_authorization_events).
 */
enum AuthorizationStatus: string
{
    case NotRequested        = 'not_requested';
    case Pending             = 'pending';
    case Authorized          = 'authorized';
    case PartiallyAuthorized = 'partially_authorized';
    case Denied              = 'denied';
    case Expired             = 'expired';
    case Waived              = 'waived';

    public function label(): string
    {
        return __('surgery_enums.authorization_status.' . $this->value);
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

    /** Libera o agendamento da cirurgia. */
    public function allowsScheduling(): bool
    {
        return in_array($this, [self::Authorized, self::PartiallyAuthorized, self::Waived], true);
    }
}
