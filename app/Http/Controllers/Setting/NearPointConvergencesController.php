<?php

declare(strict_types=1);

namespace App\Http\Controllers\Setting;

use App\Http\Requests\NearPointConvergenceRequest;
use App\Http\Resources\NearPointConvergenceResource;
use App\Services\NearPointConvergenceService;

class NearPointConvergencesController extends BaseSettingController
{
    public function __construct(NearPointConvergenceService $service)
    {
        $this->titleController = __('actions.sidemenu.nearpointconvergences');
        $this->service         = $service;
        $this->resourceClass   = NearPointConvergenceResource::class;
        $this->routePrefix     = 'panel.setting.nearpointconvergences';
        $this->viewSlot        = 'nearpointconvergences';
    }

    public function store(NearPointConvergenceRequest $request)
    {
        return $this->genericStore($request);
    }

    public function update(NearPointConvergenceRequest $request, string $id)
    {
        return $this->genericUpdate($request, $id);
    }
}
