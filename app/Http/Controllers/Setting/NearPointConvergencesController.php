<?php

declare(strict_types=1);

namespace App\Http\Controllers\Setting;

use App\Http\Requests\NearPointConvergenceRequest;
use App\Http\Resources\NearPointConvergenceResource;
use App\Services\NearPointConvergenceService;

class NearPointConvergencesController extends BaseSettingController
{
    public function __construct(NearPointConvergenceService $service)
    {
        $this->titleController = __('actions.sidemenu.nearpointconvergences');
        $this->service         = $service;
        $this->resourceClass   = NearPointConvergenceResource::class;
        $this->routePrefix     = 'panel.setting.nearpointconvergences';
        $this->viewSlot        = 'nearpointconvergences';

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

    public function store(NearPointConvergenceRequest $request)
    {
        return $this->genericStore($request);
    }

    public function update(NearPointConvergenceRequest $request, string $id)
    {
        return $this->genericUpdate($request, $id);
    }
}
