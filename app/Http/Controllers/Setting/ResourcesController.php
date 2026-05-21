<?php

declare(strict_types=1);

namespace App\Http\Controllers\Setting;

use App\Http\Requests\ClinicResourceRequest;
use App\Http\Resources\ClinicResourceResource;
use App\Services\ClinicResourceService;

class ResourcesController extends BaseSettingController
{
    public function __construct(ClinicResourceService $service)
    {
        $this->titleController = __('actions.sidemenu.resources');
        $this->service         = $service;
        $this->resourceClass   = ClinicResourceResource::class;
        $this->routePrefix     = 'panel.setting.resources';
        $this->viewSlot        = 'clinic-resources';
        $this->crudFields      = [
            'name'        => '',
            'type'        => 'room',
            'description' => '',
            'active'      => true,
        ];
    }

    protected function getColumns(): array
    {
        return [
            ['key' => 'code', 'label' => __('actions.code'), 'type' => 'code'],
            ['key' => 'name', 'label' => __('actions.name'), 'type' => 'text', 'sortable' => true],
            ['key' => 'type', 'label' => __('actions.type'), 'type' => 'text'],
        ];
    }

    protected function getFormFields(): array
    {
        return [
            ['key' => 'name',        'label' => __('actions.name'),        'type' => 'text',    'required' => true],
            ['key' => 'type',        'label' => __('actions.type'),        'type' => 'select',  'required' => true, 'options' => [
                ['value' => 'room',      'label' => 'Sala'],
                ['value' => 'equipment', 'label' => 'Equipamento'],
                ['value' => 'other',     'label' => 'Outro'],
            ]],
            ['key' => 'description', 'label' => __('actions.description'), 'type' => 'text'],
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
