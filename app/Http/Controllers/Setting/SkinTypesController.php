<?php

namespace App\Http\Controllers\Setting;

use App\DataTables\SkinTypesDataTable;
use App\Http\Requests\SkinTypeRequest;
use App\Http\Resources\SkinTypeResource;
use App\Services\SkinTypeService;

class SkinTypesController extends BaseSettingController
{
    public function __construct(SkinTypesDataTable $dataTable, SkinTypeService $service)
    {
        $this->titleController = __('actions.sidemenu.skintypes');
        $this->dataTable       = $dataTable;
        $this->service         = $service;
        $this->resourceClass   = SkinTypeResource::class;
        $this->routePrefix     = 'panel.setting.skintypes';
        $this->jsFile          = 'resources/js/system/skintypes.js';
        $this->baseUrl         = url('panel/setting/skintypes');
    }

    public function store(SkinTypeRequest $request)
    {
        return $this->genericStore($request);
    }
    public function update(SkinTypeRequest $request, string $id)
    {
        return $this->genericUpdate($request, $id);
    }
}
