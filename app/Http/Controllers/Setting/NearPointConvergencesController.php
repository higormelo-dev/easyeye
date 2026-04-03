<?php

namespace App\Http\Controllers\Setting;

use App\DataTables\NearPointConvergencesDataTable;
use App\Http\Requests\NearPointConvergenceRequest;
use App\Http\Resources\NearPointConvergenceResource;
use App\Services\NearPointConvergenceService;

class NearPointConvergencesController extends BaseSettingController
{
    public function __construct(NearPointConvergencesDataTable $dataTable, NearPointConvergenceService $service)
    {
        $this->titleController = __('actions.sidemenu.nearpointconvergences');
        $this->dataTable       = $dataTable;
        $this->service         = $service;
        $this->resourceClass   = NearPointConvergenceResource::class;
        $this->routePrefix     = 'panel.setting.nearpointconvergences';
        $this->jsFile          = 'resources/js/system/nearpointconvergences.js';
        $this->baseUrl         = url('panel/setting/nearpointconvergences');
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
