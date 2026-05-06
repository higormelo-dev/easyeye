<?php

namespace App\Http\Controllers;

use App\Enums\{DataAccessPurpose, DocumentationType, EntityGate};
use App\Models\{Doctor, Entity, MedicalRecord, MedicalRecordDocumentation, Patient, ReportSettingContent};
use App\Services\{MedicalRecordDocumentationService, MedicalRecordPdfService};
use App\Traits\LogsDataAccess;
use Illuminate\Http\{JsonResponse, Request, Response};
use Illuminate\Support\Facades\Gate;
use Mews\Purifier\Facades\Purifier;

class MedicalRecordDocumentationsController extends Controller
{
    use LogsDataAccess;

    public function __construct(
        private readonly MedicalRecordDocumentationService $service,
        private readonly MedicalRecordPdfService $pdfService,
    ) {
    }

    /**
     * Save a new documentation for the given medical record.
     */
    public function store(Request $request, Patient $patient, MedicalRecord $medicalrecord): JsonResponse
    {
        $this->assertRecordBelongsToPatient($patient, $medicalrecord);
        $this->authorizeIssueReport();

        $validated = $request->validate([
            'report_setting_content_id' => ['required', 'uuid', 'exists:report_setting_contents,id'],
            'title'                     => ['nullable', 'string', 'max:255'],
            'content'                   => ['required', 'string'],
        ]);

        $content = ReportSettingContent::findOrFail($validated['report_setting_content_id']);
        $this->assertContentBelongsToCurrentEntity($content);

        // HTML rico do TinyMCE é renderizado em PDF + views (`{!! $content !!}`).
        // Sanitizar antes de persistir blinda contra XSS persistente. Profile
        // `medical` autoriza formatação clínica (listas, tabelas, headings).
        $sanitized = Purifier::clean($validated['content'], 'medical');

        $documentation = $this->service->store(
            $medicalrecord,
            $content,
            $sanitized,
            $validated['title'] ?? null,
        );

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
     * Return documentation content as JSON.
     */
    public function show(Patient $patient, MedicalRecord $medicalrecord, MedicalRecordDocumentation $documentation): JsonResponse
    {
        $this->assertRecordBelongsToPatient($patient, $medicalrecord);
        $this->assertDocumentationBelongsToRecord($medicalrecord, $documentation);

        $this->logAccess($documentation, DataAccessPurpose::PatientCare, patientId: $patient->id);

        return response()->json([
            'id'         => $documentation->id,
            'type'       => $documentation->type,
            'type_label' => $documentation->getTypeLabel(),
            'title'      => $documentation->title,
            'content'    => $documentation->content,
            'created_at' => $documentation->created_at?->format('d/m/Y H:i'),
        ]);
    }

    /**
     * Stream a PDF of the documentation.
     */
    public function pdf(Patient $patient, MedicalRecord $medicalrecord, MedicalRecordDocumentation $documentation): Response
    {
        $this->assertRecordBelongsToPatient($patient, $medicalrecord);
        $this->assertDocumentationBelongsToRecord($medicalrecord, $documentation);

        $this->logAccess($documentation, DataAccessPurpose::PatientCare, patientId: $patient->id);

        return $this->pdfService->generateDocumentation($documentation);
    }

    /**
     * Salva um laudo de tonometria e retorna a URL do PDF para abrir no modal.
     */
    public function storeTonometry(Request $request, Patient $patient, MedicalRecord $medicalrecord): JsonResponse
    {
        $this->assertRecordBelongsToPatient($patient, $medicalrecord);
        $this->authorizeIssueReport();

        $validated = $request->validate([
            'od'        => ['nullable', 'numeric', 'min:0', 'max:999'],
            'oe'        => ['nullable', 'numeric', 'min:0', 'max:999'],
            'time'      => ['required', 'string', 'max:5'],
            'doctor_id' => ['nullable', 'uuid'],
        ]);

        $entityId = session('selected_entity_id');

        // Resolve doctor: explicit request → record's doctor → logged-in user's doctor profile.
        $doctorId = $validated['doctor_id'] ?? $medicalrecord->doctor_id;

        if (! $doctorId) {
            $doctorId = Doctor::whereHas('entityUser', fn ($q) => $q
                ->where('entity_id', $entityId)
                ->where('user_id', auth()->id()))
                ->value('id');
        }

        abort_if(! $doctorId, 422, __('actions.medical_records.doctor_required_for_print'));

        $doc = MedicalRecordDocumentation::create([
            'medical_record_id' => $medicalrecord->id,
            'patient_id'        => $patient->id,
            'doctor_id'         => $doctorId,
            'type'              => DocumentationType::Tonometry,
            'title'             => 'Laudo de Tonômetria — ' . now()->format('d/m/Y H:i'),
            'content'           => json_encode($validated),
        ]);

        return response()->json([
            'id'         => $doc->id,
            'type'       => $doc->type,
            'type_label' => $doc->getTypeLabel(),
            'title'      => $doc->title,
            'created_at' => $doc->created_at->format('d/m/Y H:i'),
            'pdf_url'    => route('panel.patients.medicalrecords.documentations.pdf', [$patient, $medicalrecord, $doc]),
        ], 201);
    }

    /**
     * Soft-delete a documentation.
     */
    public function destroy(Patient $patient, MedicalRecord $medicalrecord, MedicalRecordDocumentation $documentation): JsonResponse
    {
        $this->assertRecordBelongsToPatient($patient, $medicalrecord);
        $this->assertDocumentationBelongsToRecord($medicalrecord, $documentation);
        $this->authorizeIssueReport();

        $documentation->delete();

        return response()->json(['message' => __('actions.medical_records.documentation_deleted')]);
    }

    private function authorizeIssueReport(): void
    {
        $entityId = session('selected_entity_id');
        Gate::authorize(EntityGate::IssueReport->value, Entity::findOrFail($entityId));
    }

    private function assertRecordBelongsToPatient(Patient $patient, MedicalRecord $medicalRecord): void
    {
        abort_if($medicalRecord->patient_id !== $patient->id, 404);
    }

    private function assertDocumentationBelongsToRecord(MedicalRecord $medicalRecord, MedicalRecordDocumentation $documentation): void
    {
        abort_if($documentation->medical_record_id !== $medicalRecord->id, 404);
        abort_if($documentation->patient_id !== $medicalRecord->patient_id, 404);
    }

    private function assertContentBelongsToCurrentEntity(ReportSettingContent $content): void
    {
        $selectedEntityId = (string) session('selected_entity_id');
        $content->loadMissing('reportSetting');

        $settingEntityId = (string) ($content->reportSetting?->entity_id ?? '');
        abort_if($settingEntityId !== '' && $settingEntityId !== $selectedEntityId, 404);
    }
}
