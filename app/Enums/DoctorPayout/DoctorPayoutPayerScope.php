<?php

declare(strict_types=1);

namespace App\Enums\DoctorPayout;

/**
 * Pagador coberto por uma regra de repasse.
 *
 * Particular = atendimento sem convênio ou com convênio sem registro ANS
 * (mesma regra de ResolveTissOperatorForCovenantAction::isEligible, usada no
 * faturamento). Covenant = convênio com registro ANS; com covenant_id, só
 * aquele convênio (que pode ser inclusive o "PARTICULAR").
 */
enum DoctorPayoutPayerScope: string
{
    case Any        = 'any';
    case Particular = 'particular';
    case Covenant   = 'covenant';

    public function label(): string
    {
        return __("financial_doctor_payouts.payer_scopes.{$this->value}");
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
