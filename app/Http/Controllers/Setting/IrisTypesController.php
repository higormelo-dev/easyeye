<?php

declare(strict_types=1);

namespace App\Http\Controllers\Setting;

use App\Http\Requests\IrisTypeRequest;
use App\Http\Resources\IrisTypeResource;
use App\Services\IrisTypeService;

class IrisTypesController extends BaseSettingController
{
    public function __construct(IrisTypeService $service)
    {
        $this->titleController = __('actions.sidemenu.iristypes');
        $this->service         = $service;
        $this->resourceClass   = IrisTypeResource::class;
        $this->routePrefix     = 'panel.setting.iristypes';
        $this->viewSlot        = 'iristypes';
    }

    public function store(IrisTypeRequest $request)
    {
        return $this->genericStore($request);
    }

    public function update(IrisTypeRequest $request, string $id)
    {
        return $this->genericUpdate($request, $id);
    }
}
