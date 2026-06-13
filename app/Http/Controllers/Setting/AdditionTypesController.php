<?php

declare(strict_types=1);

namespace App\Http\Controllers\Setting;

use App\Http\Requests\AdditionTypeRequest;
use App\Http\Resources\AdditionTypeResource;
use App\Services\AdditionTypeService;

class AdditionTypesController extends BaseSettingController
{
    public function __construct(AdditionTypeService $service)
    {
        $this->titleController = __('actions.sidemenu.additiontypes');
        $this->service         = $service;
        $this->resourceClass   = AdditionTypeResource::class;
        $this->routePrefix     = 'panel.setting.additiontypes';
        $this->viewSlot        = 'additiontypes';
    }

    public function store(AdditionTypeRequest $request)
    {
        return $this->genericStore($request);
    }

    public function update(AdditionTypeRequest $request, string $id)
    {
        return $this->genericUpdate($request, $id);
    }
}
