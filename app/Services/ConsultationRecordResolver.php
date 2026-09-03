<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\{Doctor, MedicalRecord, PatientExam, Schedule};
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
        $scheduleId = PatientExam::query()
            ->whereIn('id', $examIds)
            ->whereNotNull('schedule_id')
            ->orderByDesc('created_at')
            ->value('schedule_id');

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
     * Resolve o Doctor.id associado ao usuário autenticado dentro da entity
     * ativa. Retorna null se o usuário for admin/secretary sem perfil
     * médico vinculado — o caller decide o fallback (doctor do prontuário,
     * doctor do exame, ou 422 exigindo médico).
     */
    public function resolveDoctorIdForCurrentUser(string $entityId): ?string
    {
        $userId = (string) auth()->id();

        return Doctor::query()
            ->whereHas('entityUser', function ($query) use ($entityId, $userId): void {
                $query->where('entity_id', $entityId)->where('user_id', $userId);
            })
            ->value('id');
    }
}
