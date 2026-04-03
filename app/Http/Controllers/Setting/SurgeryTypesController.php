<?php

namespace App\Http\Controllers\Setting;

use App\DataTables\SurgeryTypesDataTable;
use App\Http\Requests\SurgeryTypeRequest;
use App\Http\Resources\SurgeryTypeResource;
use App\Models\SurgeryType;
use App\Services\SurgeryTypeService;

class SurgeryTypesController extends BaseSettingController
{
    public function __construct(SurgeryTypesDataTable $dataTable, SurgeryTypeService $service)
    {
        $this->titleController = __('actions.sidemenu.surgerytypes');
        $this->dataTable       = $dataTable;
        $this->service         = $service;
        $this->resourceClass   = SurgeryTypeResource::class;
        $this->routePrefix     = 'panel.setting.surgerytypes';
        $this->jsFile          = 'resources/js/system/surgerytypes.js';
        $this->viewSlot        = 'surgerytypes';
        $this->baseUrl         = url('panel/setting/surgerytypes');
        $this->crudFields      = ['name' => '', 'category' => '', 'active' => true];
    }

    protected function viewData(): array
    {
        return array_merge(parent::viewData(), ['categories' => SurgeryType::$categories]);
    }

    public function store(SurgeryTypeRequest $request)
    {
        return $this->genericStore($request);
    }
    public function update(SurgeryTypeRequest $request, string $id)
    {
        return $this->genericUpdate($request, $id);
    }
}
