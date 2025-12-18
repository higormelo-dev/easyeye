<?php

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Models\{Entity, EntityIntegrator, EntityIntegratorEquipment};
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;

class EntityIntegratorEquipmentsController extends Controller
{
    protected string $titleController = 'Equipamentos';

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
    public function index(string $entityId, string $integratorId): Application|View
    {
        $entity     = Entity::query()->findOrFail($entityId);
        $integrator = EntityIntegrator::query()->findOrFail($integratorId);

        $meta = [
            'title'       => $this->titleController . ': ' . $integrator->name,
            'action'      => __('actions.records'),
            'breadcrumbs' => [
                [
                    'label'  => __('actions.sidemenu.dashboard'),
                    'url'    => route('panel.dashboard'),
                    'active' => false,
                ],
                [
                    'label'  => __('actions.sidemenu.entities'),
                    'url'    => route('panel.manager.entities.index'),
                    'active' => false,
                ],
                [
                    'label'  => __('actions.integrator'),
                    'url'    => route('panel.manager.entities.integrators.index', $entity->id),
                    'active' => false,
                ],
                [
                    'label' => $this->titleController,
                    'url'   => route(
                        'panel.manager.entities.integrators.equipments.index',
                        [$entity->id, $integrator->id]
                    ),
                    'active' => false,
                ],
                [
                    'label'  => __('actions.records'),
                    'url'    => 'javascript:void(0);',
                    'active' => true,
                ],
            ],
        ];

        return view(
            'system.manager.entity_integrator_equipments.index',
            compact('meta', 'entity')
        );
    }
}
