<?php

declare(strict_types=1);

namespace App\Domains\Recall\Enums;

/**
 * Por que um convite de retorno por WhatsApp NÃO sai (ou não saiu). O código é gravado em
 * `whatsapp_messages.error_code` (`recall_<código>`) quando o envio é cancelado e aparece na prévia do convite.
 *
 * Três níveis de chave (kill-switch), do mais amplo ao mais estreito: PAUSA GLOBAL do EasyEye (Manager),
 * CHAVE DA CLÍNICA no Manager (`whatsapp_settings.recall_enabled`) e CHAVE DA CLÍNICA nas configurações do
 * recall (`recall_clinic_settings.whatsapp_invites_enabled`).
 */
enum InviteBlocker: string
{
    // Canal (a clínica)
    case PausedGlobal     = 'paused_global';
    case FeatureOff       = 'feature_off';
    case ClinicNotEnabled = 'clinic_not_enabled';
    case NoSender         = 'no_sender';
    case AccessBlocked    = 'access_blocked';
    case ClinicSwitchOff  = 'clinic_switch_off';
    // Paciente
    case PatientUnavailable = 'patient_unavailable';
    case ContactBlocked     = 'contact_blocked';
    case NoConsent          = 'no_consent';
    case NoWhatsapp         = 'no_whatsapp';
    case InvalidPhone       = 'invalid_phone';
    case OptedOut           = 'opted_out';
    // Retorno
    case NothingToInvite = 'nothing_to_invite';
    // Ritmo
    case OutsideWindow = 'outside_window';
    case TooSoon       = 'too_soon';
    case DailyCap      = 'daily_cap';

    public function label(): string
    {
        return __('recall_invite.blockers.' . $this->value);
    }

    /** Códigos que só valem na CRIAÇÃO da onda (o ritmo): o envio já enfileirado não os reconfere. */
    public function isPacing(): bool
    {
        return in_array($this, [self::OutsideWindow, self::TooSoon, self::DailyCap], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $blocker): string => $blocker->value, self::cases());
    }
}
