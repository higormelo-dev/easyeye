<?php

declare(strict_types=1);

namespace App\Enums\Surgery;

/**
 * Evento do log imutável de autorização (surgical_authorization_events).
 */
enum AuthorizationEventType: string
{
    case Requested           = 'requested';
    case Submitted           = 'submitted';
    case Authorized          = 'authorized';
    case PartiallyAuthorized = 'partially_authorized';
    case Denied              = 'denied';
    case Appealed            = 'appealed';
    case Expired             = 'expired';
    case Waived              = 'waived';
    case Cancelled           = 'cancelled';
    case Note                = 'note';

    public function label(): string
    {
        return __('surgery_enums.authorization_event_type.' . $this->value);
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

    /** Estado do caso que o evento produz; null = não muda o estado (anotação, envio). */
    public function resultingStatus(): ?AuthorizationStatus
    {
        return match ($this) {
            self::Requested, self::Appealed => AuthorizationStatus::Pending,
            self::Authorized          => AuthorizationStatus::Authorized,
            self::PartiallyAuthorized => AuthorizationStatus::PartiallyAuthorized,
            self::Denied              => AuthorizationStatus::Denied,
            self::Expired             => AuthorizationStatus::Expired,
            self::Waived              => AuthorizationStatus::Waived,
            self::Cancelled           => AuthorizationStatus::NotRequested,
            self::Submitted, self::Note => null,
        };
    }
}
