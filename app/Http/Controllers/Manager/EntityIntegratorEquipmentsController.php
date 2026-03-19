<?php

namespace App\Http\Controllers\Manager;

use App\DataTables\EntityIntegratorEquipmentsDataTable;
use App\Http\Controllers\Controller;
use App\Models\{Entity, EntityIntegrator, EntityIntegratorEquipment, EntityUserIntegrator};
use Illuminate\Http\JsonResponse;

class EntityIntegratorEquipmentsController extends Controller
{
    protected string $titleController = 'Equipamentos';

    protected EntityIntegratorEquipment $model;

    public function __construct(EntityIntegratorEquipment $entityIntegratorEquipment)
    {
        $this->model = $entityIntegratorEquipment;
    }

    /**
     * Display a listing of the resource.
     */
    public function index(string $entityId, string $userIntegrator, string $integrator, EntityIntegratorEquipmentsDataTable $dataTable): mixed
    {
        $entity              = Entity::query()->findOrFail($entityId);
        $userIntegratorModel = EntityUserIntegrator::query()
            ->where('entity_id', $entity->id)
            ->findOrFail($userIntegrator);
        $integratorModel     = EntityIntegrator::query()
            ->where('entity_user_integrator_id', $userIntegratorModel->id)
            ->findOrFail($integrator);

        $meta = [
            'title'       => $this->titleController . ': ' . $integratorModel->name,
            'action'      => __('actions.records'),
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('actions.sidemenu.entities'), 'url' => route('panel.manager.entities.index'), 'active' => false],
                ['label' => __('actions.user_integrators'), 'url' => route('panel.manager.entities.user-integrators.index', $entityId), 'active' => false],
                ['label' => __('actions.integrators'), 'url' => route('panel.manager.entities.user-integrators.integrators.index', [$entityId, $userIntegrator]), 'active' => false],
                ['label' => $this->titleController, 'url' => route('panel.manager.entities.user-integrators.integrators.equipments.index', [$entityId, $userIntegrator, $integratorModel->id]), 'active' => false],
                ['label' => __('actions.records'), 'url' => 'javascript:void(0);', 'active' => true],
            ],
        ];

        return $dataTable
            ->forIntegrator($integrator)
            ->render('system.manager.entity_integrator_equipments.index', [
                'meta'           => $meta,
                'entity'         => $entity,
                'userIntegrator' => $userIntegratorModel,
                'integrator'     => $integratorModel,
            ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $entityId, string $userIntegrator, string $integratorId, string $equipment): mixed
    {
        $entity     = Entity::query()->withTrashed()->findOrFail($entityId);
        $integrator = EntityIntegrator::query()->withTrashed()
            ->where('entity_user_integrator_id', $userIntegrator)
            ->findOrFail($integratorId);
        $record     = $this->model->query()->withTrashed()->where('integrator_id', $integrator->id)->findOrFail($equipment);

        if (request()->wantsJson()) {
            return response()->json(['data' => $record->toArray()]);
        }

        return view('system.manager.entity_integrator_equipments.show', compact('entity', 'integrator', 'record'));
    }
}
