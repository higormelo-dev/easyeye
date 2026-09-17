<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ClinicResourceResource;
use App\Models\ClinicResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ClinicResourcesController extends Controller
{
    /**
     * Lists `clinic_resources` (type `equipment`) the integrator's operator
     * can link a local equipment to — see
     * `EntityIntegratorEquipmentRequest::rules()`'s `clinic_resource_id`
     * validation for the write side of this same tenant scoping.
     */
    public function index(): AnonymousResourceCollection
    {
        $entityId = request()->attributes->get('integrator')->user->entity_id;

        $resources = ClinicResource::query()
            ->where('entity_id', $entityId)
            ->where('type', 'equipment');

        if (request()->has('search')) {
            $search    = request()->search;
            $resources = $resources->whereLikeUnaccent('name', $search);
        }

        $resources = $resources->paginate($this->perPage());

        return ClinicResourceResource::collection($resources);
    }
}
