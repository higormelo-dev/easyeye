<?php

namespace App\Http\Controllers\Setting;

use App\DataTables\LensesDataTable;
use App\Http\Requests\LenseRequest;
use App\Http\Resources\LenseResource;
use App\Services\LenseService;

class LensesController extends BaseSettingController
{
    public function __construct(LensesDataTable $dataTable, LenseService $service)
    {
        $this->titleController = __('actions.sidemenu.lenses');
        $this->dataTable       = $dataTable;
        $this->service         = $service;
        $this->resourceClass   = LenseResource::class;
        $this->routePrefix     = 'panel.setting.lenses';
        $this->jsFile          = 'resources/js/system/lenses.js';
        $this->viewSlot        = 'lenses';
        $this->baseUrl         = url('panel/setting/lenses');
        $this->crudFields      = ['name' => '', 'away' => false, 'near' => false, 'active' => true];
    }

    public function store(LenseRequest $request) { return $this->genericStore($request); }
    public function update(LenseRequest $request, string $id) { return $this->genericUpdate($request, $id); }
}
