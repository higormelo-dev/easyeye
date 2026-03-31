<?php

namespace App\Http\Controllers\Setting;

use App\DataTables\VisitTypesDataTable;
use App\Http\Requests\VisitTypeRequest;
use App\Http\Resources\VisitTypeResource;
use App\Services\VisitTypeService;

class VisitTypesController extends BaseSettingController
{
    public function __construct(VisitTypesDataTable $dataTable, VisitTypeService $service)
    {
        $this->titleController = __('actions.sidemenu.visittypes');
        $this->dataTable       = $dataTable;
        $this->service         = $service;
        $this->resourceClass   = VisitTypeResource::class;
        $this->routePrefix     = 'panel.setting.visittypes';
        $this->jsFile          = 'resources/js/system/visittypes.js';
        $this->baseUrl         = url('panel/setting/visittypes');
        $this->inertiaPage     = 'VisitTypes';
    }

    public function store(VisitTypeRequest $request) { return $this->genericStore($request); }
    public function update(VisitTypeRequest $request, string $id) { return $this->genericUpdate($request, $id); }
}
