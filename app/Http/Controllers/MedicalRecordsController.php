<?php

namespace App\Http\Controllers;

use App\Domains\AI\Services\{AiCreditWalletService, AiProviderSettings, AiQuotaService};
use App\Enums\AI\AiRunMode;
use App\Enums\{ClientRule, DataAccessPurpose, ExamReportRegistry, FeatureKey};
use App\Exceptions\LockedMedicalRecordException;
use App\Http\Requests\{StoreMedicalRecordRequest, UpdateMedicalRecordRequest};
use App\Models\{AdditionType, ColorVisionType, CoverTestType, Doctor, Entity, Lense,
    MedicalRecord, NearPointConvergence, Patient, VisualAcuityType};
use App\Models\ReportSettingContent;
use App\Services\{FeatureGateService, LensFormatterService, MedicalRecordDocumentationService, MedicalRecordPdfService, MedicalRecordService, UsageMeterService};
use App\Traits\LogsDataAccess;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request, Response};
use Illuminate\Support\{Collection, Str};
use Inertia\{Inertia, Response as InertiaResponse};

class MedicalRecordsController extends Controller
{
    use LogsDataAccess;

    public function __construct(
        private readonly MedicalRecordService $service,
        private readonly MedicalRecordDocumentationService $documentationService,
        private readonly MedicalRecordPdfService $pdfService,
        private readonly LensFormatterService $lensFormatter,
        private readonly FeatureGateService $featureGate,
        private readonly UsageMeterService $usageMeter,
        private readonly AiCreditWalletService $aiWallet,
        private readonly AiProviderSettings $aiProviderSettings,
        private readonly AiQuotaService $aiQuotaService,
    ) {
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Patient $patient): InertiaResponse
    {
        $patient->load(['person', 'covenant', 'skinType', 'irisType']);

        return Inertia::render('Panel/MedicalRecords/Index', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('actions.sidemenu.patients'), 'url' => route('panel.patients.index'), 'active' => false],
                ['label' => $patient->person?->full_name ?? $patient->code, 'url' => '#', 'active' => false],
                ['label' => __('actions.medical_records.title'), 'url' => '#', 'active' => true],
            ],
            'patient' => [
                'id'            => (string) $patient->id,
                'code'          => $patient->code,
                'full_name'     => $patient->person?->full_name,
                'birth_date'    => $patient->person?->birth_date?->format('d/m/Y'),
                'age'           => $patient->person?->birth_date?->age,
                'gender'        => $patient->person?->gender,
                'cpf'           => $patient->person?->cpf,
                'phone'         => $patient->person?->cellphone ?? $patient->person?->telephone,
                'email'         => $patient->person?->email,
                'covenant_name' => $patient->covenant?->name,
                'skin_type'     => $patient->skinType?->name,
                'iris_type'     => $patient->irisType?->name,
            ],
            'urls' => [
                'ajax_list' => route('panel.patients.medicalrecords.ajaxlist', $patient),
                'create'    => route('panel.patients.medicalrecords.create', $patient),
                'patients'  => route('panel.patients.index'),
            ],
            // Apenas médicos podem criar/editar/excluir prontuário (CFM Res. 2.227/2018).
            // A UI consome essa flag para esconder botões e o backend valida via
            // middleware `entity.role:doctor` nas rotas write.
            'isDoctor' => session('selected_entity_user_rule') === ClientRule::Doctor->value,
            't'        => trans('actions.medical_records'),
        ]);
    }

    /**
     * Retorna prontuários paginados como JSON puro para a timeline Vue.
     * O frontend renderiza cada item (não vem HTML do servidor).
     */
    public function ajaxList(Request $request, Patient $patient): JsonResponse
    {
        // CFM Res. 2.227/2018 + LGPD Art. 37 — registra acesso à lista de prontuários do paciente
        $this->logAccess($patient, DataAccessPurpose::PatientCare);

        $records = MedicalRecord::with(['doctor.person', 'doctor.entityUser', 'documentations'])
            ->where('patient_id', $patient->id)
            ->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 10));

        return response()->json([
            'data' => $records->getCollection()->map(fn (MedicalRecord $r) => [
                'id'                   => (string) $r->id,
                'code'                 => $r->code,
                'created_at'           => $r->created_at?->format('d/m/Y H:i'),
                'created_at_iso'       => $r->created_at?->toIso8601String(),
                'doctor_name'          => $r->doctor?->person?->full_name,
                'main_complaint'       => $r->main_complaint,
                'diagnosis_cids'       => $r->diagnosis_cids ?? [],
                'clinical_conduct'     => $r->clinical_conduct,
                'is_signed'            => $r->isSigned(),
                'is_locked'            => $r->isLocked(),
                'signed_at'            => $r->signed_at?->format('d/m/Y H:i'),
                'documentations_count' => $r->documentations->count(),
                'edit_url'             => route('panel.patients.medicalrecords.edit', [$patient, $r]),
                'show_url'             => route('panel.patients.medicalrecords.show', [$patient, $r]),
                'pdf_url'              => route('panel.patients.medicalrecords.pdf', [$patient, $r]),
                'destroy_url'          => route('panel.patients.medicalrecords.destroy', [$patient, $r]),
            ]),
            'has_more'  => $records->hasMorePages(),
            'next_page' => $records->currentPage() + 1,
            'total'     => $records->total(),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Patient $patient): InertiaResponse
    {
        $patient->load(['person', 'covenant', 'skinType', 'irisType']);
        $props                  = $this->buildFormProps($patient, null);
        $props['breadcrumbs'][] = [
            'label'  => __('actions.medical_records.create'),
            'url'    => '#',
            'active' => true,
        ];

        return Inertia::render('Panel/MedicalRecords/Create', $props);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreMedicalRecordRequest $request, Patient $patient): RedirectResponse
    {
        $validated = $request->validated();

        $record = $this->service->store($validated, $patient);

        return $this->redirectAfterSave(
            $patient,
            $validated,
            __('actions.medical_records.saved'),
            $record,
        );
    }

    /**
     * Display the specified resource as JSON for the detail offcanvas.
     */
    public function show(Patient $patient, MedicalRecord $medicalrecord): JsonResponse
    {
        $this->assertMedicalRecordBelongsToPatient($patient, $medicalrecord);
        $this->logAccess($medicalrecord, DataAccessPurpose::PatientCare, patientId: $patient->id);

        $medicalrecord->load([
            'doctor.person',
            'visualAcuityType',
            'nearPointConvergence',
            'coverTestType',
            'colorVisionType',
            // NOTA: relations do model nomeadas `visualAcuityTypeWith*` /
            // `WitCorrection` (typo legado preservado por compat — não
            // renomear sem migration). Eager-load usa nomes EXATOS do model.
            'visualAcuityTypeWithoutCorrectionRight',
            'visualAcuityTypeWithoutCorrectionLeft',
            'visualAcuityTypeWitCorrectionRight',
            'visualAcuityTypeWitCorrectionLeft',
            'additionType',
            'lensAway',
            'lensNear',
            'signedBy.user',
            'documentations.doctor.person',
            'documentations.aiRun',
        ]);

        return response()->json([
            'id'                   => $medicalrecord->id,
            'code'                 => $medicalrecord->code,
            'created_at_formatted' => $medicalrecord->created_at?->format('d/m/Y H:i'),
            'doctor_name'          => $medicalrecord->doctor?->person?->full_name ?? '',
            // Anamnese
            'main_complaint'          => $medicalrecord->main_complaint,
            'hda'                     => $medicalrecord->hda,
            'diabetic'                => $medicalrecord->diabetic,
            'diabetic_family'         => $medicalrecord->diabetic_family,
            'hypertensive'            => $medicalrecord->hypertensive,
            'hypertensive_family'     => $medicalrecord->hypertensive_family,
            'glaucomatous'            => $medicalrecord->glaucomatous,
            'glaucomatous_family'     => $medicalrecord->glaucomatous_family,
            'ocular_surgical_history' => $medicalrecord->ocular_surgical_history,
            'medications_in_use'      => $medicalrecord->medications_in_use,
            // Exame físico
            'visual_acuity_type'     => $medicalrecord->visualAcuityType?->name,
            'near_point_convergence' => $medicalrecord->nearPointConvergence?->name,
            'cover_test_type'        => $medicalrecord->coverTestType?->name,
            'color_vision_type'      => $medicalrecord->colorVisionType?->name,
            'ocular_motility'        => $medicalrecord->ocular_motility,
            'tonometer_right'        => $medicalrecord->tonometer_right,
            'tonometer_left'         => $medicalrecord->tonometer_left,
            'tonometer_time'         => $medicalrecord->tonometer_time,
            'pachymetry_right'       => $medicalrecord->pachymetry_right,
            'pachymetry_left'        => $medicalrecord->pachymetry_left,
            'gonioscopy_right'       => $medicalrecord->gonioscopy_right,
            'gonioscopy_left'        => $medicalrecord->gonioscopy_left,
            // Refração
            'visual_acuity_without_correction_right' => $medicalrecord->visualAcuityTypeWithoutCorrectionRight?->name,
            'visual_acuity_without_correction_left'  => $medicalrecord->visualAcuityTypeWithoutCorrectionLeft?->name,
            'visual_acuity_with_correction_right'    => $medicalrecord->visualAcuityTypeWitCorrectionRight?->name,
            'visual_acuity_with_correction_left'     => $medicalrecord->visualAcuityTypeWitCorrectionLeft?->name,
            'dynamic_spherical_right'                => $medicalrecord->dynamic_spherical_right,
            'dynamic_spherical_left'                 => $medicalrecord->dynamic_spherical_left,
            'dynamic_cylindrical_right'              => $medicalrecord->dynamic_cylindrical_right,
            'dynamic_cylindrical_left'               => $medicalrecord->dynamic_cylindrical_left,
            'dynamic_axis_right'                     => $medicalrecord->dynamic_axis_right,
            'dynamic_axis_left'                      => $medicalrecord->dynamic_axis_left,
            'addition_type'                          => $medicalrecord->additionType?->name,
            'static_spherical_right'                 => $medicalrecord->static_spherical_right,
            'static_spherical_left'                  => $medicalrecord->static_spherical_left,
            'static_cylindrical_right'               => $medicalrecord->static_cylindrical_right,
            'static_cylindrical_left'                => $medicalrecord->static_cylindrical_left,
            'static_axis_right'                      => $medicalrecord->static_axis_right,
            'static_axis_left'                       => $medicalrecord->static_axis_left,
            'lens_away'                              => $medicalrecord->lensAway?->name,
            'lens_near'                              => $medicalrecord->lensNear?->name,
            // Achados
            'biomicroscopy_right'   => $medicalrecord->biomicroscopy_right,
            'biomicroscopy_left'    => $medicalrecord->biomicroscopy_left,
            'fundoscopy_right'      => $medicalrecord->fundoscopy_right,
            'fundoscopy_left'       => $medicalrecord->fundoscopy_left,
            'observation_general'   => $medicalrecord->observation_general,
            'observation_of_lenses' => $medicalrecord->observation_of_lenses,
            // Diagnóstico & conduta
            'diagnosis_cids'   => $medicalrecord->diagnosis_cids ?? [],
            'clinical_conduct' => $medicalrecord->clinical_conduct,
            'follow_up_days'   => $medicalrecord->follow_up_days,
            // Assinatura (CFM Res. 2.227/2018)
            'is_locked'           => $medicalrecord->isLocked(),
            'is_signed'           => $medicalrecord->isSigned(),
            'signed_at_formatted' => $medicalrecord->signed_at?->format('d/m/Y H:i'),
            'signed_by_name'      => $medicalrecord->signedBy?->user?->name,
            // Documentações
            'documentations' => $medicalrecord->documentations->map(fn ($d) => [
                'id'                => $d->id,
                'type'              => $d->type,
                'type_label'        => $d->getTypeLabel(),
                'title'             => $d->title,
                'doctor_name'       => $d->doctor?->person?->full_name ?? '',
                'created_at'        => $d->created_at?->format('d/m/Y H:i'),
                'is_ai'             => $d->ai_run_id !== null,
                'ai_workflow_label' => $d->ai_run_id && $d->aiRun ? __('ai.workflow_' . $d->aiRun->workflow) : null,
                'pdf_url'           => route('panel.patients.medicalrecords.documentations.pdf', [$patient, $medicalrecord, $d]),
            ]),
            // URLs de ação
            'edit_url'      => route('panel.patients.medicalrecords.edit', [$patient, $medicalrecord]),
            'pdf_url'       => route('panel.patients.medicalrecords.pdf', [$patient, $medicalrecord]),
            'templates_url' => route('panel.patients.medicalrecords.templates', [$patient, $medicalrecord]),
            // Labels i18n consumidos pelo `buildDetailHtml` no offcanvas.
            'labels' => [
                'yes'          => __('actions.medical_records.yes'),
                'no'           => __('actions.medical_records.no'),
                'not_informed' => __('actions.medical_records.not_informed'),
                'complaint'    => __('actions.medical_records.complaint'),
                'history'      => __('actions.medical_records.history'),
                'diabetic'     => __('actions.medical_records.diabetic'),
                'hypertensive' => __('actions.medical_records.hypertensive'),
                'glaucomatous' => __('actions.medical_records.glaucomatous'),
                'family'       => __('actions.medical_records.family'),
                'tonometry'    => __('actions.medical_records.tonometry'),
                'general_obs'  => __('actions.medical_records.general_obs'),
                'lenses_obs'   => __('actions.medical_records.lenses_obs'),
            ],
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Patient $patient, MedicalRecord $medicalrecord): InertiaResponse
    {
        $this->assertMedicalRecordBelongsToPatient($patient, $medicalrecord);
        $this->logAccess($medicalrecord, DataAccessPurpose::PatientCare, patientId: $patient->id);

        $patient->load(['person', 'covenant', 'skinType', 'irisType']);
        $medicalrecord->load([
            'doctor.person',
            'documentations.doctor.person',
            'documentations.reportSettingContent',
            'documentations.aiRun',
            'files',
        ]);

        $props                  = $this->buildFormProps($patient, $medicalrecord);
        $props['breadcrumbs'][] = [
            'label'  => __('actions.medical_records.edit'),
            'url'    => '#',
            'active' => true,
        ];

        return Inertia::render('Panel/MedicalRecords/Edit', $props);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateMedicalRecordRequest $request, Patient $patient, MedicalRecord $medicalrecord): RedirectResponse
    {
        $this->assertMedicalRecordBelongsToPatient($patient, $medicalrecord);
        $validated = $request->validated();

        try {
            $this->service->update($medicalrecord, $validated);
        } catch (LockedMedicalRecordException) {
            return back()->with('error', __('actions.medical_records.locked'));
        }

        return $this->redirectAfterSave($patient, $validated, __('actions.medical_records.updated'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Patient $patient, MedicalRecord $medicalrecord): RedirectResponse
    {
        $this->assertMedicalRecordBelongsToPatient($patient, $medicalrecord);

        try {
            $this->service->delete($medicalrecord);
        } catch (LockedMedicalRecordException) {
            return back()->with('error', __('actions.medical_records.locked'));
        }

        return redirect()
            ->route('panel.patients.medicalrecords.index', $patient)
            ->with('message', __('actions.medical_records.deleted'));
    }

    /**
     * Restore a soft-deleted record.
     */
    public function restore(Patient $patient, string $medicalrecord): RedirectResponse
    {
        $this->service->restore($medicalrecord);

        return redirect()
            ->route('panel.patients.medicalrecords.index', $patient)
            ->with('message', __('actions.medical_records.restored'));
    }

    /**
     * Generate and stream a PDF of the full clinical record.
     */
    public function pdf(Patient $patient, MedicalRecord $medicalrecord): Response
    {
        $this->assertMedicalRecordBelongsToPatient($patient, $medicalrecord);
        $this->logAccess($medicalrecord, DataAccessPurpose::PatientCare, patientId: $patient->id);

        return $this->pdfService->generateRecord($medicalrecord);
    }

    /**
     * Generate and stream a Laudo de Tonômetria PDF.
     * Accepts ?time=HH:MM to stamp the measurement time captured at print moment.
     */
    public function tonometryPdf(Request $request, Patient $patient, MedicalRecord $medicalrecord): Response
    {
        $this->assertMedicalRecordBelongsToPatient($patient, $medicalrecord);
        $this->logAccess($medicalrecord, DataAccessPurpose::PatientCare, patientId: $patient->id);

        // Auto-resolve doctor_id se prontuário ainda não tem (admin precisa selecionar
        // antes; médico logado é resolvido automaticamente).
        if (! $medicalrecord->doctor_id) {
            $entityId = session('selected_entity_id');
            $doctor   = Doctor::whereHas('entityUser', fn ($q) => $q
                ->where('entity_id', $entityId)
                ->where('user_id', auth()->id()))
                ->first();
            abort_if(! $doctor, 422, __('actions.medical_records.doctor_required') ?? 'Selecione o médico responsável antes de imprimir.');
            $medicalrecord->doctor_id = $doctor->id;
        }

        $time = $request->string('time')->trim()->value() ?: now()->format('H:i');

        return $this->pdfService->generateTonometry($medicalrecord, $time);
    }

    /**
     * Format lens reference (esférico / cilíndrico / eixo) following oftalmological convention.
     */
    public function lensFormat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'kind'  => ['required', 'in:spherical,cylindrical,axis'],
            'value' => ['nullable', 'string', 'max:16'],
        ]);

        return response()->json([
            'value' => $this->lensFormatter->format($validated['kind'], $validated['value'] ?? null),
        ]);
    }

    /**
     * Calculate presbyopia addition and return static sphericals as JSON.
     */
    public function calculatePresbyopia(Request $request, Patient $patient): JsonResponse
    {
        $validated = $request->validate([
            'dynamic_spherical_right' => ['nullable', 'numeric'],
            'dynamic_spherical_left'  => ['nullable', 'numeric'],
            'addition'                => ['nullable', 'numeric'],
        ]);

        $result = $this->service->calculatePresbyopia(
            isset($validated['dynamic_spherical_right']) ? (float) $validated['dynamic_spherical_right'] : null,
            isset($validated['dynamic_spherical_left']) ? (float) $validated['dynamic_spherical_left'] : null,
            isset($validated['addition']) ? (float) $validated['addition'] : null,
        );

        return response()->json($result);
    }

    /**
     * Return available documentation templates for a medical record.
     */
    public function templates(Patient $patient, MedicalRecord $medicalrecord): JsonResponse
    {
        $this->assertMedicalRecordBelongsToPatient($patient, $medicalrecord);
        $entityId  = session('selected_entity_id');
        $templates = $this->documentationService->getActiveTemplates($entityId);

        return response()->json($templates);
    }

    /**
     * Preview a resolved template before saving a documentation.
     */
    public function templatePreview(Request $request, Patient $patient, MedicalRecord $medicalrecord): JsonResponse
    {
        $this->assertMedicalRecordBelongsToPatient($patient, $medicalrecord);
        $validated = $request->validate([
            'report_setting_content_id' => ['required', 'uuid', 'exists:report_setting_contents,id'],
        ]);

        $content = ReportSettingContent::findOrFail($validated['report_setting_content_id']);
        $this->assertTemplateBelongsToCurrentEntity($content);
        $doctor = $medicalrecord->doctor ?? Doctor::find($request->doctor_id);
        $entity = Entity::findOrFail(session('selected_entity_id'));

        $resolved = $this->documentationService->loadTemplate($content, $patient, $doctor, $entity, $medicalrecord);

        // Strip placeholders restantes ({{CONTEUDO_LIVRE}}, {{LISTA_MEDICAMENTOS}}, etc.)
        // que não são resolvidos pelo TemplateVariableResolver (são preenchidos
        // dinamicamente via quick-actions). Em fluxo livre via select, médico
        // edita manualmente — placeholder literal só polui o textarea.
        $html = preg_replace('/\{\{[A-Z_][A-Z0-9_]*\}\}/u', '', $resolved['html']) ?? $resolved['html'];

        // Remove o cabeçalho de data já resolvido pelo TemplateVariableResolver
        // (`<p style="text-align:right">SÃO PAULO/SP, 21 de maio de 2026</p>` e
        // o `<p>&nbsp;</p>` espaçador que segue). Motivo: a data e localização
        // já aparecem no bloco de assinatura do PDF (`_signature.blade.php`) —
        // mostrar no topo do editor duplica visualmente e polui o textarea.
        $html = $this->stripResolvedDateHeader($html);

        return response()->json(['content' => $html, 'unresolved' => $resolved['unresolved']]);
    }

    /**
     * Remove o `<p style="text-align:right">{LOCAL_DATA já resolvido}</p>` e o
     * `<p>&nbsp;</p>` que normalmente o sucede no template. Pareia o cabeçalho
     * pela posição (início do HTML) + alinhamento à direita, sem hardcoded
     * de cidade ou mês — funciona para qualquer entity.
     */
    private function stripResolvedDateHeader(string $html): string
    {
        // Padrão: <p ... text-align:right ...>QUALQUER COISA</p>(\s*<p>&nbsp;</p>)?
        // Só remove se estiver no INÍCIO do conteúdo (no máximo após whitespace).
        $pattern = '/^\s*<p\s+[^>]*text-align\s*:\s*right[^>]*>[^<]*<\/p>\s*(?:<p[^>]*>&nbsp;<\/p>)?\s*/iu';

        return preg_replace($pattern, '', $html, 1) ?? $html;
    }

    /**
     * Constrói o payload de props para Inertia (Create e Edit).
     * Substitui o antigo commonFormData() do fluxo Blade — agora retorna tudo
     * serializado em arrays puros prontos para consumo Vue.
     */
    /**
     * Prontuários anteriores do paciente (somente visualização via modal).
     * Exclui o registro em edição; escopo de tenant garantido pelo patient_id
     * (o paciente já vem resolvido dentro da entidade ativa).
     */
    private function serializePreviousRecords(Patient $patient, ?MedicalRecord $record): array
    {
        return MedicalRecord::query()
            ->where('patient_id', $patient->id)
            ->when($record, fn ($q) => $q->where('id', '!=', $record->id))
            ->with('doctor.person')
            ->orderByDesc('created_at')
            ->limit(6)
            ->get()
            ->map(fn (MedicalRecord $mr) => [
                'id'                   => (string) $mr->id,
                'code'                 => $mr->code,
                'created_at_formatted' => $mr->created_at?->format('d/m/Y'),
                'created_at_time'      => $mr->created_at?->format('H:i'),
                'doctor_name'          => $mr->doctor?->person?->full_name ?? '—',
                'main_complaint'       => Str::limit((string) $mr->main_complaint, 90) ?: null,
                'diagnosis_count'      => is_array($mr->diagnosis_cids) ? count($mr->diagnosis_cids) : 0,
                'is_signed'            => $mr->isSigned(),
                'show_url'             => route('panel.patients.medicalrecords.show', [$patient, $mr]),
            ])
            ->all();
    }

    private function buildFormProps(Patient $patient, ?MedicalRecord $record): array
    {
        $entityId = (string) session('selected_entity_id');
        $entity   = Entity::find($entityId);
        $isEdit   = (bool) $record;

        $doctors = Doctor::with(['person', 'entityUser'])
            ->whereHas('entityUser', fn ($q) => $q->where('entity_id', $entityId))
            ->get();

        $currentDoctor = $doctors->first(
            fn (Doctor $d) => $d->entityUser?->user_id === auth()->id(),
        );

        $canChooseDoctor = $entity && auth()->user()?->hasRoleInEntity($entity, ClientRule::Admin);
        $isDoctor        = session('selected_entity_user_rule') === ClientRule::Doctor->value;

        return [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('actions.sidemenu.patients'), 'url' => route('panel.patients.index'), 'active' => false],
                ['label' => $patient->person?->full_name ?? $patient->code, 'url' => '#', 'active' => false],
                ['label' => __('actions.medical_records.title'), 'url' => route('panel.patients.medicalrecords.index', $patient), 'active' => false],
            ],
            'patient'         => $this->serializePatient($patient),
            'medicalrecord'   => $record ? $this->serializeRecord($record) : null,
            'previousRecords' => $this->serializePreviousRecords($patient, $record),
            'doctors'         => $this->serializeCatalog($doctors, fn (Doctor $d) => [
                'id'   => (string) $d->id,
                'name' => $d->person?->full_name,
            ]),
            'currentDoctorId' => $currentDoctor ? (string) $currentDoctor->id : null,
            'canChooseDoctor' => (bool) $canChooseDoctor,
            'isDoctor'        => $isDoctor,
            'isEdit'          => $isEdit,
            'catalogs'        => [
                'visual_acuity_types' => $this->serializeCatalog(VisualAcuityType::orderBy('scale')->get()),
                'color_vision_types'  => $this->serializeCatalog(ColorVisionType::orderBy('name')->get()),
                'cover_test_types'    => $this->serializeCatalog(CoverTestType::orderBy('name')->get()),
                'near_point_types'    => $this->serializeCatalog(NearPointConvergence::orderBy('name')->get()),
                'addition_types'      => $this->serializeCatalog(AdditionType::orderBy('name')->get()),
                'lenses'              => $this->serializeCatalog(Lense::orderBy('name')->get()),
                'documentation_types' => $this->documentationService->getTypes(),
                'available_templates' => $this->documentationService->getActiveTemplates($entityId),
                'exam_reports'        => array_map(
                    fn (ExamReportRegistry $exam) => [
                        'value'    => $exam->value,
                        'label'    => $exam->label(),
                        'icon'     => $exam->icon(),
                        'subtypes' => $exam->subtypes(),
                    ],
                    ExamReportRegistry::examsForHub(),
                ),
            ],
            'urls'    => $this->buildFormUrls($patient, $record, $isEdit),
            'storage' => $this->buildStorageQuota($entityId),
            'ai'      => $this->buildAiProps($entityId),
            't'       => trans('actions.medical_records'),
        ];
    }

    /**
     * Props do Assistente de IA no prontuário (análise do caso, rascunho de laudo,
     * apoio em campos). Reaproveita os feature gates e o AiRunsController. Retorna
     * apenas { enabled:false } quando a entity não tem nenhum recurso de IA.
     */
    private function buildAiProps(string $entityId): array
    {
        $hasExamAssistant  = $this->featureGate->can($entityId, FeatureKey::HasAiExamAssistant);
        $hasReportDrafting = $this->featureGate->can($entityId, FeatureKey::HasAiReportDrafting);
        $canConsensus      = $this->aiProviderSettings->isModeAvailable(AiRunMode::Consensus)
            && $this->featureGate->can($entityId, FeatureKey::HasAiConsensus);

        if (! $hasExamAssistant && ! $hasReportDrafting && ! $canConsensus) {
            return ['enabled' => false];
        }

        $modes = [];

        foreach ($this->aiProviderSettings->availableModes() as $mode) {
            if ($mode === AiRunMode::Consensus && ! $canConsensus) {
                continue;
            }
            $modes[] = ['value' => $mode->value, 'label' => __('ai.mode_' . $mode->value)];
        }

        $workflows = array_values(array_filter([
            $hasExamAssistant ? 'record_assist' : null,    // Análise do caso + sugestões
            $hasReportDrafting ? 'report_drafting' : null, // Rascunho de laudo
        ]));

        return [
            'enabled'          => true,
            'can_analysis'     => $hasExamAssistant,   // Análise do caso + apoio inline
            'can_report'       => $hasReportDrafting,   // Rascunho de laudo
            'can_consensus'    => $canConsensus,
            'default_mode'     => AiRunMode::Economy->value,
            'default_workflow' => $workflows[0] ?? 'record_assist',
            'workflows'        => $workflows,
            'modes'            => $modes,
            'balance'          => $this->aiWallet->balance($entityId),
            'quota'            => $this->aiQuotaService->currentMonthSnapshot($entityId),
            'urls'             => [
                'estimate'   => route('panel.ai-runs.estimate'),
                'store'      => route('panel.ai-runs.store'),
                'show'       => route('panel.ai-runs.show', ['aiRun' => '__ID__']),
                'approve'    => route('panel.ai-runs.approve', ['aiRun' => '__ID__']),
                'reject'     => route('panel.ai-runs.reject', ['aiRun' => '__ID__']),
                'cancel'     => route('panel.ai-runs.cancel', ['aiRun' => '__ID__']),
                'by_patient' => route('panel.ai-runs.by-patient', ['patient' => '__ID__']),
                // Onda 3 — endpoints inline
                'my_prompts_index'   => route('panel.ai-runs.my-prompts.index'),
                'my_prompts_store'   => route('panel.ai-runs.my-prompts.store'),
                'my_prompts_destroy' => route('panel.ai-runs.my-prompts.destroy', ['aiPrompt' => '__ID__']),
                'escalate'           => route('panel.ai-runs.escalate', ['aiRun' => '__ID__']),
                'feedback'           => route('panel.ai-runs.feedback', ['aiRun' => '__ID__']),
            ],
            // Rótulos do painel compartilhado (AiAssistantPanel).
            'assistant'       => trans('ai.assistant'),
            'workflow_labels' => [
                'record_assist'      => __('ai.workflow_record_assist'),
                'report_drafting'    => __('ai.workflow_report_drafting'),
                'eye_image_analysis' => __('ai.workflow_eye_image_analysis'),
            ],
        ];
    }

    /**
     * Payload de cota de armazenamento para o modal de upload de anexos.
     * Espelha as restrições do backend ({@see MedicalRecordFilesController::store})
     * para que a UI valide antes de enviar e mostre o consumo atual.
     */
    private function buildStorageQuota(string $entityId): array
    {
        $status     = $this->featureGate->status($entityId, FeatureKey::MaxStorageGB);
        $usedBytes  = $this->usageMeter->getStorageUsedBytes($entityId);
        $limitBytes = $status->isUnlimited ? 0 : $status->limit * 1024 * 1024 * 1024;
        $percent    = $status->isUnlimited || $limitBytes === 0
            ? 0
            : min(100, (int) round(($usedBytes / $limitBytes) * 100));

        return [
            'used_bytes'      => $usedBytes,
            'limit_bytes'     => $limitBytes,
            'limit_gb'        => $status->limit,
            'is_unlimited'    => $status->isUnlimited,
            'percent'         => $percent,
            'remaining_bytes' => $status->isUnlimited ? null : max(0, $limitBytes - $usedBytes),
            // Espelha validações do backend (MedicalRecordFilesController::store)
            'max_file_size_bytes' => 10 * 1024 * 1024,
            'max_files_per_batch' => 10,
            'accept'              => '.jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx',
            'accept_mimes'        => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx'],
        ];
    }

    /**
     * URLs consumidas pelo formulário Vue. Em CREATE muitas URLs ainda não
     * existem (depende de UUID do registro) — só são populadas após edit.
     */
    private function buildFormUrls(Patient $patient, ?MedicalRecord $record, bool $isEdit): array
    {
        $urls = [
            'store'             => route('panel.patients.medicalrecords.store', $patient),
            'list'              => route('panel.patients.medicalrecords.index', $patient),
            'create'            => route('panel.patients.medicalrecords.create', $patient),
            'validation_rules'  => route('panel.medical-records.validation-rules', ['mode' => $isEdit ? 'update' : 'store']),
            'calc_presbyopia'   => route('panel.patients.medicalrecords.calculate-presbyopia', $patient),
            'lens_format'       => route('panel.medicalrecords.lens-format'),
            'tonometry_pdf'     => route('panel.patients.tonometry-pdf', $patient),
            'medicine_search'   => route('panel.medicines.search'),
            'medication_format' => route('panel.medication-prescription.format-line'),
            'procedure_search'  => route('panel.procedures.search'),
            'indication_search' => route('panel.indications.search'),
            'procedure_format'  => route('panel.procedure-solicitation.format-line'),
            'cid10_search'      => route('panel.cid10.search'),
            // Histórico de evoluções é por PACIENTE (atravessa prontuários) —
            // disponível também no create, antes de salvar o prontuário atual.
            'evolutions_index' => route('panel.patients.evolutions.index', $patient),
        ];

        if ($isEdit && $record) {
            $urls['update']                = route('panel.patients.medicalrecords.update', [$patient, $record]);
            $urls['destroy']               = route('panel.patients.medicalrecords.destroy', [$patient, $record]);
            $urls['pdf']                   = route('panel.patients.medicalrecords.pdf', [$patient, $record]);
            $urls['templates']             = route('panel.patients.medicalrecords.templates', [$patient, $record]);
            $urls['template_preview']      = route('panel.patients.medicalrecords.template-preview', [$patient, $record]);
            $urls['store_doc']             = route('panel.patients.medicalrecords.documentations.store', [$patient, $record]);
            $urls['store_file']            = route('panel.patients.medicalrecords.files.store', [$patient, $record]);
            $urls['store_tonometry']       = route('panel.patients.medicalrecords.tonometry.store', [$patient, $record]);
            $urls['quick_action_template'] = route(
                'panel.patients.medicalrecords.quick-actions.issue',
                [$patient, $record, '__ACTION__'],
            );
            $urls['evolutions_store'] = route(
                'panel.patients.medicalrecords.evolutions.store',
                [$patient, $record],
            );
            $urls['exam_template_template'] = route(
                'panel.patients.medicalrecords.exam-template',
                [$patient, $record, '__EXAM__'],
            );
            $urls['day_extension_preview'] = route('panel.medical-records.day-extension-preview');
        }

        return $urls;
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param Collection<int,T> $collection
     */
    private function serializeCatalog($collection, ?callable $mapper = null): array
    {
        $mapper ??= fn ($item) => [
            'id'   => (string) $item->id,
            'name' => $item->name,
        ];

        return $collection->map($mapper)->values()->all();
    }

    private function serializePatient(Patient $patient): array
    {
        return [
            'id'            => (string) $patient->id,
            'code'          => $patient->code,
            'full_name'     => $patient->person?->full_name,
            'birth_date'    => $patient->person?->birth_date?->format('d/m/Y'),
            'age'           => $patient->person?->birth_date?->age,
            'gender'        => $patient->person?->gender,
            'cpf'           => $patient->person?->cpf,
            'phone'         => $patient->person?->cellphone ?? $patient->person?->telephone,
            'email'         => $patient->person?->email,
            'covenant_name' => $patient->covenant?->name,
            'skin_type'     => $patient->skinType?->name,
            'iris_type'     => $patient->irisType?->name,
        ];
    }

    /**
     * Serializa o MedicalRecord para o frontend Vue. Inclui todos os campos
     * editáveis + flags de compliance (locked/signed) + documentações.
     */
    private function serializeRecord(MedicalRecord $r): array
    {
        return [
            // Identificação
            'id'          => (string) $r->id,
            'code'        => $r->code,
            'doctor_id'   => $r->doctor_id ? (string) $r->doctor_id : null,
            'doctor_name' => $r->doctor?->person?->full_name,

            // Anamnese
            'main_complaint'          => $r->main_complaint,
            'hda'                     => $r->hda,
            'diabetic'                => (bool) $r->diabetic,
            'diabetic_family'         => (bool) $r->diabetic_family,
            'hypertensive'            => (bool) $r->hypertensive,
            'hypertensive_family'     => (bool) $r->hypertensive_family,
            'glaucomatous'            => (bool) $r->glaucomatous,
            'glaucomatous_family'     => (bool) $r->glaucomatous_family,
            'others_history'          => $r->others_history,
            'ocular_surgical_history' => $r->ocular_surgical_history,
            'medications_in_use'      => $r->medications_in_use,

            // Acuidade visual e refração
            'visual_acuity_type_id'                     => $r->visual_acuity_type_id ? (string) $r->visual_acuity_type_id : null,
            'visual_acuity_without_correction_right_id' => $r->visual_acuity_without_correction_right_id ? (string) $r->visual_acuity_without_correction_right_id : null,
            'visual_acuity_without_correction_left_id'  => $r->visual_acuity_without_correction_left_id ? (string) $r->visual_acuity_without_correction_left_id : null,
            'visual_acuity_with_correction_right_id'    => $r->visual_acuity_with_correction_right_id ? (string) $r->visual_acuity_with_correction_right_id : null,
            'visual_acuity_with_correction_left_id'     => $r->visual_acuity_with_correction_left_id ? (string) $r->visual_acuity_with_correction_left_id : null,
            'near_point_convergence_id'                 => $r->near_point_convergence_id ? (string) $r->near_point_convergence_id : null,
            'cover_test_type_id'                        => $r->cover_test_type_id ? (string) $r->cover_test_type_id : null,
            'color_vision_type_id'                      => $r->color_vision_type_id ? (string) $r->color_vision_type_id : null,
            'addition_type_id'                          => $r->addition_type_id ? (string) $r->addition_type_id : null,
            'lens_away_id'                              => $r->lens_away_id ? (string) $r->lens_away_id : null,
            'lens_near_id'                              => $r->lens_near_id ? (string) $r->lens_near_id : null,

            // Refração dinâmica
            'dynamic_spherical_right'   => $r->dynamic_spherical_right,
            'dynamic_spherical_left'    => $r->dynamic_spherical_left,
            'dynamic_cylindrical_right' => $r->dynamic_cylindrical_right,
            'dynamic_cylindrical_left'  => $r->dynamic_cylindrical_left,
            'dynamic_axis_right'        => $r->dynamic_axis_right,
            'dynamic_axis_left'         => $r->dynamic_axis_left,

            // Refração estática
            'static_spherical_right'   => $r->static_spherical_right,
            'static_spherical_left'    => $r->static_spherical_left,
            'static_cylindrical_right' => $r->static_cylindrical_right,
            'static_cylindrical_left'  => $r->static_cylindrical_left,
            'static_axis_right'        => $r->static_axis_right,
            'static_axis_left'         => $r->static_axis_left,

            // Exame físico
            'ocular_motility'  => $r->ocular_motility,
            'tonometer_right'  => $r->tonometer_right,
            'tonometer_left'   => $r->tonometer_left,
            'tonometer_time'   => $r->tonometer_time,
            'pachymetry_right' => $r->pachymetry_right,
            'pachymetry_left'  => $r->pachymetry_left,
            'gonioscopy_right' => $r->gonioscopy_right,
            'gonioscopy_left'  => $r->gonioscopy_left,

            // Achados
            'biomicroscopy_right'   => $r->biomicroscopy_right,
            'biomicroscopy_left'    => $r->biomicroscopy_left,
            'fundoscopy_right'      => $r->fundoscopy_right,
            'fundoscopy_left'       => $r->fundoscopy_left,
            'observation_general'   => $r->observation_general,
            'observation_of_lenses' => $r->observation_of_lenses,

            // Diagnóstico & conduta
            'diagnosis_cids'   => $r->diagnosis_cids ?? [],
            'clinical_conduct' => $r->clinical_conduct,
            'follow_up_days'   => $r->follow_up_days,

            // Compliance CFM
            'is_signed'           => $r->isSigned(),
            'is_locked'           => $r->isLocked(),
            'signed_at_formatted' => $r->signed_at?->format('d/m/Y H:i'),

            // Documentações já anexadas
            'documentations' => $r->documentations->map(fn ($d) => [
                'id'                => (string) $d->id,
                'type'              => $d->type,
                'type_label'        => $d->getTypeLabel(),
                'title'             => $d->title,
                'doctor_name'       => $d->doctor?->person?->full_name ?? '',
                'created_at'        => $d->created_at?->format('d/m/Y H:i'),
                'is_ai'             => $d->ai_run_id !== null,
                'ai_workflow_label' => $d->ai_run_id && $d->aiRun ? __('ai.workflow_' . $d->aiRun->workflow) : null,
                'pdf_url'           => route(
                    'panel.patients.medicalrecords.documentations.pdf',
                    [$r->patient_id, $r, $d],
                ),
            ])->all(),

            // Anexos já enviados — consumido pela lista no rodapé do form
            'files' => $r->files->map(fn ($f) => [
                'id'            => (string) $f->id,
                'original_name' => $f->original_name,
                'mime_type'     => $f->mime_type,
                'file_size'     => $f->file_size,
                'is_image'      => $f->isImage(),
                'show_url'      => route(
                    'panel.patients.medicalrecords.files.show',
                    [$r->patient_id, $r, $f],
                ),
            ])->all(),
        ];
    }

    private function assertMedicalRecordBelongsToPatient(Patient $patient, MedicalRecord $medicalrecord): void
    {
        abort_if($medicalrecord->patient_id !== $patient->id, 404);
    }

    /**
     * Redireciona após save:
     *   - Se schedule_id presente, volta à agenda.
     *   - Se record recém-criado, redireciona para EDIT (sem ação pós-save).
     *   - Caso contrário, listagem padrão.
     */
    private function redirectAfterSave(
        Patient $patient,
        array $validated,
        string $message,
        ?MedicalRecord $record = null,
        ?string $postSaveAction = null,
    ): RedirectResponse {
        if (! empty($validated['schedule_id'])) {
            return redirect()
                ->route('panel.schedules.index')
                ->with('message', $message);
        }

        if ($record && $record->wasRecentlyCreated) {
            return redirect()
                ->route('panel.patients.medicalrecords.edit', [$patient, $record])
                ->with('message', $message);
        }

        return redirect()
            ->route('panel.patients.medicalrecords.index', $patient)
            ->with('message', $message);
    }

    private function assertTemplateBelongsToCurrentEntity(ReportSettingContent $content): void
    {
        $selectedEntityId = (string) session('selected_entity_id');
        $content->loadMissing('reportSetting');

        $settingEntityId = (string) ($content->reportSetting?->entity_id ?? '');
        abort_if($settingEntityId !== '' && $settingEntityId !== $selectedEntityId, 404);
    }
}
