<?php

declare(strict_types=1);

namespace App\Http\Controllers\Setting;

use App\Http\Requests\CoverTestTypeRequest;
use App\Http\Resources\CoverTestTypeResource;
use App\Services\CoverTestTypeService;

class CoverTestTypesController extends BaseSettingController
{
    public function __construct(CoverTestTypeService $service)
    {
        $this->titleController = __('actions.sidemenu.covertesttypes');
        $this->service         = $service;
        $this->resourceClass   = CoverTestTypeResource::class;
        $this->routePrefix     = 'panel.setting.covertesttypes';
        $this->viewSlot        = 'covertesttypes';
        $this->crudFields      = ['name' => '', 'abbreviation' => '', 'active' => true];

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
            ['key' => 'abbreviation', 'label' => __('actions.abbreviation'), 'type' => 'abbrev'],
        ];
    }

    protected function getFormFields(): array
    {
        return [
            ['key' => 'name', 'label' => __('actions.name'), 'type' => 'text', 'required' => true],
            ['key' => 'abbreviation', 'label' => __('actions.abbreviation'), 'type' => 'text', 'required' => false, 'maxlength' => 10],
        ];
    }

    public function store(CoverTestTypeRequest $request)
    {
        return $this->genericStore($request);
    }

    public function update(CoverTestTypeRequest $request, string $id)
    {
        return $this->genericUpdate($request, $id);
    }
}
