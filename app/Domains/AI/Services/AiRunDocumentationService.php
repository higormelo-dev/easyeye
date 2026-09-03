<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\AI\Models\AiRun;
use App\Enums\DocumentationType;
use App\Models\{MedicalRecord, MedicalRecordDocumentation, PatientExam};
use App\Services\ConsultationRecordResolver;
use Mews\Purifier\Facades\Purifier;

/**
 * Persistência do laudo de IA no prontuário (extraído de AiRunsController).
 *
 * Regra do prontuário do DIA DA CONSULTA + idempotência por ai_run_id. Sem
 * mudança de comportamento: lógica movida verbatim do controller.
 *
 * A ancoragem "prontuário do dia" em si vive em ConsultationRecordResolver
 * (serviço neutro, compartilhado com o laudo manual do Gerenciador de
 * Imagens) — os métodos públicos abaixo mantêm o nome/assinatura originais
 * (usados por AiRunsController) e delegam pra lá.
 */
class AiRunDocumentationService
{
    public function __construct(
        private readonly ConsultationRecordResolver $recordResolver = new ConsultationRecordResolver(),
    ) {
    }

    /**
     * Persiste o laudo no prontuário do DIA DA CONSULTA (agendamento). Regra:
     *  - Run já vinculado a um prontuário específico -> grava nele.
     *  - Senão, procura o prontuário do paciente da consulta: mesmo agendamento
     *    (schedule_id) e, na falta, qualquer prontuário do mesmo dia do agendamento.
     *  - Sem prontuário desse dia, NÃO cria automaticamente: sinaliza
     *    `requires_record_confirmation` para o médico decidir abrir (CFM/LGPD).
     *
     * Idempotência via `updateOrCreate` por `ai_run_id`.
     *
     * @return array{attached: bool, requires_record_confirmation: bool, consultation_date: ?string}
     */
    public function persistFromApprovedRun(AiRun $aiRun, string $finalOutput): array
    {
        // BUGFIX/GUARDA: o Assistente Virtual (workflow=assistant_chat) é uma
        // conversa livre de apoio, NUNCA um documento clínico estruturado —
        // aprovar uma resposta de chat (approve() é auto-chamado pelo widget
        // ao terminar) não pode silenciosamente gravar o texto como documentação
        // no prontuário do DIA da consulta só porque o médico deu contexto de
        // um paciente. Só os workflows de laudo/prontuário fazem essa escrita.
        if ($aiRun->workflow === 'assistant_chat') {
            return ['attached' => false, 'requires_record_confirmation' => false, 'consultation_date' => null];
        }

        $consultationDate = null;

        if ($aiRun->medical_record_id) {
            $recordQuery = MedicalRecord::query()
                ->where('id', (string) $aiRun->medical_record_id)
                ->where('entity_id', (string) $aiRun->entity_id);

            if (! empty($aiRun->patient_id)) {
                $recordQuery->where('patient_id', (string) $aiRun->patient_id);
            }

            $record = $recordQuery->first();
        } else {
            $patientId = $this->resolveRunPatientId($aiRun);

            if (! $patientId) {
                return ['attached' => false, 'requires_record_confirmation' => false, 'consultation_date' => null];
            }

            [$scheduleId, $consultationDate] = $this->consultationAnchorForRun($aiRun);
            $record                          = $this->findConsultationRecord((string) $aiRun->entity_id, $patientId, $scheduleId, $consultationDate);

            // Sem prontuário do dia da consulta -> pergunta ao médico se pode abrir.
            if (! $record) {
                return ['attached' => false, 'requires_record_confirmation' => true, 'consultation_date' => $consultationDate];
            }
        }

        if (! $record) {
            return ['attached' => false, 'requires_record_confirmation' => false, 'consultation_date' => $consultationDate];
        }

        $this->writeRunDocumentation($aiRun, $record, $finalOutput);

        return ['attached' => true, 'requires_record_confirmation' => false, 'consultation_date' => $consultationDate];
    }

    /**
     * Prontuário da consulta: prioriza o do MESMO agendamento; na falta, qualquer
     * prontuário do paciente no mesmo DIA do agendamento.
     */
    public function findConsultationRecord(string $entityId, string $patientId, ?string $scheduleId, string $consultationDate): ?MedicalRecord
    {
        return $this->recordResolver->findRecord($entityId, $patientId, $scheduleId, $consultationDate);
    }

    /**
     * Âncora da consulta = agendamento dos exames analisados: devolve
     * [schedule_id, data]. Sem agendamento vinculado, cai para hoje.
     *
     * @return array{0: ?string, 1: string}
     */
    public function consultationAnchorForRun(AiRun $aiRun): array
    {
        return $this->recordResolver->anchorForExamIds((array) ($aiRun->input_summary['exam_ids'] ?? []));
    }

    /**
     * Grava (idempotente) a documentação do laudo no prontuário informado.
     */
    public function writeRunDocumentation(AiRun $aiRun, MedicalRecord $record, string $finalOutput): void
    {
        $doctorId = $this->resolveDoctorIdForCurrentUser((string) $aiRun->entity_id)
            ?? (string) $record->doctor_id;

        $title = __('ai.documentation.auto_title', [
            'workflow' => __('ai.workflow_' . $aiRun->workflow),
        ]);

        // O conteúdo aprovado pode carregar HTML/trechos injetados. Sanitiza com o
        // mesmo profile "medical" usado no fluxo manual antes de persistir.
        $sanitizedOutput = Purifier::clean($finalOutput, 'medical');

        MedicalRecordDocumentation::query()->updateOrCreate(
            ['ai_run_id' => $aiRun->id],
            [
                'medical_record_id' => $record->id,
                'patient_id'        => $record->patient_id,
                'doctor_id'         => $doctorId,
                'type'              => DocumentationType::Report->value,
                'title'             => $title,
                'content'           => $sanitizedOutput,
            ],
        );
    }

    /**
     * Paciente do run: o próprio patient_id ou, em runs de imagem sem paciente
     * explícito, o paciente dos exames analisados.
     */
    public function resolveRunPatientId(AiRun $aiRun): ?string
    {
        if (! empty($aiRun->patient_id)) {
            return (string) $aiRun->patient_id;
        }

        $patientId = PatientExam::query()
            ->whereIn('id', (array) ($aiRun->input_summary['exam_ids'] ?? []))
            ->value('patient_id');

        return $patientId !== null ? (string) $patientId : null;
    }

    /**
     * Resolve o Doctor.id associado ao usuário autenticado dentro da entity ativa.
     * Retorna null se o aprovador for admin/secretary sem perfil médico vinculado
     * — nesse caso o caller usa fallback (doctor do prontuário).
     */
    public function resolveDoctorIdForCurrentUser(string $entityId): ?string
    {
        return $this->recordResolver->resolveDoctorIdForCurrentUser($entityId);
    }
}
