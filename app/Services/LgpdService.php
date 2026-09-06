<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\{LgpdRequestStatus, LgpdRequestType};
use App\Models\{EntityUser, LgpdRequest, MedicalRecord, MedicalRecordDocumentation, MedicalRecordFile,
    Patient, PatientConsent, PatientExam};
use Illuminate\Database\Eloquent\Collection;

class LgpdService
{
    /**
     * Abre uma solicitação de direitos do titular.
     * LGPD Art. 18 — prazo de resposta: 15 dias (Art. 23).
     */
    public function openRequest(
        string $entityId,
        LgpdRequestType $type,
        string $requesterName,
        string $requesterEmail,
        string $description,
        ?Patient $patient = null,
        ?string $requesterDocument = null,
    ): LgpdRequest {
        return LgpdRequest::create([
            'entity_id'          => $entityId,
            'patient_id'         => $patient?->id,
            'requester_name'     => $requesterName,
            'requester_email'    => $requesterEmail,
            'requester_document' => $requesterDocument,
            'request_type'       => $type,
            'status'             => LgpdRequestStatus::Pending,
            'description'        => $description,
            'requested_at'       => now(),
            'deadline_at'        => now()->addDays($type->deadlineDays()),
        ]);
    }

    /**
     * Inicia o processamento de uma solicitação.
     */
    public function startProcessing(LgpdRequest $request): void
    {
        $request->update(['status' => LgpdRequestStatus::InProgress]);
    }

    /**
     * Conclui uma solicitação com resposta ao titular.
     */
    public function complete(LgpdRequest $request, EntityUser $respondedBy, string $response): void
    {
        $request->update([
            'status'       => LgpdRequestStatus::Completed,
            'response'     => $response,
            'responded_at' => now(),
            'responded_by' => $respondedBy->id,
        ]);
    }

    /**
     * Rejeita uma solicitação com justificativa.
     * Ex.: não é o titular, dados não encontrados, base legal que impede eliminação.
     */
    public function reject(LgpdRequest $request, EntityUser $respondedBy, string $reason): void
    {
        $request->update([
            'status'           => LgpdRequestStatus::Rejected,
            'rejection_reason' => $reason,
            'responded_at'     => now(),
            'responded_by'     => $respondedBy->id,
        ]);
    }

    /**
     * Conclui uma solicitação SEM revisão humana — usado no self-service do
     * Portal do Paciente (Fase 4): o titular pedindo acesso aos PRÓPRIOS
     * dados não precisa do prazo de 15 dias do Art. 23 (esse prazo é o
     * MÁXIMO permitido pra quando há revisão humana, não um mínimo
     * obrigatório). `responded_by` fica null — nenhum EntityUser agiu.
     */
    public function completeAutomatically(LgpdRequest $request, string $response): void
    {
        $request->update([
            'status'       => LgpdRequestStatus::Completed,
            'response'     => $response,
            'responded_at' => now(),
        ]);
    }

    /**
     * Retorna solicitações abertas e vencidas (prazo expirado sem resposta).
     * Deve ser exibido como alerta no painel do gestor.
     *
     * @return Collection<LgpdRequest>
     */
    public function overdueRequests(string $entityId): Collection
    {
        return LgpdRequest::query()
            ->where('entity_id', $entityId)
            ->whereIn('status', [LgpdRequestStatus::Pending->value, LgpdRequestStatus::InProgress->value])
            ->where('deadline_at', '<', now())
            ->orderBy('deadline_at')
            ->get();
    }

    /**
     * Exporta todos os dados de um paciente pra atendimento de acesso/portabilidade.
     * LGPD Art. 18, II (acesso) e V (portabilidade).
     *
     * Escopo: um Patient = uma clínica (entity_id) = um controlador de dados
     * diferente sob a LGPD — nunca agrega dados de outras clínicas do mesmo
     * titular aqui (isso é papel do Portal "Minhas Clínicas", que já isola
     * por Patient). Conteúdo textual/relacional completo (inclui laudos e
     * anamnese); binários (imagem de exame, anexo) ficam só como metadado —
     * já acessíveis via download individual no Portal (Fase 2).
     *
     * Retorna um array estruturado com todos os dados do paciente no sistema.
     */
    public function exportPatientData(Patient $patient): array
    {
        $patient->load(['person', 'entity', 'covenant']);

        $medicalRecords = $patient->medicalRecords()
            ->with(['doctor.person', 'documentations', 'files'])
            ->orderByDesc('created_at')
            ->get();

        return [
            'exported_at' => now()->toIso8601String(),
            'clinic'      => [
                'name'          => $patient->entity?->name,
                'patient_code'  => $patient->code,
                'card_number'   => $patient->card_number,
                'covenant'      => $patient->covenant?->name,
                'active'        => $patient->active,
                'patient_since' => $patient->created_at?->toIso8601String(),
            ],
            'personal_data' => $patient->person ? [
                'full_name'         => $patient->person->full_name,
                'birth_date'        => $patient->person->birth_date?->toDateString(),
                'gender'            => $patient->person->gender_label,
                'email'             => $patient->person->email,
                'telephone'         => $patient->person->telephone,
                'cellphone'         => $patient->person->cellphone,
                'national_registry' => $patient->person->national_registry,
                'address'           => [
                    'zipcode'    => $patient->person->zipcode,
                    'address'    => $patient->person->address,
                    'number'     => $patient->person->number,
                    'complement' => $patient->person->complement,
                    'district'   => $patient->person->district,
                    'city'       => $patient->person->city,
                    'state'      => $patient->person->state,
                ],
            ] : null,
            'medical_records' => $medicalRecords->map(fn (MedicalRecord $r) => [
                'code'             => $r->code,
                'date'             => $r->created_at?->toIso8601String(),
                'doctor'           => $r->doctor?->person?->full_name,
                'main_complaint'   => $r->main_complaint,
                'hda'              => $r->hda,
                'diagnosis_cids'   => $r->diagnosis_cids ?? [],
                'clinical_conduct' => $r->clinical_conduct,
                'is_signed'        => $r->isSigned(),
                'signed_at'        => $r->signed_at?->toIso8601String(),
                'documentations'   => $r->documentations->map(fn (MedicalRecordDocumentation $d) => [
                    'type'       => $d->getTypeLabel(),
                    'title'      => $d->title,
                    'content'    => $d->contentForRender(),
                    'created_at' => $d->created_at?->toIso8601String(),
                ])->all(),
                'files' => $r->files->map(fn (MedicalRecordFile $f) => [
                    'original_name' => $f->original_name,
                    'mime_type'     => $f->mime_type,
                    'file_size'     => $f->file_size,
                    'created_at'    => $f->created_at?->toIso8601String(),
                ])->all(),
            ])->all(),
            // Exames de imagem: metadado apenas — a imagem em si já é
            // baixável individualmente pelo titular via Portal (Fase 2),
            // reencodar binário em JSON não agrega portabilidade real.
            'exams' => $patient->exams()
                ->with('examType')
                ->orderByDesc('exam_performed_at')
                ->get()
                ->map(fn (PatientExam $e) => [
                    'type'           => $e->examType?->name,
                    'laterality'     => $e->laterality,
                    'performed_at'   => $e->exam_performed_at?->toIso8601String(),
                    'diagnosis_cids' => $e->diagnosis_cids ?? [],
                ])->all(),
            'consents' => $patient->consents()
                ->get()
                ->map(fn (PatientConsent $c) => [
                    'type'       => $c->consent_type->label(),
                    'status'     => $c->status,
                    'granted_at' => $c->granted_at?->toIso8601String(),
                    'revoked_at' => $c->revoked_at?->toIso8601String(),
                ])
                ->toArray(),
            // LGPD Art. 9º, VI — transparência sobre quem tratou os dados.
            'access_log_summary' => [
                'total_accesses'   => $patient->accessLogs()->count(),
                'last_accessed_at' => optional($patient->accessLogs()->latest('accessed_at')->first())
                    ->accessed_at?->toIso8601String(),
            ],
        ];
    }
}
