<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ClinicalSafety\MedicationStatus;
use App\Enums\{DataAccessPurpose, EntityGate};
use App\Http\Requests\ClinicalSafety\{PatientAllergyRequest, PatientMedicationRequest};
use App\Models\{Entity, Patient, PatientAllergy, PatientMedication};
use App\Services\ClinicalSafety\PatientClinicalSafetyService;
use App\Support\{CurrentDoctor, PatientAccess};
use App\Traits\LogsDataAccess;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Alergias e medicações em uso do paciente (Fase 2 / A1 e A2).
 *
 * Rotas no grupo de Pacientes (mesma regra de PatientAccess). Tenant e posse
 * conferidos aqui: o binding de {patient}/{allergy}/{medication} roda antes
 * do escopo de clínica. Confirmar, descartar, suspender e reativar exigem o
 * médico com cadastro ativo (Gate IssueReport); os demais perfis só
 * registram/corrigem o que é "informado pelo paciente" (regra no service).
 * Toda resposta devolve a situação inteira atualizada (a tela troca o bloco
 * e os alertas de uma vez).
 */
class PatientClinicalSafetyController extends Controller
{
    use LogsDataAccess;

    public function __construct(private readonly PatientClinicalSafetyService $service)
    {
    }

    public function show(Patient $patient): JsonResponse
    {
        $this->assertPatient($patient);

        // Leitura de dado de saúde sensível (LGPD art. 11/37).
        $this->logAccess($patient, DataAccessPurpose::PatientCare, patientId: (string) $patient->getKey());

        return $this->summary($patient);
    }

    public function storeAllergy(PatientAllergyRequest $request, Patient $patient): JsonResponse
    {
        $this->assertPatient($patient);
        $this->service->createAllergy($patient, $request->validated(), $this->asDoctor());

        return $this->summary($patient, 201);
    }

    public function updateAllergy(PatientAllergyRequest $request, Patient $patient, PatientAllergy $allergy): JsonResponse
    {
        $this->assertAllergy($patient, $allergy);
        $this->service->updateAllergy($allergy, $request->validated(), $this->asDoctor());

        return $this->summary($patient);
    }

    public function confirmAllergy(Patient $patient, PatientAllergy $allergy): JsonResponse
    {
        $this->assertAllergy($patient, $allergy);
        $this->authorizeDoctor();
        $this->service->confirmAllergy($allergy);

        return $this->summary($patient);
    }

    public function discardAllergy(Request $request, Patient $patient, PatientAllergy $allergy): JsonResponse
    {
        $this->assertAllergy($patient, $allergy);
        $this->authorizeDoctor();

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $this->service->discardAllergy($allergy, $data['reason'] ?? null);

        return $this->summary($patient);
    }

    /** "Nega alergias" (denied=true) ou desfaz. */
    public function updateAllergyStatus(Request $request, Patient $patient): JsonResponse
    {
        $this->assertPatient($patient);

        $data = $request->validate(['denied' => ['required', 'boolean']]);
        $this->service->setDenied($patient, (bool) $data['denied']);

        return $this->summary($patient->refresh());
    }

    public function storeMedication(PatientMedicationRequest $request, Patient $patient): JsonResponse
    {
        $this->assertPatient($patient);
        $this->service->createMedication($patient, $request->validated(), $this->asDoctor());

        return $this->summary($patient, 201);
    }

    public function updateMedication(PatientMedicationRequest $request, Patient $patient, PatientMedication $medication): JsonResponse
    {
        $this->assertMedication($patient, $medication);
        $this->service->updateMedication($medication, $request->validated(), $this->asDoctor());

        return $this->summary($patient);
    }

    public function confirmMedication(Patient $patient, PatientMedication $medication): JsonResponse
    {
        $this->assertMedication($patient, $medication);
        $this->authorizeDoctor();
        $this->service->confirmMedication($medication);

        return $this->summary($patient);
    }

    /** Suspender (parou de usar), reativar ou descartar (registro indevido). */
    public function updateMedicationStatus(Request $request, Patient $patient, PatientMedication $medication): JsonResponse
    {
        $this->assertMedication($patient, $medication);
        $this->authorizeDoctor();

        $data = $request->validate(['status' => ['required', 'string', Rule::in(MedicationStatus::values())]]);
        $this->service->changeMedicationStatus($medication, MedicationStatus::from($data['status']));

        return $this->summary($patient);
    }

    private function summary(Patient $patient, int $status = 200): JsonResponse
    {
        $asDoctor = $this->asDoctor();

        return response()->json([
            'data' => [
                ...$this->service->summary($patient),
                'patient_id' => (string) $patient->getKey(),
                'can_review' => $asDoctor,
                // Termos da checagem do receituário (só para quem prescreve):
                // alergia nova já vale na próxima receita, sem recarregar a tela.
                'check' => $asDoctor ? $this->service->clientCheck($patient) : null,
            ],
        ], $status);
    }

    /** Médico com cadastro ativo nesta clínica (mesmo Gate de emitir documento). */
    private function asDoctor(): bool
    {
        $entity = Entity::query()->find((string) session('selected_entity_id'));

        return $entity !== null && Gate::allows(EntityGate::IssueReport->value, $entity);
    }

    private function authorizeDoctor(): void
    {
        abort_unless($this->asDoctor(), 403, CurrentDoctor::issuerMissing()
            ? CurrentDoctor::missingMessage()
            : __('clinical_safety.allergies.doctor_only'));
    }

    private function assertPatient(Patient $patient): void
    {
        abort_unless((string) $patient->entity_id === (string) session('selected_entity_id'), 404);
        abort_unless(app(PatientAccess::class)->current(), 403);
    }

    private function assertAllergy(Patient $patient, PatientAllergy $allergy): void
    {
        $this->assertPatient($patient);
        abort_unless((string) $allergy->patient_id === (string) $patient->getKey()
            && (string) $allergy->entity_id === (string) $patient->entity_id, 404);
    }

    private function assertMedication(Patient $patient, PatientMedication $medication): void
    {
        $this->assertPatient($patient);
        abort_unless((string) $medication->patient_id === (string) $patient->getKey()
            && (string) $medication->entity_id === (string) $patient->entity_id, 404);
    }
}
