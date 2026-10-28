<?php

namespace App\Http\Controllers;

use App\Enums\{DataAccessPurpose, DocumentationType, EntityGate};
use App\Exceptions\AllergyConflictException;
use App\Models\{Doctor, DoctorDocumentTemplate, Entity, MedicalRecord, MedicalRecordDocumentation, Patient, ReportSetting, ReportSettingContent};
use App\Services\{MedicalRecordDocumentationService, MedicalRecordPdfService};
use App\Support\ClinicalSafety\{PrescriptionAllergyGuard, TermNormalizer};
use App\Support\CurrentDoctor;
use App\Support\Documents\DocumentDate;
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
        private readonly PrescriptionAllergyGuard $allergyGuard,
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
            // Receita que bate com alergia ativa: motivo para seguir (Fase 2 / A4).
            'allergy_override_reason' => ['nullable', 'string', 'max:1000'],
            // Data alternativa impressa (Fase 2 / B3) e modelo pessoal usado.
            'document_date' => DocumentDate::rules(),
            'template_id'   => ['nullable', 'uuid'],
        ], DocumentDate::messages());

        $content = ReportSettingContent::findOrFail($validated['report_setting_content_id']);
        $this->assertContentBelongsToCurrentEntity($content);

        // HTML rico do TinyMCE é renderizado em PDF + views (`{!! $content !!}`).
        // Sanitizar antes de persistir blinda contra XSS persistente. Profile
        // `medical` autoriza formatação clínica (listas, tabelas, headings).
        $sanitized = Purifier::clean($validated['content'], 'medical');

        // Autoria = médico que emite (cadastro ativo; o Gate acima exige). A
        // chamada antes não passava o médico e estourava ArgumentCountError (500).
        $issuer   = $this->issuer();
        $metadata = [];

        // Modelo pessoal usado (Fase 2 / B3): só do médico que emite. Carregado
        // ANTES da checagem de alergia (3ª revisão Codex, P1) — o editor de
        // Documentações só tem texto livre (não existe campo `items` aqui); um
        // modelo de RECEITA perde o vínculo com o catálogo ao virar HTML
        // ("LACRIMA PLUS — solução oftálmica" sem a palavra "colírio"), e a
        // checagem por texto não reconhece o conservante. Os itens
        // estruturados (medicine_id/active_ingredient) ficam guardados no
        // modelo mesmo — é lá que a checagem precisa buscá-los.
        $template = null;

        if (filled($validated['template_id'] ?? null)) {
            $template = DoctorDocumentTemplate::query()
                ->withoutGlobalScopes()
                ->whereNull('deleted_at')
                ->ownedBy((string) session('selected_entity_id'), (string) $issuer->id)
                ->find($validated['template_id']);

            if ($template === null) {
                return response()->json([
                    'message' => __('clinical_documents.templates.not_found'),
                    'errors'  => ['template_id' => [__('clinical_documents.templates.not_found')]],
                ], 422);
            }

            $metadata['personal_template'] = ['id' => (string) $template->id, 'name' => $template->name];
        }

        // Médico pode ter editado o texto depois de aplicar o modelo (tirou
        // ou trocou um remédio) — os itens do modelo ORIGINAL não acompanham
        // essa edição; sem reconciliar contra o texto final, a checagem
        // continuava cobrando motivo pra remédio que não está mais na
        // receita (13ª revisão Codex, P2).
        $templateItems = $template?->kind === 'prescription'
            ? $this->reconcileTemplateItems($template->payload['items'] ?? [], $sanitized)
            : [];

        // Modelo do tipo RECEITA (da clínica ou global) salvo pela
        // Documentação: mesma checagem de alergia do receituário.
        try {
            $override = $content->type === DocumentationType::Prescription
                ? $this->allergyGuard->guard($patient, $sanitized, $templateItems, $validated['allergy_override_reason'] ?? null)
                : null;
        } catch (AllergyConflictException $exception) {
            return response()->json($exception->toResponsePayload(), 422);
        }

        $documentation = $this->service->store(
            $medicalrecord,
            $content,
            $sanitized,
            $validated['title'] ?? null,
            (string) $issuer->id,
            $override,
            DocumentDate::alternative($validated['document_date'] ?? null),
            $metadata,
        );

        if ($override !== null) {
            $this->allergyGuard->recordOverride($documentation, $override);
        }

        return response()->json([
            'id'         => $documentation->id,
            'type'       => $documentation->type,
            'type_label' => $documentation->getTypeLabel(),
            'title'      => $documentation->title,
            'created_at' => $documentation->created_at?->format('d/m/Y H:i'),
            // Data alternativa impressa (Fase 2 / B3).
            'document_date' => $documentation->document_date?->format('d/m/Y'),
            'pdf_url'       => route('panel.patients.medicalrecords.documentations.pdf', [$patient, $medicalrecord, $documentation]),
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

    /**
     * Mantém só os itens do modelo que ainda aparecem no texto final — pelo
     * NOME/marca ("Amoxil 500mg" → "amoxil") OU pelo princípio ativo, igual
     * PatientClinicalSafetyService faz pra reconciliar texto livre. Item sem
     * termo nenhum pra comparar fica (não dá pra provar que saiu).
     *
     * Checar só o princípio ativo quando ele existe (e nunca o nome) perdia
     * marca que o médico manteve no texto só com o nome comercial ("Lacrima
     * Plus"), sem citar "dextrana"/"hipromelose" — o item saía da checagem
     * sem o remédio ter saído da receita. Se o catálogo desativar esse
     * remédio depois, a busca por texto livre (que exige `active=true`)
     * também para de achá-lo: ficava sem QUALQUER aviso (14ª revisão Codex,
     * P1).
     *
     * @param array<int, array<string, mixed>> $items
     *
     * @return array<int, array<string, mixed>>
     */
    private function reconcileTemplateItems(array $items, string $html): array
    {
        if ($items === []) {
            return [];
        }

        $textCore = TermNormalizer::core($html);

        return array_values(array_filter($items, function (array $item) use ($textCore) {
            $name  = (string) ($item['name'] ?? '');
            $terms = [
                ...TermNormalizer::terms((string) preg_replace('/\s*\d.*$/u', '', $name) ?: $name),
                ...TermNormalizer::terms((string) ($item['active_ingredient'] ?? '')),
            ];

            if ($terms === []) {
                return true;
            }

            foreach ($terms as $term) {
                if (TermNormalizer::contains($textCore, $term)) {
                    return true;
                }
            }

            return false;
        }));
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
