<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMedicalRecordRequest;
use App\Models\{AdditionType, ColorVisionType, CoverTestType, Doctor, Lense,
                MedicalRecord, NearPointConvergence, Patient, VisualAcuityType};
use Illuminate\Foundation\Application;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Inertia\Inertia;

class MedicalRecordsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Patient $patient): \Inertia\Response
    {
        $patient->load(['person', 'covenant', 'skinType', 'irisType']);

        return Inertia::render('MedicalRecords/Index', [
            'patient' => $patient,
        ]);
    }

    /**
     * Return paginated JSON for the timeline infinite scroll.
     */
    public function ajaxList(Request $request, Patient $patient): JsonResponse
    {
        $records = MedicalRecord::with(['doctor.person', 'doctor.entityUser'])
            ->where('patient_id', $patient->id)
            ->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 10));

        $items = $records->map(function ($record) {
            $userId    = $record->doctor?->entityUser?->user_id;
            $photoPath = 'system/images/users/' . $userId . '.jpg';
            $photoUrl  = asset($photoPath);
            if (!$userId || !file_exists(public_path($photoPath))) {
                $photoUrl = asset('system/images/team.png');
            }

            return [
                'id'                   => $record->id,
                'code'                 => $record->code,
                'created_at_formatted' => $record->created_at?->format('d/m/Y H:i') ?? '—',
                'doctor_name'          => $record->doctor?->person?->full_name ?? __('actions.medical_records.not_informed'),
                'doctor_color'         => $record->doctor?->color ?: '#6c757d',
                'doctor_photo'         => $photoUrl,
                'diabetic'             => (bool) $record->diabetic,
                'hypertensive'         => (bool) $record->hypertensive,
                'glaucomatous'         => (bool) $record->glaucomatous,
                'main_complaint'       => \Illuminate\Support\Str::limit(strip_tags((string)$record->main_complaint), 150),
                'tonometer_right'      => $record->tonometer_right,
                'tonometer_left'       => $record->tonometer_left,
            ];
        });

        return response()->json([
            'data'      => $items,
            'has_more'  => $records->hasMorePages(),
            'next_page' => $records->currentPage() + 1,
            'total'     => $records->total(),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Patient $patient): \Inertia\Response
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

        return Inertia::render('MedicalRecords/Create', compact(
            'patient', 'doctors',
            'visualAcuityTypes', 'colorVisionTypes', 'coverTestTypes',
            'nearPointTypes', 'additionTypes', 'lenses'
        ));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreMedicalRecordRequest $request, Patient $patient): RedirectResponse
    {
        MedicalRecord::create([
            'patient_id'                                => $patient->id,
            'doctor_id'                                 => $request->doctor_id ?: null,
            'visual_acuity_type_id'                     => $request->visual_acuity_type_id ?: null,
            'near_point_convergence_id'                 => $request->near_point_convergence_id ?: null,
            'cover_test_type_id'                        => $request->cover_test_type_id ?: null,
            'visual_acuity_without_correction_right_id' => $request->visual_acuity_without_correction_right_id ?: null,
            'visual_acuity_without_correction_left_id'  => $request->visual_acuity_without_correction_left_id ?: null,
            'visual_acuity_with_correction_right_id'    => $request->visual_acuity_with_correction_right_id ?: null,
            'visual_acuity_with_correction_left_id'     => $request->visual_acuity_with_correction_left_id ?: null,
            'addition_type_id'                          => $request->addition_type_id ?: null,
            'lens_away_id'                              => $request->lens_away_id ?: null,
            'lens_near_id'                              => $request->lens_near_id ?: null,
            'main_complaint'                            => $request->main_complaint,
            'diabetic'                                  => $request->boolean('diabetic'),
            'diabetic_family'                           => $request->boolean('diabetic_family'),
            'hypertensive'                              => $request->boolean('hypertensive'),
            'hypertensive_family'                       => $request->boolean('hypertensive_family'),
            'glaucomatous'                              => $request->boolean('glaucomatous'),
            'glaucomatous_family'                       => $request->boolean('glaucomatous_family'),
            'tonometer_right'                           => $request->tonometer_right ?: null,
            'tonometer_left'                            => $request->tonometer_left ?: null,
            'tonometer_time'                            => $request->tonometer_time ?: null,
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
            'static_axis_left'                          => $request->static_axis_left ?: null,
            'biomicroscopy_right'                       => $request->biomicroscopy_right,
            'biomicroscopy_left'                        => $request->biomicroscopy_left,
            'fundoscopy_right'                          => $request->fundoscopy_right,
            'fundoscopy_left'                           => $request->fundoscopy_left,
            'observation_general'                       => $request->observation_general,
            'observation_of_lenses'                     => $request->observation_of_lenses,
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
        $medicalrecord->load(['doctor.person']);

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
