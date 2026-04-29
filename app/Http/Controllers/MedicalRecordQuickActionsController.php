<?php

namespace App\Http\Controllers;

use App\Enums\EntityGate;
use App\Models\{Entity, MedicalRecord, Patient};
use App\Services\MedicalRecordQuickActionService;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

class MedicalRecordQuickActionsController extends Controller
{
    public function __construct(
        private readonly MedicalRecordQuickActionService $service,
    ) {
    }

    public function issue(Request $request, Patient $patient, MedicalRecord $medicalrecord, string $action): JsonResponse
    {
        $this->assertMedicalRecordBelongsToPatient($patient, $medicalrecord);
        $this->authorizeIssueReport();

        $payload = $this->validatePayload($request, $action);
        $entity  = Entity::findOrFail(session('selected_entity_id'));
        $doctor  = $medicalrecord->doctor;

        // Fallback: usuário logado é médico → resolve automaticamente.
        if (! $doctor) {
            $doctor = \App\Models\Doctor::with('person')
                ->whereHas('entityUser', fn ($q) => $q
                    ->where('entity_id', $entity->id)
                    ->where('user_id', auth()->id()))
                ->first();
        }

        if (! $doctor) {
            return response()->json([
                'message' => 'Selecione o médico responsável antes de emitir o documento.',
            ], 422);
        }

        try {
            $documentation = $this->service->issue(
                $action,
                $medicalrecord,
                $patient,
                $doctor,
                $entity,
                $payload,
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'id'         => $documentation->id,
            'type'       => $documentation->type,
            'type_label' => $documentation->getTypeLabel(),
            'title'      => $documentation->title,
            'created_at' => $documentation->created_at?->format('d/m/Y H:i'),
            'pdf_url'    => route('panel.patients.medicalrecords.documentations.pdf', [$patient, $medicalrecord, $documentation]),
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, string $action): array
    {
        return match ($action) {
            'medical-certificate' => $request->validate([
                'days' => ['required', 'integer', 'min:1', 'max:365'],
                'date' => ['nullable', 'date_format:d/m/Y'],
            ]),
            'cataract-prescription' => $request->validate([
                'eye'          => ['required', 'string', 'max:32'],
                'date_surgery' => ['nullable', 'date_format:d/m/Y'],
                'hour_surgery' => ['nullable', 'date_format:H:i'],
                'template'     => ['nullable', 'string', 'in:1,2,3,pre_operatorio,pos_operatorio,instrucoes_cirurgicas'],
            ]),
            'medical-declaration', 'medication-prescription', 'procedure-request' => $request->validate([
                'content' => ['required', 'string'],
            ]),
            'lens-prescription' => $request->validate([
                'mode' => ['required', 'in:dynamic,static,presbyopia_dynamic,presbyopia'],
            ]),
            default => [],
        };
    }

    private function authorizeIssueReport(): void
    {
        $entityId = session('selected_entity_id');
        Gate::authorize(EntityGate::IssueReport->value, Entity::findOrFail($entityId));
    }

    private function assertMedicalRecordBelongsToPatient(Patient $patient, MedicalRecord $medicalrecord): void
    {
        abort_if($medicalrecord->patient_id !== $patient->id, 404);
    }
}
