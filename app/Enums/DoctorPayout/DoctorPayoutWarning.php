<?php

declare(strict_types=1);

namespace App\Enums\DoctorPayout;

/**
 * Alertas de conferência de um item de produção. Só NoRule bloqueia o
 * fechamento; os demais orientam a revisão antes de fechar.
 */
enum DoctorPayoutWarning: string
{
    /** Nenhuma regra cobre o item — cadastre uma (pode ser R$ 0). Bloqueia o fechamento. */
    case NoRule = 'no_rule';

    /** Regra percentual sobre item sem valor base: repasse sai 0. */
    case NoBaseValue = 'no_base_value';

    /** Houve cobrança (caixa/guia), mas toda cancelada ou negada: base 0. */
    case ChargeCancelled = 'charge_cancelled';

    /** O prontuário do atendimento foi registrado por outro médico. */
    case DoctorMismatch = 'doctor_mismatch';

    /** O lançamento de caixa do atendimento pode incluir procedimento do mesmo dia. */
    case SharedCharge = 'shared_charge';

    /** Item com data dentro de um período já fechado para este médico (lançado depois). */
    case LateItem = 'late_item';

    public function label(): string
    {
        return __("financial_doctor_payouts.warnings.{$this->value}");
    }

    public function blocksClosing(): bool
    {
        return $this === self::NoRule;
    }
}
