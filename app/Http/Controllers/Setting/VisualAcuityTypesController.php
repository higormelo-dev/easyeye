<?php

declare(strict_types=1);

namespace App\Http\Controllers\Setting;

use App\Http\Requests\VisualAcuityTypeRequest;
use App\Http\Resources\VisualAcuityTypeResource;
use App\Services\VisualAcuityTypeService;

class VisualAcuityTypesController extends BaseSettingController
{
    public function __construct(VisualAcuityTypeService $service)
    {
        $this->titleController = __('actions.sidemenu.visualacuitytypes');
        $this->service         = $service;
        $this->resourceClass   = VisualAcuityTypeResource::class;
        $this->routePrefix     = 'panel.setting.visualacuitytypes';
        $this->viewSlot        = 'visualacuitytypes';
        $this->crudFields      = ['name' => '', 'scale' => 0, 'active' => true];
    }

    protected function getColumns(): array
    {
        return [
            ['key' => 'code', 'label' => __('actions.code'), 'type' => 'code'],
            ['key' => 'name', 'label' => __('actions.name'), 'type' => 'text', 'sortable' => true],
            ['key' => 'scale', 'label' => __('actions.scale'), 'type' => 'numeric'],
        ];
    }

    protected function getFormFields(): array
    {
        return [
            ['key' => 'name', 'label' => __('actions.name'), 'type' => 'text', 'required' => true],
            ['key' => 'scale', 'label' => __('actions.scale'), 'type' => 'numeric', 'required' => true, 'min' => 0, 'step' => 0.01],
        ];
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
