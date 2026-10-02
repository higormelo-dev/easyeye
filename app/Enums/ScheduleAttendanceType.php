<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Tipo de atendimento do agendamento (consulta, retorno, urgência...).
 *
 * Classificação da CONSULTA em si — muda de um agendamento para o outro do
 * mesmo paciente (uma "Avaliação pré-operatória" hoje pode ser um "Retorno"
 * na próxima visita). Por isso vive em `schedules`, não em `patients`.
 *
 * Lista fixa (não é catálogo configurável por entity como VisitType/Covenant)
 * — os valores pedidos formam um conjunto clínico fechado e comum a todas as
 * clínicas oftalmológicas do produto.
 */
enum ScheduleAttendanceType: int
{
    case Consultation     = 1; // Consulta
    case Return           = 2; // Retorno
    case Urgency          = 3; // Urgência
    case PreOpEvaluation  = 4; // Avaliação pré-operatória
    case PostOpEvaluation = 5; // Avaliação pós-operatória
    case SecondOpinion    = 6; // Segunda opinião
    case Teleconsultation = 7; // Teleconsulta

    public function label(): string
    {
        return match ($this) {
            self::Consultation     => __('actions.attendance_type_consultation'),
            self::Return           => __('actions.attendance_type_return'),
            self::Urgency          => __('actions.attendance_type_urgency'),
            self::PreOpEvaluation  => __('actions.attendance_type_preop'),
            self::PostOpEvaluation => __('actions.attendance_type_postop'),
            self::SecondOpinion    => __('actions.attendance_type_second_opinion'),
            self::Teleconsultation => __('actions.attendance_type_teleconsultation'),
        };
    }

    /**
     * Nome do "Tipo de consulta" global equivalente (catálogo visit_types,
     * ver migration clean_and_dedupe_visit_types). O formulário da agenda só
     * usa o catálogo; este enum sobrevive para dados legados e para a coluna
     * `tipo_atendimento` do import, que vira fallback do tipo de consulta.
     */
    public function visitTypeName(): string
    {
        return match ($this) {
            self::Consultation     => 'CONSULTA',
            self::Return           => 'RETORNO',
            self::Urgency          => 'URGÊNCIA',
            self::PreOpEvaluation  => 'AVALIAÇÃO PRÉ-OPERATÓRIA',
            self::PostOpEvaluation => 'AVALIAÇÃO PÓS-OPERATÓRIA',
            self::SecondOpinion    => 'SEGUNDA OPINIÃO',
            self::Teleconsultation => 'TELECONSULTA',
        };
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['id' => $case->value, 'name' => $case->label()],
            self::cases(),
        );
    }
}
