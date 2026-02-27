<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PatientExamRequest;
use App\Http\Resources\PatientExamResource;
use App\Models\{PatientExam};
use App\Services\Api\PatientExamService;
use Illuminate\Http\{JsonResponse};
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class PatientExamsController extends Controller
{
    /**
     * Instance of the standard model.
     */
    protected PatientExam $model;

    protected PatientExamService $service;

    public function __construct(PatientExam $patientExam, PatientExamService $patientExamService)
    {
        $this->model   = $patientExam;
        $this->service = $patientExamService;
    }

    /**
     * Display a listing of the resource.
     */
    public function index(string $patientId)
    {
        $integrator   = request()->attributes->get('integrator');
		dd($integrator->user->entity_id);
        $patientExams = $this->model->query()
            ->with('patient', 'doctor', 'schedule', 'patient.person', 'doctor.person')
            ->whereHas('patient', function ($query) use ($integrator) {
                $query->where('entity_id', $integrator->user->entity_id);
            });

        if (request()->has('search')) {
            $search       = request()->search;
            $patientExams = $patientExams->where(function ($query) use ($search) {
                $query->whereHas('patient', function ($q) use ($search) {
                    $q->where('code', mb_convert_case($search, MB_CASE_LOWER, 'UTF-8'))
                        ->orWhereHas('person', function ($p) use ($search) {
                            $p->where('name', 'like', '%' . $search . '%')
                                ->orWhere('nickname', 'like', '%' . $search . '%');
                        });
                })
                    ->orWhereHas('doctor', function ($q) use ($search) {
                        $q->where('code', mb_convert_case($search, MB_CASE_LOWER, 'UTF-8'))
                            ->orWhereHas('person', function ($p) use ($search) {
                                $p->where('full_name', 'like', '%' . $search . '%')
                                    ->orWhere('name', 'like', '%' . $search . '%')
                                    ->orWhere('nickname', 'like', '%' . $search . '%');
                            });
                    })
                    ->orWhereHas('schedule', function ($q) use ($search) {
                        $q->where('code', mb_convert_case($search, MB_CASE_LOWER, 'UTF-8'));
                    });
            });
        }

        $patientExams = $patientExams->paginate(request()->get('per_page', 10));

        return PatientExamResource::collection($patientExams);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(PatientExamRequest $request, string $patientId): PatientExamResource|JsonResponse
    {
        $record = $this->service->create($request, $patientId);

        return new PatientExamResource($record);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $patientId, string $idOrCode): PatientExamResource|JsonResponse
    {
        $record = $this->service->findByIdOrCode($patientId, $idOrCode);

        return new PatientExamResource($record);
    }

    /**
     * Update the specified resource in storage.
     *
     * @throws \Throwable
     */
    public function update(PatientExamRequest $request, string $patientId, string $idOrCode): PatientExamResource|JsonResponse
    {
        $record = $this->service->findByIdOrCode($patientId, $idOrCode);

        $updatedRecord = $this->service->update($record, $request);

        return new PatientExamResource($updatedRecord);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $patientId, string $idOrCode): JsonResponse
    {
        $this->service->destroyByIdOrCode($patientId, $idOrCode);

        return response()->json([], HttpResponse::HTTP_NO_CONTENT);
    }
}
