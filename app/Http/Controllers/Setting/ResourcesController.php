<?php

namespace App\Http\Controllers\Setting;

use App\DataTables\ClinicResourcesDataTable;
use App\Http\Requests\ClinicResourceRequest;
use App\Http\Resources\ClinicResourceResource;
use App\Services\ClinicResourceService;

class ResourcesController extends BaseSettingController
{
    public function __construct(ClinicResourcesDataTable $dataTable, ClinicResourceService $service)
    {
        $this->titleController = 'Recursos da Clínica';
        $this->dataTable       = $dataTable;
        $this->service         = $service;
        $this->resourceClass   = ClinicResourceResource::class;
        $this->routePrefix     = 'panel.setting.resources';
        $this->jsFile          = 'resources/js/system/clinic-resources.js';
        $this->viewSlot        = 'clinic-resources';
        $this->baseUrl         = url('panel/setting/resources');
        $this->inertiaPage     = 'Resources';
        $this->crudFields      = [
            'name'        => '',
            'type'        => 'room',
            'description' => '',
            'active'      => true,
        ];
    }

    public function store(ClinicResourceRequest $request)
    {
        return $this->genericStore($request);
    }

    public function update(ClinicResourceRequest $request, string $id)
    {
        return $this->genericUpdate($request, $id);
    }
}
