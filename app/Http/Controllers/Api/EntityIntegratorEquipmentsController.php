<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\EntityIntegratorEquipmentRequest;
use App\Http\Resources\EntityIntegratorEquipmentResource;
use App\Models\{EntityIntegrator, EntityIntegratorEquipment};
use Illuminate\Http\{JsonResponse};
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class EntityIntegratorEquipmentsController extends Controller
{
    /**
     * Instance of the standard model.
     */
    protected EntityIntegratorEquipment $model;

    public function __construct(EntityIntegratorEquipment $entityIntegratorEquipment)
    {
        $this->model = $entityIntegratorEquipment;
    }

    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse|AnonymousResourceCollection
    {
        $integrator = $this->getAuthenticatedIntegrator();

        if (! $integrator) {
            return $this->unauthorizedResponse();
        }

        $equipments = $this->model->query()->where('integrator_id', $integrator->id);

        if (request()->has('search')) {
            $equipments = $equipments->where(function ($query) {
                $query->where('name', 'like', '%' . request()->search . '%');
            });
        }

        $equipments = $equipments->paginate(request()->get('per_page', 10));

        return EntityIntegratorEquipmentResource::collection($equipments);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(EntityIntegratorEquipmentRequest $request): EntityIntegratorEquipmentResource|JsonResponse
    {
        try {
            $integrator = $this->getAuthenticatedIntegrator();

            if (! $integrator) {
                return $this->unauthorizedResponse();
            }

            $data      = collect($request->validated())->merge(['integrator_id' => $integrator->id, 'active' => true]);
            $equipment = $this->model->create($data->toArray());

            return new EntityIntegratorEquipmentResource($equipment);
        } catch (\Throwable $e) {
            return $this->serverErrorResponse();
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id): EntityIntegratorEquipmentResource|JsonResponse
    {
        try {
            $integrator = $this->getAuthenticatedIntegrator();

            if (! $integrator) {
                return $this->unauthorizedResponse();
            }

            $equipment = $this->findEquipmentForIntegrator($integrator->id, $id);

            if (! $equipment) {
                return $this->notFoundResponse();
            }

            return new EntityIntegratorEquipmentResource($equipment);
        } catch (\Throwable $e) {
            return $this->serverErrorResponse();
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(EntityIntegratorEquipmentRequest $request, string $id): EntityIntegratorEquipmentResource|JsonResponse
    {
        try {
            $integrator = $this->getAuthenticatedIntegrator();

            if (! $integrator) {
                return $this->unauthorizedResponse();
            }

            $equipment = $this->findEquipmentForIntegrator($integrator->id, $id);

            if (! $equipment) {
                return $this->notFoundResponse();
            }

            $data = collect($request->validated())->merge(['integrator_id' => $integrator->id]);
            $equipment->update($data->toArray());
            $equipment->refresh();

            return new EntityIntegratorEquipmentResource($equipment);
        } catch (\Throwable $e) {
            return $this->serverErrorResponse();
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $integrator = $this->getAuthenticatedIntegrator();

            if (! $integrator) {
                return $this->unauthorizedResponse();
            }

            $equipment = $this->findEquipmentForIntegrator($integrator->id, $id);

            if (! $equipment) {
                return $this->notFoundResponse();
            }

            $equipment->delete();

            return response()->json([], HttpResponse::HTTP_NO_CONTENT);
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
     * Find equipment for specific integrator
     */
    private function findEquipmentForIntegrator(string $integratorId, string $equipmentId): ?EntityIntegratorEquipment
    {
        return $this->model->query()
            ->where('integrator_id', $integratorId)
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
        return response()->json(['message' => 'Equipment not found.'], HttpResponse::HTTP_NOT_FOUND);
    }

    /**
     * Return server error response
     */
    private function serverErrorResponse(): JsonResponse
    {
        return response()->json(['message' => 'An error occurred.'], HttpResponse::HTTP_INTERNAL_SERVER_ERROR);
    }

}
