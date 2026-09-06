<?php

declare(strict_types=1);

namespace App\Http\Controllers\PatientPortal;

use App\Enums\{DataAccessPurpose, LgpdRequestType};
use App\Http\Controllers\Controller;
use App\Models\{Patient, PatientAccount};
use App\Services\LgpdService;
use App\Traits\LogsDataAccess;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * Autoatendimento LGPD (Fase 4 do Portal do Paciente) — Art. 18, II (acesso)
 * e V (portabilidade). O titular baixa os PRÓPRIOS dados de UMA clínica
 * (um Patient = um controlador de dados) sem depender de atendimento manual
 * do staff.
 *
 * Reaproveita a infraestrutura LgpdRequest/LgpdService já existente (até
 * então dormente, sem nenhum caller) só pra registro/trilha — o pedido é
 * concluído automaticamente (ver LgpdService::completeAutomatically()):
 * acesso aos PRÓPRIOS dados não precisa do prazo de 15 dias do Art. 23
 * (prazo é teto pra revisão humana, não piso obrigatório).
 */
class LgpdExportController extends Controller
{
    use LogsDataAccess;

    public function __construct(
        private readonly LgpdService $lgpdService,
    ) {
    }

    public function export(Patient $patient): Response
    {
        /** @var PatientAccount $account */
        $account = Auth::guard('patient')->user();

        // Nunca 403: não revelar a um paciente que {patient} existe mas não é dele.
        abort_unless((string) $patient->person_id === (string) $account->person_id, 404);

        $person = $account->person;

        $lgpdRequest = $this->lgpdService->openRequest(
            entityId: (string) $patient->entity_id,
            type: LgpdRequestType::Access,
            requesterName: $person?->full_name ?? $account->email,
            requesterEmail: $account->email,
            description: 'Exportação de dados pessoais solicitada pelo titular via Portal do Paciente (self-service).',
            patient: $patient,
            requesterDocument: $person?->national_registry,
        );

        $data = $this->lgpdService->exportPatientData($patient);

        $this->lgpdService->completeAutomatically(
            $lgpdRequest,
            'Exportado automaticamente — acesso self-service aos próprios dados via Portal do Paciente.',
        );

        // CFM Res. 2.227/2018 + LGPD Art. 37 — leitura em massa dos próprios
        // dados clínicos também é um acesso a registrar (patient_account_id,
        // não user_id — ver AuditContext).
        $this->logAccess($patient, DataAccessPurpose::LgpdRequest, patientId: $patient->id);

        $filename = 'meus-dados-' . ($patient->entity?->name ?? 'clinica') . '-' . now()->format('Y-m-d') . '.json';
        $filename = preg_replace('/[^A-Za-z0-9\-_.]/', '_', $filename);

        return response(
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            200,
            [
                'Content-Type'        => 'application/json',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ],
        );
    }
}
