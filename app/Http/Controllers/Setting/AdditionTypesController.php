<?php

namespace App\Http\Controllers\Setting;

use App\DataTables\AdditionTypesDataTable;
use App\Http\Requests\AdditionTypeRequest;
use App\Http\Resources\AdditionTypeResource;
use App\Services\AdditionTypeService;

class AdditionTypesController extends BaseSettingController
{
    public function __construct(AdditionTypesDataTable $dataTable, AdditionTypeService $service)
    {
        $this->titleController = __('actions.sidemenu.additiontypes');
        $this->dataTable       = $dataTable;
        $this->service         = $service;
        $this->resourceClass   = AdditionTypeResource::class;
        $this->routePrefix     = 'panel.setting.additiontypes';
        $this->jsFile          = 'resources/js/system/additiontypes.js';
        $this->baseUrl         = url('panel/setting/additiontypes');
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
