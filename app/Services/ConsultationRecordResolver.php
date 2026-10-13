<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\{MedicalRecord, Patient, PatientExam, Schedule, User};
use App\Services\ClinicalSafety\PatientClinicalSafetyService;
use App\Support\{CurrentDoctor, ExamScheduleLink};
use Illuminate\Support\Carbon;

/**
 * Resolve o "prontuário do dia da consulta" a partir de exames — a mesma
 * ancoragem usada tanto pelo laudo de IA (AiRunDocumentationService) quanto
 * pelo laudo manual do Gerenciador de Imagens (EyeImageReportController).
 * Domínio neutro de propósito: nem um nem outro fluxo é "dono" dessa regra.
 *
 * Regra: um documento clínico gerado a partir de exames pertence ao
 * prontuário do MESMO agendamento; na falta de agendamento vinculado, ao
 * prontuário mais recente do paciente no mesmo DIA. Sem prontuário nenhum
 * nesse dia, o caller decide se abre um novo (CFM/LGPD exige confirmação
 * explícita do médico, nunca criação silenciosa).
 */
class ConsultationRecordResolver
{
    /**
     * Âncora da consulta = agendamento dos exames informados: devolve
     * [schedule_id, data]. Sem agendamento vinculado a nenhum exame, cai
     * para hoje (documento avulso, sem consulta associada).
     *
     * @param array<int, mixed> $examIds
     *
     * @return array{0: ?string, 1: string}
     */
    public function anchorForExamIds(array $examIds): array
    {
        // Só ancora num agendamento a que o exame AINDA pertence (mesmo
        // paciente e dia): agendamento trocado de paciente ou remarcado depois
        // da captura não leva o laudo pro atendimento (e pro prontuário) de
        // outro paciente — nesse caso o exame vale como sem agendamento (achado
        // via revisão adversarial Codex; regra em App\Support\ExamScheduleLink).
        $scheduleId = PatientExam::query()
            ->join('schedules as s', 's.id', '=', 'patient_exams.schedule_id')
            ->whereIn('patient_exams.id', $examIds)
            ->whereNull('s.deleted_at')
            ->whereRaw(ExamScheduleLink::compatibleSql('patient_exams', 's'))
            ->orderByDesc('patient_exams.created_at')
            ->value('patient_exams.schedule_id');

        if ($scheduleId) {
            $dateTime = Schedule::query()->whereKey($scheduleId)->value('date_time');

            return [(string) $scheduleId, $dateTime ? Carbon::parse($dateTime)->toDateString() : now()->toDateString()];
        }

        return [null, now()->toDateString()];
    }

    /**
     * Prontuário da consulta: prioriza o do MESMO agendamento; na falta,
     * qualquer prontuário do paciente no mesmo DIA do agendamento.
     */
    public function findRecord(string $entityId, string $patientId, ?string $scheduleId, string $consultationDate): ?MedicalRecord
    {
        $base = MedicalRecord::query()
            ->where('entity_id', $entityId)
            ->where('patient_id', $patientId);

        if ($scheduleId) {
            $record = (clone $base)->where('schedule_id', $scheduleId)->latest('created_at')->first();

            if ($record) {
                return $record;
            }
        }

        return (clone $base)->whereDate('created_at', $consultationDate)->latest('created_at')->first();
    }

    /**
     * Abre o prontuário da consulta (o caller já tem a confirmação do médico e
     * o lock de abertura do paciente) com o RETRATO das alergias e medicações
     * ativas do paciente no momento — mesma regra de MedicalRecordService::store
     * (Fase 2 / A2). Ponto único dos prontuários abertos fora do salvar do
     * formulário: laudo manual do Gerenciador de Imagens e laudo de IA aprovado.
     */
    public function openRecord(string $entityId, string $patientId, string $doctorId, ?string $scheduleId): MedicalRecord
    {
        $patient = Patient::query()
            ->withoutGlobalScopes()
            ->where('entity_id', $entityId)
            ->findOrFail($patientId);

        return MedicalRecord::query()->create([
            ...array_filter([
                'entity_id'   => $entityId,
                'patient_id'  => $patientId,
                'doctor_id'   => $doctorId,
                'schedule_id' => $scheduleId,
            ]),
            ...app(PatientClinicalSafetyService::class)->snapshot($patient),
        ]);
    }

    /**
     * Doctor.id ATIVO do usuário autenticado na entity — o médico que emite
     * (App\Support\CurrentDoctor::activeFor, a mesma regra do Gate
     * IssueReport). null para quem não tem cadastro de médico ativo; os
     * fluxos de emissão não caem mais no médico do exame/prontuário.
     */
    public function resolveDoctorIdForCurrentUser(string $entityId): ?string
    {
        $user = auth()->user();
        $id   = CurrentDoctor::activeFor($user instanceof User ? $user : null, $entityId)?->id;

        return $id !== null ? (string) $id : null;
    }
}
