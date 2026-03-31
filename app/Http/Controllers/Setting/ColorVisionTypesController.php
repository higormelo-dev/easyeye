<?php

namespace App\Http\Controllers\Setting;

use App\DataTables\ColorVisionTypesDataTable;
use App\Http\Requests\ColorVisionTypeRequest;
use App\Http\Resources\ColorVisionTypeResource;
use App\Services\ColorVisionTypeService;

class ColorVisionTypesController extends BaseSettingController
{
    public function __construct(ColorVisionTypesDataTable $dataTable, ColorVisionTypeService $service)
    {
        $this->titleController = __('actions.sidemenu.colorvisiontypes');
        $this->dataTable       = $dataTable;
        $this->service         = $service;
        $this->resourceClass   = ColorVisionTypeResource::class;
        $this->routePrefix     = 'panel.setting.colorvisiontypes';
        $this->jsFile          = 'resources/js/system/colorvisiontypes.js';
        $this->baseUrl         = url('panel/setting/colorvisiontypes');
        $this->inertiaPage     = 'ColorVisionTypes';
    }

    public function store(ColorVisionTypeRequest $request) { return $this->genericStore($request); }
    public function update(ColorVisionTypeRequest $request, string $id) { return $this->genericUpdate($request, $id); }
}
