<?php

declare(strict_types=1);

namespace App\Enums\DoctorPayout;

/**
 * Tipo de serviço de um item de produção (e escopo obrigatório de uma regra).
 *
 * Agendamento atendido é classificado pelo procedimento do tipo de
 * atendimento (visit_types.procedure_id → procedures.treatment):
 * 3 = exame, 4 = procedimento, qualquer outro (ou sem procedimento) = consulta.
 * Procedimento do prontuário só entra com tratamento 4 (cirúrgico/intervencionista);
 * exame vem dos equipamentos (patient_exams).
 */
enum DoctorPayoutServiceType: string
{
    case Consultation = 'consultation';
    case Exam         = 'exam';
    case Procedure    = 'procedure';

    public function label(): string
    {
        return __("financial_doctor_payouts.service_types.{$this->value}");
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
