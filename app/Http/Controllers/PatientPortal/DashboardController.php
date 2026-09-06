<?php

declare(strict_types=1);

namespace App\Http\Controllers\PatientPortal;

use App\Http\Controllers\Controller;
use App\Models\{Patient, PatientAccount};
use Illuminate\Support\Facades\Auth;
use Inertia\{Inertia, Response};

class DashboardController extends Controller
{
    /**
     * "Minhas Clínicas" — lista as clínicas onde o paciente logado já foi
     * atendido, e SOMENTE isso (nome/cidade da Entity). Nunca expor
     * prontuário/exame/documento aqui: grant de documento é Fase 2, fora de
     * escopo desta entrega.
     *
     * Sem tenant.bind/entity.selected na rota (ver routes/patient-portal.php)
     * — TenantContext fica sem vínculo, o EntityScope global de Patient fica
     * inerte, e a query abaixo retorna as linhas de TODAS as clínicas do
     * titular (ver People::patients() e PatientService::findOrCreatePerson()).
     */
    public function index(): Response
    {
        /** @var PatientAccount $account */
        $account = Auth::guard('patient')->user();

        $clinics = Patient::query()
            ->where('person_id', $account->person_id)
            ->with('entity')
            ->get()
            ->map(fn (Patient $patient) => [
                'entity_id' => $patient->entity_id,
                'name'      => $patient->entity?->name,
                'city'      => $patient->entity?->city,
                // Fase 2 — lista de documentos liberados nesta clínica.
                'clinic_url' => route('patient-portal.clinics.show', $patient),
            ])
            ->values();

        return Inertia::render('PatientPortal/Dashboard', [
            'appName'     => config('app.name', 'EasyEye'),
            'patientName' => $account->person?->full_name,
            'clinics'     => $clinics,
        ]);
    }
}
