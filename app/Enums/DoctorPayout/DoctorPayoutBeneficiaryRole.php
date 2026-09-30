<?php

declare(strict_types=1);

namespace App\Enums\DoctorPayout;

/**
 * Papel do beneficiário numa parcela (E4): executor (médico do item),
 * participante fixo da regra (ex.: líder do grupo) ou os dois (o líder que
 * executou o próprio atendimento).
 */
enum DoctorPayoutBeneficiaryRole: string
{
    case Executor = 'executor';

    case Doctor = 'doctor';

    case Both = 'both';

    public function label(): string
    {
        return __("financial_doctor_payouts.beneficiary_roles.{$this->value}");
    }
}
