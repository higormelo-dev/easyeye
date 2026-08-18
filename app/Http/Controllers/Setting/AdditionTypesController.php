<?php

declare(strict_types=1);

namespace App\Http\Controllers\Setting;

use App\Http\Requests\AdditionTypeRequest;
use App\Http\Resources\AdditionTypeResource;
use App\Services\AdditionTypeService;

class AdditionTypesController extends BaseSettingController
{
    public function __construct(AdditionTypeService $service)
    {
        $this->titleController = __('actions.sidemenu.additiontypes');
        $this->service         = $service;
        $this->resourceClass   = AdditionTypeResource::class;
        $this->routePrefix     = 'panel.setting.additiontypes';
        $this->viewSlot        = 'additiontypes';

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

    public function store(AdditionTypeRequest $request)
    {
        return $this->genericStore($request);
    }

    public function update(AdditionTypeRequest $request, string $id)
    {
        return $this->genericUpdate($request, $id);
    }
}
