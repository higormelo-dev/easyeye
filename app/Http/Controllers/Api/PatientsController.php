<?php

namespace App\Http\Controllers\Api;

use App\Enums\DataAccessPurpose;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\IntegratorPatientResource as PatientResource;
use App\Models\Patient;
use App\Services\Api\PatientExamService;
use App\Services\DataAccessLogService;
use App\Traits\LogsDataAccess;

class PatientsController extends Controller
{
    use LogsDataAccess;

    /**
     * Instance of the standard model.
     */
    protected Patient $model;

    public function __construct(Patient $patient)
    {
        $this->model = $patient;
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $integrator = request()->attributes->get('integrator');

        $patients = $this->model->query()
            ->with(['entity', 'person', 'covenant', 'skinType', 'irisType'])
            ->where('entity_id', $integrator->user->entity_id);

        if (request()->has('search')) {
            $search   = request()->search;
            $patients = $patients->where(function ($query) use ($search) {
                $query->whereHas('person', function ($q) use ($search) {
                    $q->whereLikeUnaccent('full_name', $search);
                })
                    ->orWhereLikeUnaccent('code', $search)
                    ->orWhereLikeUnaccent('import_code', $search)
                    ->orWhereLikeUnaccent('card_number', $search);
            });
        }

        $patients = $patients->paginate($this->perPage());

        app(DataAccessLogService::class)->aggregate('patients', $patients->count(), request()->only(['date', 'clinic_resource_id', 'page']));

        return PatientResource::collection($patients);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $idOrCode): PatientResource
    {
        $integrator = request()->attributes->get('integrator');

        $patient = app(PatientExamService::class)->patientFindByIdOrCode($idOrCode, (string) $integrator->user->entity_id);
        abort_unless($patient !== null, 404);
        $patient->load(['entity', 'person', 'covenant', 'skinType', 'irisType']);

        // LGPD Art. 37 / CFM 2.227/2018: registra acesso ao cadastro do paciente.
        $this->logAccess($patient, DataAccessPurpose::ApiAccess, patientId: $patient->id);

        return new PatientResource($patient);
    }
}
