<?php

declare(strict_types=1);

namespace App\Enums\DoctorPayout;

/**
 * Regime de cálculo de um fechamento / item de repasse.
 */
enum DoctorPayoutBasis: string
{
    /** Regime anterior (até 2026-09-29): valor cobrado/tabela na data do atendimento. Histórico intocado. */
    case Production = 'production';

    /** Liberação pelo recebido: parcela = devido sobre o recebido acumulado − já liberado. */
    case Receipt = 'receipt';

    public function label(): string
    {
        return __("financial_doctor_payouts.bases.{$this->value}");
    }
}
