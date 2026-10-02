<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\{LgpdRequestStatus, LgpdRequestType};
use App\Models\{EntityUser, LgpdRequest, 
    Patient};
use App\Services\Lgpd\PatientDataExporter;
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
        return app(PatientDataExporter::class)->export($patient);
    }
}
