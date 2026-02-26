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
            $patients = $patients->join(
                'people',
                'patients.person_id',
                '=',
                'people.id'
            )->where(function ($query) {
                $query->where('people.full_name', 'like', '%' . request()->search . '%')
                    ->orWhere('patients.code', 'like', '%' . request()->search . '%')
                    ->orWhere('patients.card_number', 'like', '%' . request()->search . '%');
            });
        }

        $patients = $patients->paginate(request()->get('per_page', 10));

        return PatientResource::collection($patients);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $idOrCode): PatientResource
    {
        $integrator = request()->attributes->get('integrator');

        $patient = $this->model->query()
            ->where('entity_id', $integrator->user->entity_id)
            ->when(
                Str::isUuid($idOrCode),
                static fn ($q) => $q->where('id', $idOrCode),
                static fn ($q) => $q->where('code', $idOrCode)
            )
            ->firstOrFail();

        return new PatientResource($patient);
    }
}
