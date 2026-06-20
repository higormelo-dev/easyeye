<?php

namespace App\Http\Controllers\Api;

use App\Enums\FeatureKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ExamRequest;
use App\Http\Resources\PatientExamResource;
use App\Models\PatientExam;
use App\Services\Api\PatientExamService;
use App\Services\FeatureGateService;
use Illuminate\Http\JsonResponse;
use Throwable;

class ExamsController extends Controller
{
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
     * Store a newly created resource in storage.
     */
    public function store(ExamRequest $request): PatientExamResource|JsonResponse
    {
        $integrator = request()->attributes->get('integrator');
        $entityId   = $integrator->user->entity_id;

        // Reserva atômica da cota ANTES de criar; reverte se a criação falhar.
        $this->featureGate->consumeOrFail($entityId, FeatureKey::ApiMonthlyExamSends);

        try {
            $record = $this->service->createFromScheduleIdentifier($request);
        } catch (Throwable $e) {
            $this->featureGate->decrement($entityId, FeatureKey::ApiMonthlyExamSends);

            throw $e;
        }

        return (new PatientExamResource($record))->response()->setStatusCode(201);
    }
}
