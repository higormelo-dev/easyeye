<?php

declare(strict_types=1);

namespace App\Http\Controllers\Setting;

use App\Http\Requests\CovenantRequest;
use App\Http\Resources\CovenantResource;
use App\Services\CovenantService;

class CovenantsController extends BaseSettingController
{
    public function __construct(CovenantService $service)
    {
        $this->titleController = __('actions.sidemenu.covenants');
        $this->service         = $service;
        $this->resourceClass   = CovenantResource::class;
        $this->routePrefix     = 'panel.setting.covenants';
        $this->viewSlot        = 'covenants';
        $this->crudFields      = ['name' => '', 'color' => '#3699ff', 'table' => false, 'active' => true];
    }

    protected function getColumns(): array
    {
        return [
            ['key' => 'code',  'label' => __('actions.code'),  'type' => 'code'],
            ['key' => 'name',  'label' => __('actions.name'),  'type' => 'text', 'sortable' => true],
            ['key' => 'color', 'label' => __('actions.color'), 'type' => 'color'],
            ['key' => 'table', 'label' => __('actions.table'), 'type' => 'yesno'],
        ];
    }

    protected function getFormFields(): array
    {
        return [
            ['key' => 'name',  'label' => __('actions.name'),  'type' => 'text',     'required' => true],
            ['key' => 'color', 'label' => __('actions.color'), 'type' => 'color',    'required' => false],
            ['key' => 'table', 'label' => __('actions.table'), 'type' => 'checkbox', 'hint' => __('actions.covenant_table_hint')],
        ];
    }

    public function store(CovenantRequest $request)
    {
        return $this->genericStore($request);
    }

    public function update(CovenantRequest $request, string $id)
    {
        return $this->genericUpdate($request, $id);
    }
}
