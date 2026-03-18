<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\EntityIntegratorEquipmentRequest;
use App\Http\Resources\EntityIntegratorEquipmentResource;
use App\Models\{EntityIntegratorEquipment};
use App\Services\Api\EntityIntegratorEquipmentService;
use Illuminate\Http\{JsonResponse};
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class EntityIntegratorEquipmentsController extends Controller
{
    /**
     * Instance of the standard model.
     */
    protected EntityIntegratorEquipment $model;

    protected EntityIntegratorEquipmentService $service;

    public function __construct(EntityIntegratorEquipment $entityIntegratorEquipment, EntityIntegratorEquipmentService $entityIntegratorEquipmentService)
    {
        $this->model   = $entityIntegratorEquipment;
        $this->service = $entityIntegratorEquipmentService;
    }

    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse|AnonymousResourceCollection
    {
        $integrator = request()->attributes->get('integrator');
        $equipments = $this->model->query()->where('integrator_id', $integrator->id);

        if (request()->has('search')) {
            $equipments = $equipments->where(function ($query) {
                $query->where('name', 'like', '%' . request()->search . '%')
                    ->orWhere('code', 'like', '%' . request()->search . '%');
            });
        }

        $equipments = $equipments->paginate(min((int) request()->get('per_page', 10), 10));

        return EntityIntegratorEquipmentResource::collection($equipments);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(EntityIntegratorEquipmentRequest $request): EntityIntegratorEquipmentResource|JsonResponse
    {

        $record = $this->service->create($request);

        return new EntityIntegratorEquipmentResource($record);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id): EntityIntegratorEquipmentResource|JsonResponse
    {
        $record = $this->service->findByIdOrCode($id);

        return new EntityIntegratorEquipmentResource($record);
    }

    /**
     * Update the specified resource in storage.
     *
     * @throws \Throwable
     */
    public function update(EntityIntegratorEquipmentRequest $request, string $id): EntityIntegratorEquipmentResource|JsonResponse
    {
        $record = $this->service->findByIdOrCode($id);

        $updatedRecord = $this->service->update($record, $request);

        return new EntityIntegratorEquipmentResource($updatedRecord);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id): JsonResponse
    {
        $this->service->destroyById($id);

        return response()->json([], HttpResponse::HTTP_NO_CONTENT);
    }
}
