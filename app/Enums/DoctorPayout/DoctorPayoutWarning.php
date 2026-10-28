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

    /** O valor cobrado no agendamento foi dividido entre procedimentos iguais executados (ex.: OD e OE). */
    case SplitCharge = 'split_charge';

    /**
     * O devido ficou menor que o já liberado (recebimento estornado, glosa
     * registrada depois, divisão com outro procedimento): parcela negativa.
     */
    case NegativeAdjustment = 'negative_adjustment';

    /**
     * O ato deixou de valer para este médico (procedimento cancelado,
     * prontuário excluído, atendimento desmarcado, médico corrigido): o já
     * liberado é estornado.
     */
    case ActRemoved = 'act_removed';

    /** Parte do recebido tem data anterior ao período e ainda não tinha sido liberada. */
    case LateReceipt = 'late_receipt';

    /**
     * Agendamento de exame com mais de um tipo de exame capturado: conta como
     * UM atendimento e a regra por tipo de exame não se aplica (vale a do
     * tipo de atendimento ou a geral de exame).
     */
    case MultipleExamTypes = 'multiple_exam_types';

    /**
     * O procedimento do agendamento aparece no prontuário só como cancelado:
     * o agendamento atendido continua contando — confira antes de fechar.
     */
    case ProcedureCancelled = 'procedure_cancelled';

    public function label(): string
    {
        return __("financial_doctor_payouts.warnings.{$this->value}");
    }

    public function blocksClosing(): bool
    {
        return $this === self::NoRule;
    }
}
