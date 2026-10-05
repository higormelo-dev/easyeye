<?php

declare(strict_types=1);

namespace App\Http\Controllers\PatientPortal;

use App\Enums\ShareableDocumentType;
use App\Http\Controllers\Controller;
use App\Models\{Patient, PatientAccount, PatientDocumentShare};
use App\Services\Billing\ClinicServiceGate;
use Illuminate\Support\Facades\Auth;
use Inertia\{Inertia, Response};

/**
 * Lista os documentos (laudo/exame/anexo) que o staff de UMA clínica
 * específica liberou pro titular — Fase 2 do Portal do Paciente.
 */
class ClinicController extends Controller
{
    public function show(Patient $patient, ClinicServiceGate $gate): Response
    {
        /** @var PatientAccount $account */
        $account = Auth::guard('patient')->user();

        // Nunca 403: não revelar a um paciente que {patient} existe mas não é dele.
        abort_unless($account->ownsPerson($patient->person_id), 404);

        $shares = PatientDocumentShare::query()
            ->where('patient_id', $patient->id)
            ->whereNull('revoked_at')
            ->with('shareable')
            ->orderByDesc('granted_at')
            ->get()
            ->filter(fn (PatientDocumentShare $s) => $s->shareable !== null);

        return Inertia::render('PatientPortal/Clinic', [
            'appName'    => config('app.name', 'EasyEye'),
            'clinicName' => $patient->entity?->name,
            'documents'  => $shares->map(fn (PatientDocumentShare $s) => $this->serializeShare($s))->values(),
            // Fase 4 — autoatendimento LGPD (Art. 18, II/V).
            'lgpdExportUrl' => route('patient-portal.clinics.export', $patient),
            // Serviço da clínica suspenso: só consulta — documentos e o
            // "Baixar meus dados" seguem; aviso neutro.
            'readOnly'       => $gate->portalReadOnly($patient->entity),
            'readOnlyNotice' => __('patient_portal.read_only.notice'),
        ]);
    }

    private function serializeShare(PatientDocumentShare $share): array
    {
        $type = ShareableDocumentType::fromModelClass($share->shareable_type);
        $doc  = $share->shareable;

        return [
            'id'           => $share->id,
            'type'         => $type->value,
            'type_label'   => $type->label(),
            'title'        => $type->resolveTitle($doc),
            'shared_at'    => $share->granted_at?->format('d/m/Y H:i'),
            'view_url'     => route('patient-portal.documents.view', [$type->value, $doc->id]),
            'download_url' => route('patient-portal.documents.download', [$type->value, $doc->id]),
        ];
    }
}
