<?php

declare(strict_types=1);

namespace App\Http\Controllers\Setting;

use App\Http\Requests\LenseRequest;
use App\Http\Resources\LenseResource;
use App\Services\LenseService;

class LensesController extends BaseSettingController
{
    public function __construct(LenseService $service)
    {
        $this->titleController = __('actions.sidemenu.lenses');
        $this->service         = $service;
        $this->resourceClass   = LenseResource::class;
        $this->routePrefix     = 'panel.setting.lenses';
        $this->viewSlot        = 'lenses';
        $this->crudFields      = ['name' => '', 'away' => false, 'near' => false, 'active' => true];

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
            ['key' => 'away', 'label' => __('actions.away'), 'type' => 'yesno'],
            ['key' => 'near', 'label' => __('actions.near'), 'type' => 'yesno'],
        ];
    }

    protected function getFormFields(): array
    {
        return [
            ['key' => 'name', 'label' => __('actions.name'), 'type' => 'text', 'required' => true],
            ['key' => 'away', 'label' => __('actions.away'), 'type' => 'checkbox'],
            ['key' => 'near', 'label' => __('actions.near'), 'type' => 'checkbox'],
        ];
    }

    public function store(LenseRequest $request)
    {
        return $this->genericStore($request);
    }

    public function update(LenseRequest $request, string $id)
    {
        return $this->genericUpdate($request, $id);
    }
}
