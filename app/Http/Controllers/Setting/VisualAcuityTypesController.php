<?php

namespace App\Http\Controllers\Setting;

use App\DataTables\VisualAcuityTypesDataTable;
use App\Http\Requests\VisualAcuityTypeRequest;
use App\Http\Resources\VisualAcuityTypeResource;
use App\Services\VisualAcuityTypeService;

class VisualAcuityTypesController extends BaseSettingController
{
    public function __construct(VisualAcuityTypesDataTable $dataTable, VisualAcuityTypeService $service)
    {
        $this->titleController = __('actions.sidemenu.visualacuitytypes');
        $this->dataTable       = $dataTable;
        $this->service         = $service;
        $this->resourceClass   = VisualAcuityTypeResource::class;
        $this->routePrefix     = 'panel.setting.visualacuitytypes';
        $this->jsFile          = 'resources/js/system/visualacuitytypes.js';
        $this->viewSlot        = 'visualacuitytypes';
        $this->baseUrl         = url('panel/setting/visualacuitytypes');
        $this->crudFields      = ['name' => '', 'scale' => 0, 'active' => true];
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
