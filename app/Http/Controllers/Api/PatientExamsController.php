<?php

namespace App\Http\Controllers\Api;

use App\Enums\{DataAccessPurpose, FeatureKey};
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PatientExamRequest;
use App\Http\Resources\PatientExamResource;
use App\Models\{Patient, PatientExam};
use App\Services\Api\PatientExamService;
use App\Services\FeatureGateService;
use App\Traits\LogsDataAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

class PatientExamsController extends Controller
{
    use LogsDataAccess;

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

        // LGPD Art. 37 / CFM Res. 2.227/2018: registra o acesso a exames (dado
        // sensível de saúde) via API. Um log por listagem — não por exame.
        $this->logAccess($patient, DataAccessPurpose::ApiAccess, patientId: $patient->id);

        $patientExams = $this->model->query()
            ->with('patient', 'doctor', 'schedule', 'patient.person', 'doctor.person')
            ->whereHas('patient', function ($query) use ($integrator, $patient) {
                $query->where('id', $patient->id)
                    ->where('entity_id', $integrator->user->entity_id);
            });

        if (request()->has('search')) {
            // `ilike` (case-insensitive no PG) e apenas colunas que EXISTEM em people
            // (full_name/nickname). A antiga referência a `name` não errava: por ser
            // subquery correlacionada, o PG resolvia contra patient_exams.name — ou
            // seja, a busca por nome de pessoa comparava com o nome do exame.
            $search       = request()->search;
            $patientExams = $patientExams->where(function ($query) use ($search) {
                $query->whereHas('patient', function ($q) use ($search) {
                    $q->where('code', 'ilike', '%' . $search . '%')
                        ->orWhereHas('person', function ($p) use ($search) {
                            $p->where('full_name', 'ilike', '%' . $search . '%')
                                ->orWhere('nickname', 'ilike', '%' . $search . '%');
                        });
                })
                    ->orWhereHas('doctor', function ($q) use ($search) {
                        $q->where('code', 'ilike', '%' . $search . '%')
                            ->orWhereHas('person', function ($p) use ($search) {
                                $p->where('full_name', 'ilike', '%' . $search . '%')
                                    ->orWhere('nickname', 'ilike', '%' . $search . '%');
                            });
                    })
                    ->orWhereHas('schedule', function ($q) use ($search) {
                        $q->where('code', 'ilike', '%' . $search . '%');
                    });
            });
        }

        $patientExams = $patientExams->paginate($this->perPage());

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

        abort_unless(
            Patient::where('id', $patientId)->where('entity_id', $entityId)->exists(),
            404,
        );

        // Reserva atômica da cota ANTES de criar; reverte se a criação falhar.
        $this->featureGate->consumeOrFail($entityId, FeatureKey::ApiMonthlyExamSends);

        try {
            $record = $this->service->create($request, $patientId);
        } catch (Throwable $e) {
            $this->featureGate->decrement($entityId, FeatureKey::ApiMonthlyExamSends);

            throw $e;
        }

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

        // Registra o acesso ao exame específico (LGPD Art. 37 / CFM 2.227/2018).
        $this->logAccess($record, DataAccessPurpose::ApiAccess, patientId: $patient->id);

        return new PatientExamResource($record);
    }

    /**
     * Update the specified resource in storage.
     *
     * @throws Throwable
     */
    public function update(PatientExamRequest $request, string $patientId, string $idOrCode): PatientExamResource|JsonResponse
    {
        abort_unless(Str::isUuid($patientId), 404);

        $integrator = request()->attributes->get('integrator');
        $entityId   = $integrator->user->entity_id;

        abort_unless(
            Patient::where('id', $patientId)->where('entity_id', $entityId)->exists(),
            404,
        );

        $record = $this->service->findByIdOrCode($patientId, $idOrCode);

        // Reserva atômica da cota ANTES de atualizar; reverte se a atualização falhar.
        $this->featureGate->consumeOrFail($entityId, FeatureKey::ApiMonthlyExamSends);

        try {
            $updatedRecord = $this->service->update($record, $request);
        } catch (Throwable $e) {
            $this->featureGate->decrement($entityId, FeatureKey::ApiMonthlyExamSends);

            throw $e;
        }

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
        [$column, $value] = match (true) {
            Str::isUuid($idOrCode) => ['id', $idOrCode],
            ctype_digit($idOrCode) => ['code', sprintf('PAC-%010d', (int) $idOrCode)],
            default                => ['code', $idOrCode],
        };

        return Patient::where('entity_id', $entityId)
            ->where($column, $value)
            ->firstOrFail();
    }
}
