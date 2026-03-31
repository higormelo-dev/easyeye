<?php

namespace App\Http\Controllers\Setting;

use App\DataTables\CoverTestTypesDataTable;
use App\Http\Requests\CoverTestTypeRequest;
use App\Http\Resources\CoverTestTypeResource;
use App\Services\CoverTestTypeService;

class CoverTestTypesController extends BaseSettingController
{
    public function __construct(CoverTestTypesDataTable $dataTable, CoverTestTypeService $service)
    {
        $this->titleController = __('actions.sidemenu.covertesttypes');
        $this->dataTable       = $dataTable;
        $this->service         = $service;
        $this->resourceClass   = CoverTestTypeResource::class;
        $this->routePrefix     = 'panel.setting.covertesttypes';
        $this->jsFile          = 'resources/js/system/covertesttypes.js';
        $this->viewSlot        = 'covertesttypes';
        $this->baseUrl         = url('panel/setting/covertesttypes');
        $this->inertiaPage     = 'CoverTestTypes';
        $this->crudFields      = ['name' => '', 'abbreviation' => '', 'active' => true];
    }

    public function store(CoverTestTypeRequest $request) { return $this->genericStore($request); }
    public function update(CoverTestTypeRequest $request, string $id) { return $this->genericUpdate($request, $id); }
}
