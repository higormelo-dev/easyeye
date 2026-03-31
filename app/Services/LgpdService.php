<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\{LgpdRequestStatus, LgpdRequestType};
use App\Models\{EntityUser, LgpdRequest, Patient};

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
     * Retorna solicitações abertas e vencidas (prazo expirado sem resposta).
     * Deve ser exibido como alerta no painel do gestor.
     *
     * @return \Illuminate\Database\Eloquent\Collection<LgpdRequest>
     */
    public function overdueRequests(string $entityId): \Illuminate\Database\Eloquent\Collection
    {
        return LgpdRequest::query()
            ->where('entity_id', $entityId)
            ->whereIn('status', [LgpdRequestStatus::Pending->value, LgpdRequestStatus::InProgress->value])
            ->where('deadline_at', '<', now())
            ->orderBy('deadline_at')
            ->get();
    }

    /**
     * Exporta todos os dados de um paciente para atendimento de portabilidade/acesso.
     * LGPD Art. 18, II (acesso) e V (portabilidade).
     *
     * Retorna um array estruturado com todos os dados do paciente no sistema.
     */
    public function exportPatientData(Patient $patient): array
    {
        $patient->load([
            'person',
            'entity',
            'covenant',
        ]);

        return [
            'exported_at' => now()->toIso8601String(),
            'patient'     => [
                'code'        => $patient->code,
                'card_number' => $patient->card_number,
                'active'      => $patient->active,
                'created_at'  => $patient->created_at?->toIso8601String(),
            ],
            'personal_data' => $patient->person ? [
                'full_name'     => $patient->person->full_name,
                'birth_date'    => $patient->person->birth_date?->toDateString(),
                'gender'        => $patient->person->gender,
                'email'         => $patient->person->email,
                'telephone'     => $patient->person->telephone,
                'cellphone'     => $patient->person->cellphone,
                'national_registry' => $patient->person->national_registry,
                'address'       => [
                    'zipcode'    => $patient->person->zipcode,
                    'address'    => $patient->person->address,
                    'number'     => $patient->person->number,
                    'complement' => $patient->person->complement,
                    'district'   => $patient->person->district,
                    'city'       => $patient->person->city,
                    'state'      => $patient->person->state,
                ],
            ] : null,
            'medical_records' => $patient->medicalRecords()
                ->with(['doctor.person', 'schedule'])
                ->get()
                ->map(fn ($r) => [
                    'code'        => $r->code,
                    'date'        => $r->created_at?->toIso8601String(),
                    'doctor'      => $r->doctor?->person?->full_name,
                    'is_signed'   => $r->isSigned(),
                    'signed_at'   => $r->signed_at?->toIso8601String(),
                ])
                ->toArray(),
            'consents' => $patient->consents()
                ->get()
                ->map(fn ($c) => [
                    'type'       => $c->consent_type->label(),
                    'status'     => $c->status,
                    'granted_at' => $c->granted_at?->toIso8601String(),
                    'revoked_at' => $c->revoked_at?->toIso8601String(),
                ])
                ->toArray(),
        ];
    }
}
