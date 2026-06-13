<?php

declare(strict_types=1);

namespace App\Http\Controllers\Setting;

use App\Http\Requests\VisitTypeRequest;
use App\Http\Resources\VisitTypeResource;
use App\Models\Procedure;
use App\Services\VisitTypeService;
use Illuminate\Database\Eloquent\Model;

class VisitTypesController extends BaseSettingController
{
    public function __construct(VisitTypeService $service)
    {
        $this->titleController = __('actions.sidemenu.visittypes');
        $this->service         = $service;
        $this->resourceClass   = VisitTypeResource::class;
        $this->routePrefix     = 'panel.setting.visittypes';
        $this->viewSlot        = 'visittypes';
        $this->crudFields      = ['name' => '', 'procedure_id' => null, 'active' => true];
    }

    protected function getColumns(): array
    {
        return [
            ['key' => 'code', 'label' => __('actions.code'), 'type' => 'code'],
            ['key' => 'name', 'label' => __('actions.name'), 'type' => 'text', 'sortable' => true],
            ['key' => 'procedure_name', 'label' => __('schedules.cash_procedure'), 'type' => 'text'],
        ];
    }

    protected function getFormFields(): array
    {
        return [
            ['key' => 'name', 'label' => __('actions.name'), 'type' => 'text', 'required' => true],
            ['key' => 'procedure_id', 'label' => __('actions.default_procedure'), 'type' => 'select', 'required' => false, 'options' => $this->procedureOptions()],
        ];
    }

    /** Adiciona o nome do procedimento padrão à listagem. */
    protected function serializeRecord(Model $record): array
    {
        $data                   = parent::serializeRecord($record);
        $data['procedure_name'] = $record->procedure?->name ?? '—';

        return $data;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function procedureOptions(): array
    {
        $entityId = session('selected_entity_id');

        return Procedure::query()
            ->where(fn ($q) => $q->where('entity_id', $entityId)->orWhereNull('entity_id'))
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Procedure $p) => ['value' => $p->id, 'label' => $p->name])
            ->all();
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
