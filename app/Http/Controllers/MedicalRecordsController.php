<?php

namespace App\Http\Controllers;

use App\Enums\{DataAccessPurpose, EntityGate};
use App\Http\Requests\StoreMedicalRecordRequest;
use App\Models\{AdditionType, ColorVisionType, CoverTestType, Doctor, Entity, Lense,
                MedicalRecord, NearPointConvergence, Patient, VisualAcuityType};
use App\Traits\LogsDataAccess;
use Illuminate\Support\Facades\Gate;
use Illuminate\Contracts\View\{Factory, View};
use Illuminate\Foundation\Application;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};

class MedicalRecordsController extends Controller
{
    use LogsDataAccess;
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

        $records = MedicalRecord::with(['doctor.person', 'doctor.entityUser'])
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
        $entityId = session('selected_entity_id');

        $doctors           = Doctor::query()
            ->join('entity_users', 'doctors.entity_user_id', '=', 'entity_users.id')
            ->where('entity_users.entity_id', $entityId)
            ->join('people', 'doctors.person_id', '=', 'people.id')
            ->select('doctors.*')
            ->with('person')
            ->orderBy('people.full_name')
            ->get();
        $visualAcuityTypes = VisualAcuityType::where('entity_id', $entityId)->where('active', true)->orderBy('name')->get();
        $colorVisionTypes  = ColorVisionType::where('entity_id', $entityId)->where('active', true)->orderBy('name')->get();
        $coverTestTypes    = CoverTestType::where('entity_id', $entityId)->where('active', true)->orderBy('name')->get();
        $nearPointTypes    = NearPointConvergence::where('entity_id', $entityId)->where('active', true)->orderBy('name')->get();
        $additionTypes     = AdditionType::where('entity_id', $entityId)->where('active', true)->orderBy('name')->get();
        $lenses            = Lense::where('entity_id', $entityId)->where('active', true)->orderBy('name')->get();

        $meta = [
            'title'       => __('actions.medical_records.create'),
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'),                                     'active' => false],
                ['label' => __('actions.sidemenu.patients'),  'url' => route('panel.patients.index'),                                'active' => false],
                ['label' => $patient->person->full_name ?? $patient->code, 'url' => route('panel.patients.medicalrecords.index', $patient), 'active' => false],
                ['label' => __('actions.medical_records.create'), 'url' => 'javascript:void(0);',                                   'active' => true],
            ],
        ];

        return view('system.medical_records.create', compact(
            'meta', 'patient', 'doctors',
            'visualAcuityTypes', 'colorVisionTypes', 'coverTestTypes',
            'nearPointTypes', 'additionTypes', 'lenses'
        ));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreMedicalRecordRequest $request, Patient $patient): RedirectResponse
    {
        Gate::authorize(EntityGate::IssueReport->value, Entity::findOrFail(session('selected_entity_id')));

        MedicalRecord::create([
            'patient_id'                                => $patient->id,
            'doctor_id'                                 => $request->doctor_id ?: null,
            // Exame físico — seleções
            'visual_acuity_type_id'                     => $request->visual_acuity_type_id ?: null,
            'near_point_convergence_id'                 => $request->near_point_convergence_id ?: null,
            'cover_test_type_id'                        => $request->cover_test_type_id ?: null,
            'color_vision_type_id'                      => $request->color_vision_type_id ?: null,
            'visual_acuity_without_correction_right_id' => $request->visual_acuity_without_correction_right_id ?: null,
            'visual_acuity_without_correction_left_id'  => $request->visual_acuity_without_correction_left_id ?: null,
            'visual_acuity_with_correction_right_id'    => $request->visual_acuity_with_correction_right_id ?: null,
            'visual_acuity_with_correction_left_id'     => $request->visual_acuity_with_correction_left_id ?: null,
            'addition_type_id'                          => $request->addition_type_id ?: null,
            'lens_away_id'                              => $request->lens_away_id ?: null,
            'lens_near_id'                              => $request->lens_near_id ?: null,
            // Anamnese — CBO
            'main_complaint'                            => $request->main_complaint,
            'hda'                                       => $request->hda,
            'diabetic'                                  => $request->boolean('diabetic'),
            'diabetic_family'                           => $request->boolean('diabetic_family'),
            'hypertensive'                              => $request->boolean('hypertensive'),
            'hypertensive_family'                       => $request->boolean('hypertensive_family'),
            'glaucomatous'                              => $request->boolean('glaucomatous'),
            'glaucomatous_family'                       => $request->boolean('glaucomatous_family'),
            'ocular_surgical_history'                   => $request->ocular_surgical_history,
            'medications_in_use'                        => $request->medications_in_use,
            // Exame físico — texto
            'ocular_motility'                           => $request->ocular_motility,
            'tonometer_right'                           => $request->tonometer_right ?: null,
            'tonometer_left'                            => $request->tonometer_left ?: null,
            'tonometer_time'                            => $request->tonometer_time ?: null,
            'pachymetry_right'                          => $request->pachymetry_right ?: null,
            'pachymetry_left'                           => $request->pachymetry_left ?: null,
            'gonioscopy_right'                          => $request->gonioscopy_right,
            'gonioscopy_left'                           => $request->gonioscopy_left,
            'dynamic_spherical_right'                   => $request->dynamic_spherical_right ?: null,
            'dynamic_spherical_left'                    => $request->dynamic_spherical_left ?: null,
            'dynamic_cylindrical_right'                 => $request->dynamic_cylindrical_right ?: null,
            'dynamic_cylindrical_left'                  => $request->dynamic_cylindrical_left ?: null,
            'dynamic_axis_right'                        => $request->dynamic_axis_right ?: null,
            'dynamic_axis_left'                         => $request->dynamic_axis_left ?: null,
            'static_spherical_right'                    => $request->static_spherical_right ?: null,
            'static_spherical_left'                     => $request->static_spherical_left ?: null,
            'static_cylindrical_right'                  => $request->static_cylindrical_right ?: null,
            'static_cylindrical_left'                   => $request->static_cylindrical_left ?: null,
            'static_axis_right'                         => $request->static_axis_right ?: null,
            'static_axis_left'                          => $request->static_axis_left ?: null,
            'biomicroscopy_right'                       => $request->biomicroscopy_right,
            'biomicroscopy_left'                        => $request->biomicroscopy_left,
            'fundoscopy_right'                          => $request->fundoscopy_right,
            'fundoscopy_left'                           => $request->fundoscopy_left,
            'observation_general'                       => $request->observation_general,
            'observation_of_lenses'                     => $request->observation_of_lenses,
            // Diagnóstico — CBO
            'diagnosis_cid10'                           => $request->diagnosis_cid10 ?: null,
            'diagnosis_description'                     => $request->diagnosis_description,
            // Conduta — CBO
            'clinical_conduct'                          => $request->clinical_conduct,
            'follow_up_days'                            => $request->follow_up_days ?: null,
        ]);

        return redirect()
            ->route('panel.patients.medicalrecords.index', $patient)
            ->with('message', __('actions.medical_records.saved'));
    }

    /**
     * Display the specified resource as JSON for the detail offcanvas.
     */
    public function show(Patient $patient, MedicalRecord $medicalrecord): JsonResponse
    {
        // CFM Res. 2.227/2018 + LGPD Art. 37 — registra acesso ao prontuário individual
        $this->logAccess($medicalrecord, DataAccessPurpose::PatientCare, patientId: $patient->id);

        $medicalrecord->load(['doctor.person', 'signedBy.user']);

        return response()->json([
            'code'                  => $medicalrecord->code,
            'created_at_formatted'  => $medicalrecord->created_at?->format('d/m/Y H:i'),
            'doctor_name'           => $medicalrecord->doctor?->person?->full_name ?? __('actions.medical_records.not_informed'),
            'main_complaint'        => $medicalrecord->main_complaint,
            'diabetic'              => $medicalrecord->diabetic,
            'diabetic_family'       => $medicalrecord->diabetic_family,
            'hypertensive'          => $medicalrecord->hypertensive,
            'hypertensive_family'   => $medicalrecord->hypertensive_family,
            'glaucomatous'          => $medicalrecord->glaucomatous,
            'glaucomatous_family'   => $medicalrecord->glaucomatous_family,
            'tonometer_right'       => $medicalrecord->tonometer_right,
            'tonometer_left'        => $medicalrecord->tonometer_left,
            'tonometer_time'        => $medicalrecord->tonometer_time,
            'observation_general'   => $medicalrecord->observation_general,
            'observation_of_lenses' => $medicalrecord->observation_of_lenses,
            // CFM Res. 2.227/2018 — status de assinatura eletrônica
            'is_locked'             => $medicalrecord->isLocked(),
            'is_signed'             => $medicalrecord->isSigned(),
            'signed_at_formatted'   => $medicalrecord->signed_at?->format('d/m/Y H:i'),
            'signed_by_name'        => $medicalrecord->signedBy?->user?->name,
            'labels'                => [
                'complaint'      => __('actions.medical_records.complaint'),
                'history'        => __('actions.medical_records.history'),
                'diabetic'       => __('actions.medical_records.diabetic'),
                'hypertensive'   => __('actions.medical_records.hypertensive'),
                'glaucomatous'   => __('actions.medical_records.glaucomatous'),
                'tonometry'      => __('actions.medical_records.tonometry'),
                'general_obs'    => __('actions.medical_records.general_obs'),
                'lenses_obs'     => __('actions.medical_records.lenses_obs'),
                'family'         => __('actions.medical_records.family'),
                'not_informed'   => __('actions.medical_records.not_informed'),
                'yes'            => __('actions.medical_records.yes'),
                'no'             => __('actions.medical_records.no'),
            ],
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
