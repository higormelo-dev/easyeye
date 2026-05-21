<?php

declare(strict_types=1);

namespace App\Http\Controllers\Setting;

use App\Http\Requests\CoverTestTypeRequest;
use App\Http\Resources\CoverTestTypeResource;
use App\Services\CoverTestTypeService;

class CoverTestTypesController extends BaseSettingController
{
    public function __construct(CoverTestTypeService $service)
    {
        $this->titleController = __('actions.sidemenu.covertesttypes');
        $this->service         = $service;
        $this->resourceClass   = CoverTestTypeResource::class;
        $this->routePrefix     = 'panel.setting.covertesttypes';
        $this->viewSlot        = 'covertesttypes';
        $this->crudFields      = ['name' => '', 'abbreviation' => '', 'active' => true];
    }

    protected function getColumns(): array
    {
        return [
            ['key' => 'code',         'label' => __('actions.code'),         'type' => 'code'],
            ['key' => 'name',         'label' => __('actions.name'),         'type' => 'text', 'sortable' => true],
            ['key' => 'abbreviation', 'label' => __('actions.abbreviation'), 'type' => 'abbrev'],
        ];
    }

    protected function getFormFields(): array
    {
        return [
            ['key' => 'name',         'label' => __('actions.name'),         'type' => 'text', 'required' => true],
            ['key' => 'abbreviation', 'label' => __('actions.abbreviation'), 'type' => 'text', 'required' => false, 'maxlength' => 10],
        ];
    }

    public function store(CoverTestTypeRequest $request)
    {
        return $this->genericStore($request);
    }

    public function update(CoverTestTypeRequest $request, string $id)
    {
        return $this->genericUpdate($request, $id);
    }
}
