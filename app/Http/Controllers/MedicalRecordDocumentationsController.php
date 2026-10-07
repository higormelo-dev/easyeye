<?php

namespace App\Http\Controllers;

use App\Enums\{DataAccessPurpose, DocumentationType, EntityGate};
use App\Models\{Doctor, Entity, MedicalRecord, MedicalRecordDocumentation, Patient, ReportSetting, ReportSettingContent};
use App\Services\{MedicalRecordDocumentationService, MedicalRecordPdfService};
use App\Support\CurrentDoctor;
use App\Traits\LogsDataAccess;
use Illuminate\Http\{JsonResponse, Request, Response};
use Illuminate\Support\Facades\{DB, Gate};
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

        // Autoria = médico que emite (cadastro ativo; o Gate acima exige). A
        // chamada antes não passava o médico e estourava ArgumentCountError (500).
        $documentation = $this->service->store(
            $medicalrecord,
            $content,
            $sanitized,
            $validated['title'] ?? null,
            (string) $this->issuer()->id,
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
            'od'   => ['nullable', 'numeric', 'min:0', 'max:999'],
            'oe'   => ['nullable', 'numeric', 'min:0', 'max:999'],
            'time' => ['required', 'string', 'max:5'],
            // Achado de segurança (auditoria panel.* IDOR — ID via request body):
            // Doctor não tem entity_id direto (só via entity_user_id ->
            // entity_users.entity_id), então 'exists:doctors,id' sozinho
            // aceitava médico de OUTRA clínica. Mesma closure de
            // StoreMedicalRecordRequest::rules() para doctor_id.
            'doctor_id' => ['nullable', 'uuid', function ($attribute, $value, $fail) {
                $exists = DB::table('doctors')
                    ->join('entity_users', 'entity_users.id', '=', 'doctors.entity_user_id')
                    ->where('doctors.id', $value)
                    ->where('entity_users.entity_id', session()->get('selected_entity_id'))
                    ->whereNull('doctors.deleted_at')
                    ->exists();

                if (! $exists) {
                    $fail(__('actions.medical_records.doctor_exists_validation'));
                }
            }],
        ]);

        // Laudo emitido no nome de quem emite (médico logado, cadastro ativo) —
        // doctor_id do request (antes aceito: laudo no nome de um colega) e o
        // médico do prontuário não decidem mais a autoria.
        $doctorId = (string) $this->issuer()->id;

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

        // Prontuário assinado é imutável (CFM 2.227/2018, trait Signable): a
        // documentação emitida faz parte dele e não pode sumir depois.
        if ($medicalrecord->isLocked()) {
            return response()->json(['message' => __('actions.medical_records.documentation_locked')], 403);
        }

        $documentation->delete();

        return response()->json(['message' => __('actions.medical_records.documentation_deleted')]);
    }

    /** Médico emissor (logado, cadastro ativo) — 403 traduzido se não houver. */
    private function issuer(): Doctor
    {
        $doctor = CurrentDoctor::issuer();
        abort_if($doctor === null, 403, CurrentDoctor::missingMessage());

        return $doctor;
    }

    private function authorizeIssueReport(): void
    {
        $entityId = session('selected_entity_id');
        Gate::authorize(EntityGate::IssueReport->value, Entity::findOrFail($entityId));
    }

    private function assertRecordBelongsToPatient(Patient $patient, MedicalRecord $medicalRecord): void
    {
        // Achado de segurança (auditoria panel.* IDOR) — ver mesmo comentário em
        // MedicalRecordsController::assertMedicalRecordBelongsToPatient().
        abort_unless((string) $patient->entity_id === (string) session('selected_entity_id'), 404);
        abort_if($medicalRecord->patient_id !== $patient->id, 404);
    }

    private function assertDocumentationBelongsToRecord(MedicalRecord $medicalRecord, MedicalRecordDocumentation $documentation): void
    {
        abort_if($documentation->medical_record_id !== $medicalRecord->id, 404);
        abort_if($documentation->patient_id !== $medicalRecord->patient_id, 404);
    }

    /**
     * SEGURANÇA: ReportSetting tem EntityScope global (TenantScopeServiceProvider)
     * — pra um content de OUTRA clínica, a relação normal `reportSetting`
     * (sujeita ao scope) resolve pra null, não pro dono real. Confiar nisso
     * faria "de outra clínica" passar como "sem dono" — vazando o `content`
     * de um template privado de outro tenant pra quem soubesse/adivinhasse o
     * UUID. `withoutEntityScope()` vê o dono verdadeiro, scope ou não.
     */
    private function assertContentBelongsToCurrentEntity(ReportSettingContent $content): void
    {
        $selectedEntityId = (string) session('selected_entity_id');

        $settingEntityId = (string) (ReportSetting::query()
            ->withoutEntityScope()
            ->whereKey($content->report_setting_id)
            ->value('entity_id') ?? '');

        abort_if($settingEntityId !== '' && $settingEntityId !== $selectedEntityId, 404);
    }
}
