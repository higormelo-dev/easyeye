<?php

declare(strict_types=1);

namespace App\Enums\DoctorPayout;

/**
 * Origem de um item de produção (doctor_payout_items.source_type). Junto com
 * source_id identifica o ato de forma única: o índice único parcial
 * (entity_id, source_type, source_id) WHERE voided_at IS NULL impede que o
 * mesmo ato entre em dois fechamentos válidos.
 */
enum DoctorPayoutSourceType: string
{
    /** Agendamento atendido — source_id = schedules.id. */
    case Schedule = 'schedule';

    /** Procedimento executado no prontuário — source_id = medical_record_procedures.id. */
    case MedicalRecordProcedure = 'medical_record_procedure';

    /**
     * Exame de equipamento — source_id = "paciente|tipo de exame|data local"
     * (patient_exams tem uma linha por imagem; o exame é o agrupamento).
     */
    case PatientExam = 'patient_exam';
}
