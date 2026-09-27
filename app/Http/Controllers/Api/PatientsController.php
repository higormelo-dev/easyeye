<?php

namespace App\Http\Controllers\Api;

use App\Enums\DataAccessPurpose;
use App\Http\Controllers\Controller;
use App\Http\Resources\PatientResource;
use App\Models\Patient;
use App\Traits\LogsDataAccess;
use Illuminate\Support\Str;

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

        return PatientResource::collection($patients);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $idOrCode): PatientResource
    {
        $integrator = request()->attributes->get('integrator');

        $query = $this->model->query()
            ->with(['entity', 'person', 'covenant', 'skinType', 'irisType'])
            ->where('entity_id', $integrator->user->entity_id);

        if (Str::isUuid($idOrCode)) {
            $query->where('id', $idOrCode);
        } elseif (ctype_digit($idOrCode)) {
            // Número puro: pode ser o código interno (PAC-0000000042) OU o
            // código do sistema anterior do integrador (import_code costuma
            // ser só numérico em sistemas legados) — tenta os dois.
            $formattedCode = sprintf('PAC-%010d', (int) $idOrCode);
            $query->where(function ($q) use ($formattedCode, $idOrCode) {
                $q->where('code', $formattedCode)
                    ->orWhere('import_code', $idOrCode);
            });
        } else {
            $query->where(function ($q) use ($idOrCode) {
                $q->where('code', $idOrCode)
                    ->orWhere('import_code', $idOrCode);
            });
        }

        $patient = $query->firstOrFail();

        // LGPD Art. 37 / CFM 2.227/2018: registra acesso ao cadastro do paciente.
        $this->logAccess($patient, DataAccessPurpose::ApiAccess, patientId: $patient->id);

        return new PatientResource($patient);
    }
}
