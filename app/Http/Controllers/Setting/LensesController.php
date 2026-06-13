<?php

declare(strict_types=1);

namespace App\Http\Controllers\Setting;

use App\Http\Requests\LenseRequest;
use App\Http\Resources\LenseResource;
use App\Services\LenseService;

class LensesController extends BaseSettingController
{
    public function __construct(LenseService $service)
    {
        $this->titleController = __('actions.sidemenu.lenses');
        $this->service         = $service;
        $this->resourceClass   = LenseResource::class;
        $this->routePrefix     = 'panel.setting.lenses';
        $this->viewSlot        = 'lenses';
        $this->crudFields      = ['name' => '', 'away' => false, 'near' => false, 'active' => true];
    }

    protected function getColumns(): array
    {
        return [
            ['key' => 'code', 'label' => __('actions.code'), 'type' => 'code'],
            ['key' => 'name', 'label' => __('actions.name'), 'type' => 'text', 'sortable' => true],
            ['key' => 'away', 'label' => __('actions.away'), 'type' => 'yesno'],
            ['key' => 'near', 'label' => __('actions.near'), 'type' => 'yesno'],
        ];
    }

    protected function getFormFields(): array
    {
        return [
            ['key' => 'name', 'label' => __('actions.name'), 'type' => 'text', 'required' => true],
            ['key' => 'away', 'label' => __('actions.away'), 'type' => 'checkbox'],
            ['key' => 'near', 'label' => __('actions.near'), 'type' => 'checkbox'],
        ];
    }

    public function store(LenseRequest $request)
    {
        return $this->genericStore($request);
    }

    public function update(LenseRequest $request, string $id)
    {
        return $this->genericUpdate($request, $id);
    }
}
