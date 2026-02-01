<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PatientResource;
use App\Models\{Patient};
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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
        $patients   = $this->model->query()
            ->with(['entity', 'person', 'covenant', 'skinType', 'irisType'])
            ->where('entity_id', $integrator->entity_id);

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
    public function show(string $id): PatientResource
    {
        $integrator = request()->attributes->get('integrator');

        $patient = $this->model->query()
            ->where('entity_id', $integrator->entity_id)
            ->where(function ($query) use ($id) {
                $query->where('patients.id', $id)
                    ->orWhere('patients.code', $id);
            })
            ->firstOrFail();

        return new PatientResource($patient);
    }
}
