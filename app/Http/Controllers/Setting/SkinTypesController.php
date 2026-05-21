<?php

declare(strict_types=1);

namespace App\Http\Controllers\Setting;

use App\Http\Requests\SkinTypeRequest;
use App\Http\Resources\SkinTypeResource;
use App\Services\SkinTypeService;

class SkinTypesController extends BaseSettingController
{
    public function __construct(SkinTypeService $service)
    {
        $this->titleController = __('actions.sidemenu.skintypes');
        $this->service         = $service;
        $this->resourceClass   = SkinTypeResource::class;
        $this->routePrefix     = 'panel.setting.skintypes';
        $this->viewSlot        = 'skintypes';
    }

    public function store(SkinTypeRequest $request)
    {
        return $this->genericStore($request);
    }

    public function update(SkinTypeRequest $request, string $id)
    {
        return $this->genericUpdate($request, $id);
    }
}
