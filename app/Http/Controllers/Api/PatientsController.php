<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PatientResource;
use App\Models\{EntityIntegrator, Patient};
use Illuminate\Http\{JsonResponse, Request};
use Symfony\Component\HttpFoundation\Response as HttpResponse;

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
        $integrator = $this->getAuthenticatedIntegrator();

        if (!$integrator) {
            return $this->unauthorizedResponse();
        }

        $patients = $this->model->query()
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
    public function show(string $id): PatientResource|JsonResponse
    {
        try {
            $integrator = $this->getAuthenticatedIntegrator();

            if (!$integrator) {
                return $this->unauthorizedResponse();
            }

            $patient = $this->findPatientForIntegrator($integrator->entity_id, $id);

            if (!$patient) {
                return $this->notFoundResponse();
            }

            return new PatientResource($patient);
        } catch (\Throwable $e) {
            return $this->serverErrorResponse();
        }
    }

    /**
     * Get authenticated integrator by bearer token
     */
    private function getAuthenticatedIntegrator(): ?EntityIntegrator
    {
        return EntityIntegrator::query()
            ->where('token_session', request()->bearerToken())
            ->first();
    }

    /**
     * Find patient for specific integrator
     */
    private function findPatientForIntegrator(string $entityId, string $equipmentId): ?Patient
    {
        return $this->model->query()
            ->where('entity_id', $entityId)
            ->where('id', $equipmentId)
            ->first();
    }

    /**
     * Return unauthorized response
     */
    private function unauthorizedResponse(): JsonResponse
    {
        return response()->json(['message' => 'Not authorized.'], HttpResponse::HTTP_UNAUTHORIZED);
    }

    /**
     * Return not found response
     */
    private function notFoundResponse(): JsonResponse
    {
        return response()->json(['message' => 'Patient not found.'], HttpResponse::HTTP_NOT_FOUND);
    }

    /**
     * Return server error response
     */
    private function serverErrorResponse(): JsonResponse
    {
        return response()->json(['message' => 'An error occurred.'], HttpResponse::HTTP_INTERNAL_SERVER_ERROR);
    }
}
