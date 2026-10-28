<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\{PatientExam, Schedule};
use Illuminate\Database\Eloquent\Builder;

/**
 * Quando um exame AINDA pertence ao agendamento a que está vinculado
 * (patient_exams.schedule_id). O vínculo nasce pela identidade — paciente,
 * dia e médico (ExternalExamImportService::resolveAttendedScheduleId / API de
 * integradores) — e é derivado dela. Se o agendamento muda de paciente, de dia
 * ou de médico depois, o vínculo deixa de valer e quem confia nele sem
 * conferir erra: o repasse suprimia o exame na competência (e no médico) onde
 * ele aconteceu, o laudo ancorava no atendimento de outro paciente (achado via
 * revisão adversarial Codex).
 *
 * Esta é a regra ÚNICA, usada por quem produz o vínculo (desvincular ao editar
 * o agendamento) e por quem o consome (repasse, rateio do recebido, âncora do
 * laudo).
 */
final class ExamScheduleLink
{
    /**
     * Predicado SQL (PostgreSQL) para JOIN/WHERE: paciente, dia e médico do
     * exame batem com os do agendamento. Exame sem médico vale — a captura sem
     * médico vincula ao atendimento do paciente no dia (resolveAttendedScheduleId).
     */
    public static function compatibleSql(string $exam, string $schedule): string
    {
        return "{$schedule}.patient_id = {$exam}.patient_id"
            . " AND CAST({$schedule}.date_time AS date) = CAST(COALESCE({$exam}.exam_performed_at, {$exam}.created_at) AS date)"
            . " AND ({$exam}.doctor_id IS NULL OR {$exam}.doctor_id = {$schedule}.doctor_id)";
    }

    /**
     * Exames vinculados a este agendamento cujo vínculo deixou de valer, pela
     * identidade ATUAL do agendamento (a instância tem que estar atualizada).
     *
     * @return Builder<PatientExam>
     */
    public static function staleFor(Schedule $schedule): Builder
    {
        $query = PatientExam::query()->where('patient_exams.schedule_id', $schedule->id);

        // Sem paciente ou sem data não há identidade a que um exame pertença.
        if ($schedule->patient_id === null || $schedule->date_time === null) {
            return $query;
        }

        return $query->whereRaw(
            'NOT (patient_exams.patient_id = ?'
            . ' AND CAST(COALESCE(patient_exams.exam_performed_at, patient_exams.created_at) AS date) = ?'
            . ' AND (patient_exams.doctor_id IS NULL OR patient_exams.doctor_id = ?))',
            [$schedule->patient_id, $schedule->date_time->toDateString(), $schedule->doctor_id],
        );
    }
}
