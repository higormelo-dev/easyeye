<?php

namespace App\Http\Controllers\Setting;

use App\DataTables\CovenantsDataTable;
use App\Http\Requests\CovenantRequest;
use App\Http\Resources\CovenantResource;
use App\Services\CovenantService;

class CovenantsController extends BaseSettingController
{
    public function __construct(CovenantsDataTable $dataTable, CovenantService $service)
    {
        $this->titleController = __('actions.sidemenu.covenants');
        $this->dataTable       = $dataTable;
        $this->service         = $service;
        $this->resourceClass   = CovenantResource::class;
        $this->routePrefix     = 'panel.setting.covenants';
        $this->jsFile          = 'resources/js/system/covenants.js';
        $this->viewSlot        = 'covenants';
        $this->baseUrl         = url('panel/setting/covenants');
        $this->inertiaPage     = 'Covenants';
        $this->crudFields      = ['name' => '', 'color' => '#3699ff', 'table' => false, 'active' => true];
    }

    public function store(CovenantRequest $request)
    {
        return $this->genericStore($request);
    }

    public function update(CovenantRequest $request, string $id)
    {
        return $this->genericUpdate($request, $id);
    }
}
