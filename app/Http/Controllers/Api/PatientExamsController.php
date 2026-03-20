<?php

namespace App\Http\Controllers\Api;

use App\Enums\FeatureKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PatientExamRequest;
use App\Http\Resources\PatientExamResource;
use App\Models\{Patient, PatientExam};
use App\Services\Api\PatientExamService;
use App\Services\FeatureGateService;
use Illuminate\Http\{JsonResponse};
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class PatientExamsController extends Controller
{
    /**
     * Instance of the standard model.
     */
    protected PatientExam $model;

    protected PatientExamService $service;

    public function __construct(
        PatientExam $patientExam,
        PatientExamService $patientExamService,
        private readonly FeatureGateService $featureGate,
    ) {
        $this->model   = $patientExam;
        $this->service = $patientExamService;
    }

    /**
     * Display a listing of the resource.
     */
    public function index(string $patientIdOrCode)
    {
        $integrator = request()->attributes->get('integrator');

        $patient = $this->resolvePatient($patientIdOrCode, $integrator->user->entity_id);

        $patientExams = $this->model->query()
            ->with('patient', 'doctor', 'schedule', 'patient.person', 'doctor.person')
            ->whereHas('patient', function ($query) use ($integrator, $patient) {
                $query->where('id', $patient->id)
                    ->where('entity_id', $integrator->user->entity_id);
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

        $patientExams = $patientExams->paginate(min((int) request()->get('per_page', 10), 10));

        return PatientExamResource::collection($patientExams);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(PatientExamRequest $request, string $patientId): PatientExamResource|JsonResponse
    {
        abort_unless(Str::isUuid($patientId), 404);

        $integrator = request()->attributes->get('integrator');
        $entityId   = $integrator->user->entity_id;

        $this->featureGate->canOrFail($entityId, FeatureKey::ApiMonthlyExamSends);

        $record = $this->service->create($request, $patientId);

        $this->featureGate->increment($entityId, FeatureKey::ApiMonthlyExamSends);

        return (new PatientExamResource($record))->response()->setStatusCode(201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $patientIdOrCode, string $idOrCode): PatientExamResource|JsonResponse
    {
        $entityId = request()->attributes->get('integrator')->user->entity_id;

        $patient = $this->resolvePatient($patientIdOrCode, $entityId);

        $record = $this->service->findByIdOrCode($patient->id, $idOrCode);

        return new PatientExamResource($record);
    }

    /**
     * Update the specified resource in storage.
     *
     * @throws \Throwable
     */
    public function update(PatientExamRequest $request, string $patientId, string $idOrCode): PatientExamResource|JsonResponse
    {
        abort_unless(Str::isUuid($patientId), 404);

        $integrator = request()->attributes->get('integrator');
        $entityId   = $integrator->user->entity_id;

        $this->featureGate->canOrFail($entityId, FeatureKey::ApiMonthlyExamSends);

        $record = $this->service->findByIdOrCode($patientId, $idOrCode);

        $updatedRecord = $this->service->update($record, $request);

        $this->featureGate->increment($entityId, FeatureKey::ApiMonthlyExamSends);

        return new PatientExamResource($updatedRecord);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $patientIdOrCode, string $idOrCode): JsonResponse
    {
        $entityId = request()->attributes->get('integrator')->user->entity_id;

        $patient = $this->resolvePatient($patientIdOrCode, $entityId);

        $this->service->destroyByIdOrCode($patient->id, $idOrCode);

        return response()->json([], HttpResponse::HTTP_NO_CONTENT);
    }

    private function resolvePatient(string $idOrCode, string $entityId): Patient
    {
        $query = Patient::where('entity_id', $entityId);

        if (Str::isUuid($idOrCode)) {
            $query->where('id', $idOrCode);
        } else {
            $query->where('code', $idOrCode);
        }

        return $query->firstOrFail();
    }
}
