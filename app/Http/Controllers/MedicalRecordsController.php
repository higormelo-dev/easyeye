<?php

namespace App\Http\Controllers;

use App\Enums\{ClientRule, DataAccessPurpose, ExamReportRegistry};
use App\Exceptions\LockedMedicalRecordException;
use App\Http\Requests\{StoreMedicalRecordRequest, UpdateMedicalRecordRequest};
use App\Models\{AdditionType, ColorVisionType, CoverTestType, Doctor, Entity, Lense,
    MedicalRecord, NearPointConvergence, Patient, VisualAcuityType};
use App\Models\ReportSettingContent;
use App\Services\{LensFormatterService, MedicalRecordDocumentationService, MedicalRecordPdfService, MedicalRecordService};
use App\Traits\LogsDataAccess;
use Illuminate\Contracts\View\{Factory, View};
use Illuminate\Foundation\Application;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request, Response};

class MedicalRecordsController extends Controller
{
    use LogsDataAccess;

    public function __construct(
        private readonly MedicalRecordService $service,
        private readonly MedicalRecordDocumentationService $documentationService,
        private readonly MedicalRecordPdfService $pdfService,
        private readonly LensFormatterService $lensFormatter,
    ) {
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Patient $patient): Factory|Application|View
    {
        $meta = [
            'title'       => __('actions.sidemenu.medical_records'),
            'action'      => __('actions.records'),
            'breadcrumbs' => [
                [
                    'label'  => __('actions.sidemenu.dashboard'),
                    'url'    => route('panel.dashboard'),
                    'active' => false,
                ],
                [
                    'label'  => __('actions.sidemenu.patients'),
                    'url'    => route('panel.patients.index'),
                    'active' => false,
                ],
                [
                    'label'  => $patient->person->full_name ?? $patient->code,
                    'url'    => 'javascript:void(0);',
                    'active' => false,
                ],
                [
                    'label'  => __('actions.medical_records.title'),
                    'url'    => 'javascript:void(0);',
                    'active' => true,
                ],
            ],
        ];

        $patient->load(['person', 'covenant', 'skinType', 'irisType']);

        return view('system.medical_records.index', compact('meta', 'patient'));
    }

    /**
     * Return paginated JSON for the timeline infinite scroll.
     */
    public function ajaxList(Request $request, Patient $patient): JsonResponse
    {
        // CFM Res. 2.227/2018 + LGPD Art. 37 — registra acesso à lista de prontuários do paciente
        $this->logAccess($patient, DataAccessPurpose::PatientCare);

        $records = MedicalRecord::with(['doctor.person', 'doctor.entityUser', 'documentations'])
            ->where('patient_id', $patient->id)
            ->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 10));

        $html = view('system.medical_records._items', compact('records', 'patient'))->render();

        return response()->json([
            'html'      => $html,
            'has_more'  => $records->hasMorePages(),
            'next_page' => $records->currentPage() + 1,
            'total'     => $records->total(),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Patient $patient): Factory|Application|View
    {
        $patient->load(['person', 'covenant', 'skinType', 'irisType']);
        [$commonData, $meta] = $this->commonFormData($patient);

        $meta['title']         = __('actions.medical_records.create');
        $meta['breadcrumbs'][] = ['label' => __('actions.medical_records.create'), 'url' => 'javascript:void(0);', 'active' => true];

        return view('system.medical_records.create', array_merge(
            compact('meta', 'patient'),
            $commonData,
        ));
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
                'id'          => $d->id,
                'type'        => $d->type,
                'type_label'  => $d->getTypeLabel(),
                'title'       => $d->title,
                'doctor_name' => $d->doctor?->person?->full_name ?? '',
                'created_at'  => $d->created_at?->format('d/m/Y H:i'),
                'pdf_url'     => route('panel.patients.medicalrecords.documentations.pdf', [$patient, $medicalrecord, $d]),
            ]),
            // URLs de ação
            'edit_url'      => route('panel.patients.medicalrecords.edit', [$patient, $medicalrecord]),
            'pdf_url'       => route('panel.patients.medicalrecords.pdf', [$patient, $medicalrecord]),
            'templates_url' => route('panel.patients.medicalrecords.templates', [$patient, $medicalrecord]),
            // Labels i18n consumidos pelo `buildDetailHtml` no offcanvas.
            'labels'        => [
                'yes'           => __('actions.medical_records.yes'),
                'no'            => __('actions.medical_records.no'),
                'not_informed'  => __('actions.medical_records.not_informed'),
                'complaint'     => __('actions.medical_records.complaint'),
                'history'       => __('actions.medical_records.history'),
                'diabetic'      => __('actions.medical_records.diabetic'),
                'hypertensive'  => __('actions.medical_records.hypertensive'),
                'glaucomatous'  => __('actions.medical_records.glaucomatous'),
                'family'        => __('actions.medical_records.family'),
                'tonometry'     => __('actions.medical_records.tonometry'),
                'general_obs'   => __('actions.medical_records.general_obs'),
                'lenses_obs'    => __('actions.medical_records.lenses_obs'),
            ],
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Patient $patient, MedicalRecord $medicalrecord): Factory|Application|View
    {
        $this->assertMedicalRecordBelongsToPatient($patient, $medicalrecord);
        $this->logAccess($medicalrecord, DataAccessPurpose::PatientCare, patientId: $patient->id);

        $patient->load(['person', 'covenant', 'skinType', 'irisType']);
        $medicalrecord->load(['doctor', 'documentations.reportSettingContent']);

        [$commonData, $meta] = $this->commonFormData($patient);

        $meta['title']         = __('actions.medical_records.edit');
        $meta['breadcrumbs'][] = ['label' => __('actions.medical_records.edit'), 'url' => 'javascript:void(0);', 'active' => true];

        return view('system.medical_records.edit', array_merge(
            compact('meta', 'patient', 'medicalrecord'),
            $commonData,
        ));
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

        return response()->json(['content' => $html, 'unresolved' => $resolved['unresolved']]);
    }

    /**
     * Shared form data for create/edit views.
     *
     * @return array{0: array, 1: array}
     */
    private function commonFormData(Patient $patient): array
    {
        $entityId = session('selected_entity_id');
        $entity   = Entity::find($entityId);

        $doctors = Doctor::with(['person', 'entityUser'])
            ->whereHas('entityUser', fn ($q) => $q->where('entity_id', $entityId))
            ->get();

        $currentDoctor = $doctors->first(
            fn (Doctor $d) => $d->entityUser?->user_id === auth()->id(),
        );

        $commonData = [
            'doctors'            => $doctors,
            'currentDoctor'      => $currentDoctor,
            'canChooseDoctor'    => $entity && auth()->user()?->hasRoleInEntity($entity, ClientRule::Admin),
            'visualAcuityTypes'  => VisualAcuityType::orderBy('name')->get(),
            'colorVisionTypes'   => ColorVisionType::orderBy('name')->get(),
            'coverTestTypes'     => CoverTestType::orderBy('name')->get(),
            'nearPointTypes'     => NearPointConvergence::orderBy('name')->get(),
            'additionTypes'      => AdditionType::orderBy('name')->get(),
            'lenses'             => Lense::orderBy('name')->get(),
            'documentationTypes' => $this->documentationService->getTypes(),
            'availableTemplates' => $this->documentationService->getActiveTemplates($entityId),
            'examReports'        => array_map(
                fn (ExamReportRegistry $exam) => [
                    'value'    => $exam->value,
                    'label'    => $exam->label(),
                    'icon'     => $exam->icon(),
                    'subtypes' => $exam->subtypes(),
                ],
                ExamReportRegistry::examsForHub(),
            ),
        ];

        $meta = [
            'title'       => __('actions.medical_records.title'),
            'action'      => __('actions.medical_records.title'),
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('actions.sidemenu.patients'), 'url' => route('panel.patients.index'), 'active' => false],
                ['label' => $patient->person->full_name ?? $patient->code, 'url' => 'javascript:void(0);', 'active' => false],
                ['label' => __('actions.medical_records.title'), 'url' => route('panel.patients.medicalrecords.index', $patient), 'active' => false],
            ],
        ];

        return [$commonData, $meta];
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
