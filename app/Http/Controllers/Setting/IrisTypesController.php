<?php

namespace App\Http\Controllers\Setting;

use App\DataTables\IrisTypesDataTable;
use App\Http\Requests\IrisTypeRequest;
use App\Http\Resources\IrisTypeResource;
use App\Services\IrisTypeService;

class IrisTypesController extends BaseSettingController
{
    public function __construct(IrisTypesDataTable $dataTable, IrisTypeService $service)
    {
        $this->titleController = __('actions.sidemenu.iristypes');
        $this->dataTable       = $dataTable;
        $this->service         = $service;
        $this->resourceClass   = IrisTypeResource::class;
        $this->routePrefix     = 'panel.setting.iristypes';
        $this->jsFile          = 'resources/js/system/iristypes.js';
        $this->baseUrl         = url('panel/setting/iristypes');
    }

    public function store(IrisTypeRequest $request) { return $this->genericStore($request); }
    public function update(IrisTypeRequest $request, string $id) { return $this->genericUpdate($request, $id); }
}
