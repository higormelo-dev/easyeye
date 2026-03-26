<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PatientResource;
use App\Models\{Patient};
use Illuminate\Support\Str;

class PatientsController extends Controller
{
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
                    $q->where('full_name', 'ilike', '%' . $search . '%');
                })
                    ->orWhere('code', 'ilike', '%' . $search . '%')
                    ->orWhere('card_number', 'ilike', '%' . $search . '%');
            });
        }

        $patients = $patients->paginate(min((int) request()->get('per_page', 10), 10));

        return PatientResource::collection($patients);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $idOrCode): PatientResource
    {
        $integrator = request()->attributes->get('integrator');

        [$column, $value] = match (true) {
            Str::isUuid($idOrCode) => ['id',   $idOrCode],
            ctype_digit($idOrCode) => ['code', sprintf('PAC-%010d', (int) $idOrCode)],
            default                => ['code', $idOrCode],
        };

        $patient = $this->model->query()
            ->with(['entity', 'person', 'covenant', 'skinType', 'irisType'])
            ->where('entity_id', $integrator->user->entity_id)
            ->where($column, $value)
            ->firstOrFail();

        return new PatientResource($patient);
    }
}
