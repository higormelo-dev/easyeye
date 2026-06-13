<?php

declare(strict_types=1);

namespace App\Http\Controllers\Setting;

use App\Http\Requests\ColorVisionTypeRequest;
use App\Http\Resources\ColorVisionTypeResource;
use App\Services\ColorVisionTypeService;

class ColorVisionTypesController extends BaseSettingController
{
    public function __construct(ColorVisionTypeService $service)
    {
        $this->titleController = __('actions.sidemenu.colorvisiontypes');
        $this->service         = $service;
        $this->resourceClass   = ColorVisionTypeResource::class;
        $this->routePrefix     = 'panel.setting.colorvisiontypes';
        $this->viewSlot        = 'colorvisiontypes';
    }

    public function store(ColorVisionTypeRequest $request)
    {
        return $this->genericStore($request);
    }

    public function update(ColorVisionTypeRequest $request, string $id)
    {
        return $this->genericUpdate($request, $id);
    }
}
