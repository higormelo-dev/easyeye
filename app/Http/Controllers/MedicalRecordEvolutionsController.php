<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\{DataAccessPurpose, EntityGate};
use App\Models\{Doctor, Entity, MedicalRecord, MedicalRecordEvolution, Patient};
use App\Traits\LogsDataAccess;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Gate;

/**
 * Evoluções clínicas em texto livre do prontuário.
 *
 * Append-only por desenho (CFM: registro clínico imutável após criação):
 * só `index` (listagem cronológica por paciente) e `store`. Correção de uma
 * evolução entra como nova evolução — nunca update/delete.
 *
 * Escrita exclusiva de médico via Gate IssueReport (mesmo gate das quick
 * actions de emissão de documento). Leitura segue o grupo de rotas do
 * prontuário (admin/doctor/secretary).
 */
class MedicalRecordEvolutionsController extends Controller
{
    use LogsDataAccess;

    /**
     * Listagem cronológica (mais recente primeiro) de TODAS as evoluções do
     * paciente — atravessa prontuários. Consumida pelo modal "Evolução".
     */
    public function index(Patient $patient): JsonResponse
    {
        $entityId = (string) session('selected_entity_id');

        abort_unless((string) $patient->entity_id === $entityId, 404);

        $evolutions = MedicalRecordEvolution::query()
            ->with('doctor.person:id,full_name')
            ->where('entity_id', $entityId)
            ->where('patient_id', $patient->id)
            ->orderByDesc('created_at')
            ->get();

        // F10 — leitura de evolução é acesso a dado clínico sensível.
        $this->logAccess(
            $patient,
            DataAccessPurpose::PatientCare,
            patientId: $patient->id,
        );

        return response()->json([
            'data' => $evolutions->map(fn (MedicalRecordEvolution $e) => $this->serialize($e))->values(),
        ]);
    }

    public function store(Request $request, Patient $patient, MedicalRecord $medicalrecord): JsonResponse
    {
        abort_if($medicalrecord->patient_id !== $patient->id, 404);

        $entityId = (string) session('selected_entity_id');
        $entity   = Entity::findOrFail($entityId);

        abort_unless((string) $patient->entity_id === $entityId, 404);

        // Escrita de evolução é ato médico — mesmo gate doctor-only usado nas
        // quick actions de emissão de documentos.
        Gate::authorize(EntityGate::IssueReport->value, $entity);

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:20000'],
        ]);

        // Médico do prontuário; fallback: usuário logado é médico → resolve
        // automaticamente (mesmo padrão de MedicalRecordQuickActionsController).
        $doctor = $medicalrecord->doctor ?? Doctor::with('person')
            ->whereHas('entityUser', fn ($q) => $q
                ->where('entity_id', $entity->id)
                ->where('user_id', auth()->id()))
            ->first();

        if (! $doctor) {
            return response()->json([
                'message' => 'Selecione o médico responsável antes de registrar a evolução.',
            ], 422);
        }

        $evolution = MedicalRecordEvolution::create([
            'entity_id'         => $entity->id,
            'patient_id'        => $patient->id,
            'medical_record_id' => $medicalrecord->id,
            'doctor_id'         => $doctor->id,
            'content'           => $validated['content'],
        ]);

        $evolution->setRelation('doctor', $doctor->loadMissing('person'));

        $this->logAccess(
            $evolution,
            DataAccessPurpose::PatientCare,
            patientId: $patient->id,
        );

        return response()->json($this->serialize($evolution), 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(MedicalRecordEvolution $evolution): array
    {
        return [
            'id'          => $evolution->id,
            'content'     => $evolution->content,
            'doctor_name' => $evolution->doctor?->person?->full_name,
            'created_at'  => $evolution->created_at?->format('d/m/Y H:i'),
        ];
    }
}
