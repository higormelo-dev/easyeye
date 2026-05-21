<?php

declare(strict_types=1);

namespace App\Http\Controllers\Setting;

use App\Http\Requests\VisitTypeRequest;
use App\Http\Resources\VisitTypeResource;
use App\Services\VisitTypeService;

class VisitTypesController extends BaseSettingController
{
    public function __construct(VisitTypeService $service)
    {
        $this->titleController = __("actions.sidemenu.visittypes");
        $this->service         = $service;
        $this->resourceClass   = VisitTypeResource::class;
        $this->routePrefix     = "panel.setting.visittypes";
        $this->viewSlot        = "visittypes";
    }

    public function store(VisitTypeRequest $request)
    {
        return $this->genericStore($request);
    }

    public function update(VisitTypeRequest $request, string $id)
    {
        return $this->genericUpdate($request, $id);
    }
}
