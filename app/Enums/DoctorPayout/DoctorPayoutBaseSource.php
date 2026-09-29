<?php

declare(strict_types=1);

namespace App\Enums\DoctorPayout;

/**
 * De onde veio o valor base de um item (coluna "Valor cobrado").
 */
enum DoctorPayoutBaseSource: string
{
    /** Lançamento(s) de caixa e/ou guia do atendimento. */
    case Charged = 'charged';

    /** Tabela de preços (convênio × procedimento): nada foi lançado nem faturado. */
    case Table = 'table';

    /** Sem valor: exame de equipamento, cobrança cancelada/negada ou sem preço. */
    case None = 'none';

    public function label(): string
    {
        return __("financial_doctor_payouts.base_sources.{$this->value}");
    }
}
