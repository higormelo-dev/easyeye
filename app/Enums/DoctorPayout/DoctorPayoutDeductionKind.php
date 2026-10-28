<?php

declare(strict_types=1);

namespace App\Enums\DoctorPayout;

/**
 * Dedução sobre o recebido antes de dividir (E4): cada uma sobre o valor
 * BRUTO do recebimento, sem cascata, com a taxa vigente na data do
 * recebimento (doctor_payout_deduction_rates).
 */
enum DoctorPayoutDeductionKind: string
{
    /** Taxa do cartão de débito — só a parte paga com débito no balcão. */
    case CardDebit = 'card_debit';

    /** Taxa do cartão de crédito — só a parte paga com crédito no balcão. */
    case CardCredit = 'card_credit';

    /** Imposto sobre o recebido (alíquota única). */
    case Tax = 'tax';

    /** Taxa administrativa (% do recebido). */
    case Admin = 'admin';

    public function label(): string
    {
        return __("financial_doctor_payouts.deduction_kinds.{$this->value}");
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $kind) => $kind->value, self::cases());
    }
}
