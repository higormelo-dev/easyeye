<?php

namespace App\Http\Controllers;

use App\Enums\DataAccessPurpose;
use App\Models\{MedicalRecord, MedicalRecordDocumentation, Patient, ReportSettingContent};
use App\Services\{MedicalRecordDocumentationService, MedicalRecordPdfService};
use App\Traits\LogsDataAccess;
use Illuminate\Http\{JsonResponse, Request, Response};

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
        $validated = $request->validate([
            'report_setting_content_id' => ['required', 'uuid', 'exists:report_setting_contents,id'],
            'title'                     => ['nullable', 'string', 'max:255'],
            'content'                   => ['required', 'string'],
        ]);

        $content = ReportSettingContent::findOrFail($validated['report_setting_content_id']);

        $documentation = $this->service->store(
            $medicalrecord,
            $content,
            $validated['content'],
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
        $this->logAccess($documentation, DataAccessPurpose::PatientCare, patientId: $patient->id);

        return $this->pdfService->generateDocumentation($documentation);
    }

    /**
     * Soft-delete a documentation.
     */
    public function destroy(Patient $patient, MedicalRecord $medicalrecord, MedicalRecordDocumentation $documentation): JsonResponse
    {
        $documentation->delete();

        return response()->json(['message' => __('actions.medical_records.documentation_deleted')]);
    }
}
