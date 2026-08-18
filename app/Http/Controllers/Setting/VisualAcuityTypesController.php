<?php

declare(strict_types=1);

namespace App\Http\Controllers\Setting;

use App\Http\Requests\VisualAcuityTypeRequest;
use App\Http\Resources\VisualAcuityTypeResource;
use App\Services\VisualAcuityTypeService;

class VisualAcuityTypesController extends BaseSettingController
{
    public function __construct(VisualAcuityTypeService $service)
    {
        $this->titleController = __('actions.sidemenu.visualacuitytypes');
        $this->service         = $service;
        $this->resourceClass   = VisualAcuityTypeResource::class;
        $this->routePrefix     = 'panel.setting.visualacuitytypes';
        $this->viewSlot        = 'visualacuitytypes';
        $this->crudFields      = ['name' => '', 'scale' => 0, 'active' => true];

        // Grupo de abas "Parâmetros oftalmológicos" — mesma lista replicada
        // nos 8 controllers do grupo (cada aba navega para as demais).
        $this->tabsGroup = [
            ['route' => 'panel.setting.skintypes.index', 'label' => __('actions.sidemenu.skintypes')],
            ['route' => 'panel.setting.iristypes.index', 'label' => __('actions.sidemenu.iristypes')],
            ['route' => 'panel.setting.additiontypes.index', 'label' => __('actions.sidemenu.additiontypes')],
            ['route' => 'panel.setting.visualacuitytypes.index', 'label' => __('actions.sidemenu.visualacuitytypes')],
            ['route' => 'panel.setting.colorvisiontypes.index', 'label' => __('actions.sidemenu.colorvisiontypes')],
            ['route' => 'panel.setting.nearpointconvergences.index', 'label' => __('actions.sidemenu.nearpointconvergences')],
            ['route' => 'panel.setting.covertesttypes.index', 'label' => __('actions.sidemenu.covertesttypes')],
            ['route' => 'panel.setting.lenses.index', 'label' => __('actions.sidemenu.lenses')],
        ];
    }

    protected function getColumns(): array
    {
        return [
            ['key' => 'code', 'label' => __('actions.code'), 'type' => 'code'],
            ['key' => 'name', 'label' => __('actions.name'), 'type' => 'text', 'sortable' => true],
            ['key' => 'scale', 'label' => __('actions.scale'), 'type' => 'numeric'],
        ];
    }

    protected function getFormFields(): array
    {
        return [
            ['key' => 'name', 'label' => __('actions.name'), 'type' => 'text', 'required' => true],
            ['key' => 'scale', 'label' => __('actions.scale'), 'type' => 'numeric', 'required' => true, 'min' => 0, 'step' => 0.01],
        ];
    }

    public function store(VisualAcuityTypeRequest $request)
    {
        return $this->genericStore($request);
    }

    public function update(VisualAcuityTypeRequest $request, string $id)
    {
        return $this->genericUpdate($request, $id);
    }
}
