<?php

namespace App\Http\Controllers;

use App\Models\{MedicalRecord, Patient};
use Illuminate\Contracts\View\{Factory, View};
use Illuminate\Foundation\Application;
use Illuminate\Http\{JsonResponse, Request};

class MedicalRecordsController extends Controller
{
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
                    'label'  => __('actions.medical_records'),
                    'url'    => 'javascript:void(0);',
                    'active' => true,
                ],
            ],
        ];

        return view('system.medical_records.index', compact('meta', 'patient'));
    }

    /**
     * Return paginated HTML partial for the timeline.
     */
    public function ajaxList(Request $request, Patient $patient): View
    {
        $records = MedicalRecord::with(['doctor.person', 'doctor.entityUser'])
            ->where('patient_id', $patient->id)
            ->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 10));

        return view('system.medical_records._list', compact('records', 'patient'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource as JSON for the detail offcanvas.
     */
    public function show(Patient $patient, MedicalRecord $medicalrecord): JsonResponse
    {
        $medicalrecord->load(['doctor.person']);

        return response()->json([
            'code'                => $medicalrecord->code,
            'created_at_formatted' => $medicalrecord->created_at?->format('d/m/Y H:i'),
            'doctor_name'         => $medicalrecord->doctor?->person?->full_name ?? 'Não informado',
            'main_complaint'      => $medicalrecord->main_complaint,
            'diabetic'            => $medicalrecord->diabetic,
            'diabetic_family'     => $medicalrecord->diabetic_family,
            'hypertensive'        => $medicalrecord->hypertensive,
            'hypertensive_family' => $medicalrecord->hypertensive_family,
            'glaucomatous'        => $medicalrecord->glaucomatous,
            'glaucomatous_family' => $medicalrecord->glaucomatous_family,
            'tonometer_right'     => $medicalrecord->tonometer_right,
            'tonometer_left'      => $medicalrecord->tonometer_left,
            'tonometer_time'      => $medicalrecord->tonometer_time,
            'observation_general' => $medicalrecord->observation_general,
            'observation_of_lenses' => $medicalrecord->observation_of_lenses,
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
